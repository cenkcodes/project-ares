<?php

namespace App\Services\Seo;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class IncrementalVideoMembershipWorker
{
    public const ENGINE_VERSION = 'video-membership-v2';

    public const ENGINE_STAGE = 'video-membership';

    public const SEO_ENRICHMENT_VERSION = 'automatic-video-seo-v1';

    private const CATEGORY_CHANGE_MEMBERSHIP = 1;

    private const TERM_CHANGE_ADDED = 1;

    private const TERM_CHANGE_REMOVED = 2;

    public function __construct(
        private readonly VideoSeoContentBuilder $videoSeoContentBuilder
    ) {
    }

    /**
     * Claim and process one queue batch.
     *
     * The queue is provider-independent. Any process that changes videos or
     * video_source_terms is already captured by the database triggers created
     * by Semantic Intelligence V2.
     */
    public function processBatch(
        int $limit = 100,
        int $leaseSeconds = 900,
        int $settleSeconds = 0
    ): array {
        $limit = max(
            1,
            min(
                $limit,
                1000
            )
        );

        $leaseSeconds = max(
            60,
            min(
                $leaseSeconds,
                86400
            )
        );

        $settleSeconds = max(
            0,
            min(
                $settleSeconds,
                3600
            )
        );

        $runUuid =
            (string) Str::uuid();

        $processingToken =
            (string) Str::uuid();

        $startedAt = now();

        $runId =
            DB::table(
                'seo_semantic_runs'
            )->insertGetId(
                [
                    'run_uuid' =>
                        $runUuid,

                    'engine_stage' =>
                        self::ENGINE_STAGE,

                    'run_mode' =>
                        'incremental',

                    'status' =>
                        'running',

                    'engine_version' =>
                        self::ENGINE_VERSION,

                    'claimed_count' =>
                        0,

                    'processed_count' =>
                        0,

                    'changed_count' =>
                        0,

                    'skipped_count' =>
                        0,

                    'failed_count' =>
                        0,

                    'started_at' =>
                        $startedAt,

                    'heartbeat_at' =>
                        $startedAt,

                    'completed_at' =>
                        null,

                    'last_error' =>
                        null,

                    'metadata' =>
                        json_encode(
                            [
                                'processing_token' =>
                                    $processingToken,

                                'limit' =>
                                    $limit,

                                'lease_seconds' =>
                                    $leaseSeconds,

                                'settle_seconds' =>
                                    $settleSeconds,

                                'seo_enrichment_version' =>
                                    self::SEO_ENRICHMENT_VERSION,
                            ],
                            JSON_UNESCAPED_SLASHES
                        ),

                    'created_at' =>
                        $startedAt,

                    'updated_at' =>
                        $startedAt,
                ]
            );

        DB::table(
            'seo_semantic_sync_states'
        )
            ->where(
                'engine_key',
                'semantic-intelligence'
            )
            ->update(
                [
                    'last_incremental_started_at' =>
                        $startedAt,

                    'updated_at' =>
                        $startedAt,
                ]
            );

        try {
            $claimedRows =
                $this->claimBatch(
                    $processingToken,
                    $limit,
                    $leaseSeconds,
                    $settleSeconds
                );

            $claimedCount =
                count(
                    $claimedRows
                );

            DB::table(
                'seo_semantic_runs'
            )
                ->where(
                    'id',
                    $runId
                )
                ->update(
                    [
                        'claimed_count' =>
                            $claimedCount,

                        'heartbeat_at' =>
                            now(),

                        'updated_at' =>
                            now(),
                    ]
                );

            if (
                $claimedCount === 0
            ) {
                $completedAt =
                    now();

                DB::table(
                    'seo_semantic_runs'
                )
                    ->where(
                        'id',
                        $runId
                    )
                    ->update(
                        [
                            'status' =>
                                'completed',

                            'completed_at' =>
                                $completedAt,

                            'heartbeat_at' =>
                                $completedAt,

                            'updated_at' =>
                                $completedAt,
                        ]
                    );

                DB::table(
                    'seo_semantic_sync_states'
                )
                    ->where(
                        'engine_key',
                        'semantic-intelligence'
                    )
                    ->update(
                        [
                            'last_incremental_completed_at' =>
                                $completedAt,

                            'last_error' =>
                                null,

                            'updated_at' =>
                                $completedAt,
                        ]
                    );

                return [
                    'run_uuid' =>
                        $runUuid,

                    'claimed_count' =>
                        0,

                    'processed_count' =>
                        0,

                    'changed_count' =>
                        0,

                    'skipped_count' =>
                        0,

                    'failed_count' =>
                        0,

                    'requeued_count' =>
                        0,

                    'dirty_category_count' =>
                        0,

                    'dirty_term_count' =>
                        0,

                    'seo_candidate_count' =>
                        0,

                    'seo_ready_count' =>
                        0,

                    'seo_selected' =>
                        0,

                    'seo_processed' =>
                        0,

                    'seo_created' =>
                        0,

                    'seo_updated' =>
                        0,

                    'seo_skipped_unchanged' =>
                        0,

                    'seo_quality_hold' =>
                        0,

                    'seo_failed' =>
                        0,

                    'seo_fatal_error' =>
                        false,

                    'seo_fatal_error_type' =>
                        '',

                    'seo_fatal_error_message' =>
                        '',

                    'seo_deferred' =>
                        false,
                ];
            }

            $processedCount = 0;

            $changedCount = 0;

            $skippedCount = 0;

            $failedCount = 0;

            $requeuedCount = 0;

            $dirtyCategoryCount = 0;

            $dirtyTermCount = 0;

            $lastError = null;

            $seoCandidateIds = [];

            foreach (
                $claimedRows
                as $claimedRow
            ) {
                try {
                    $result =
                        $this->processOne(
                            (int) $claimedRow->video_id,
                            (int) $claimedRow->dirty_revision,
                            $processingToken
                        );

                    $processedCount++;

                    if (
                        $result['changed']
                    ) {
                        $changedCount++;
                    } else {
                        $skippedCount++;
                    }

                    if (
                        $result['requeued']
                    ) {
                        $requeuedCount++;
                    } else {
                        $seoCandidateIds[] =
                            (int) $claimedRow->video_id;
                    }

                    $dirtyCategoryCount +=
                        $result[
                            'dirty_category_count'
                        ];

                    $dirtyTermCount +=
                        $result[
                            'dirty_term_count'
                        ];
                } catch (Throwable $exception) {
                    $failedCount++;

                    $lastError =
                        $exception->getMessage();

                    $this->releaseFailedWork(
                        (int) $claimedRow->video_id,
                        (int) $claimedRow->dirty_revision,
                        $processingToken,
                        $exception
                    );
                }

                DB::table(
                    'seo_semantic_runs'
                )
                    ->where(
                        'id',
                        $runId
                    )
                    ->update(
                        [
                            'processed_count' =>
                                $processedCount,

                            'changed_count' =>
                                $changedCount,

                            'skipped_count' =>
                                $skippedCount,

                            'failed_count' =>
                                $failedCount,

                            'heartbeat_at' =>
                                now(),

                            'last_error' =>
                                $lastError,

                            'updated_at' =>
                                now(),
                        ]
                    );
            }

            $seoResult =
                $this->enrichVideoSeo(
                    $seoCandidateIds
                );

            $seoTechnicalFailure =
                !$seoResult['deferred']
                && (
                    $seoResult['fatal_error']
                    || $seoResult['failed'] > 0
                );

            if (
                $seoTechnicalFailure
                && $lastError === null
            ) {
                $lastError =
                    $seoResult['fatal_error']
                        ? $seoResult['fatal_error_message']
                        : (string) (
                            reset(
                                $seoResult['errors']
                            )
                            ?: 'Automatic Video SEO enrichment failed.'
                        );
            }

            $completedAt =
                now();

            $runStatus =
                $failedCount > 0
                || $seoTechnicalFailure
                    ? 'completed_with_errors'
                    : 'completed';

            DB::table(
                'seo_semantic_runs'
            )
                ->where(
                    'id',
                    $runId
                )
                ->update(
                    [
                        'status' =>
                            $runStatus,

                        'processed_count' =>
                            $processedCount,

                        'changed_count' =>
                            $changedCount,

                        'skipped_count' =>
                            $skippedCount,

                        'failed_count' =>
                            $failedCount,

                        'heartbeat_at' =>
                            $completedAt,

                        'completed_at' =>
                            $completedAt,

                        'last_error' =>
                            $lastError,

                        'metadata' =>
                            json_encode(
                                [
                                    'processing_token' =>
                                        $processingToken,

                                    'limit' =>
                                        $limit,

                                    'lease_seconds' =>
                                        $leaseSeconds,

                                    'settle_seconds' =>
                                        $settleSeconds,

                                    'requeued_count' =>
                                        $requeuedCount,

                                    'dirty_category_count' =>
                                        $dirtyCategoryCount,

                                    'dirty_term_count' =>
                                        $dirtyTermCount,

                                    'seo_enrichment_version' =>
                                        self::SEO_ENRICHMENT_VERSION,

                                    'seo_candidate_count' =>
                                        $seoResult['candidate_count'],

                                    'seo_ready_count' =>
                                        $seoResult['ready_count'],

                                    'seo_selected' =>
                                        $seoResult['selected'],

                                    'seo_processed' =>
                                        $seoResult['processed'],

                                    'seo_created' =>
                                        $seoResult['created'],

                                    'seo_updated' =>
                                        $seoResult['updated'],

                                    'seo_skipped_unchanged' =>
                                        $seoResult['skipped_unchanged'],

                                    'seo_quality_hold' =>
                                        $seoResult['quality_hold'],

                                    'seo_failed' =>
                                        $seoResult['failed'],

                                    'seo_fatal_error' =>
                                        $seoResult['fatal_error'],

                                    'seo_fatal_error_type' =>
                                        $seoResult['fatal_error_type'],

                                    'seo_deferred' =>
                                        $seoResult['deferred'],
                                ],
                                JSON_UNESCAPED_SLASHES
                            ),

                        'updated_at' =>
                            $completedAt,
                    ]
                );

            DB::table(
                'seo_semantic_sync_states'
            )
                ->where(
                    'engine_key',
                    'semantic-intelligence'
                )
                ->update(
                    [
                        'last_incremental_completed_at' =>
                            $completedAt,

                        'last_error' =>
                            $lastError,

                        'updated_at' =>
                            $completedAt,
                    ]
                );

            return [
                'run_uuid' =>
                    $runUuid,

                'claimed_count' =>
                    $claimedCount,

                'processed_count' =>
                    $processedCount,

                'changed_count' =>
                    $changedCount,

                'skipped_count' =>
                    $skippedCount,

                'failed_count' =>
                    $failedCount,

                'requeued_count' =>
                    $requeuedCount,

                'dirty_category_count' =>
                    $dirtyCategoryCount,

                'dirty_term_count' =>
                    $dirtyTermCount,

                'seo_candidate_count' =>
                    $seoResult['candidate_count'],

                'seo_ready_count' =>
                    $seoResult['ready_count'],

                'seo_selected' =>
                    $seoResult['selected'],

                'seo_processed' =>
                    $seoResult['processed'],

                'seo_created' =>
                    $seoResult['created'],

                'seo_updated' =>
                    $seoResult['updated'],

                'seo_skipped_unchanged' =>
                    $seoResult['skipped_unchanged'],

                'seo_quality_hold' =>
                    $seoResult['quality_hold'],

                'seo_failed' =>
                    $seoResult['failed'],

                'seo_fatal_error' =>
                    $seoResult['fatal_error'],

                'seo_fatal_error_type' =>
                    $seoResult['fatal_error_type'],

                'seo_fatal_error_message' =>
                    $seoResult['fatal_error_message'],

                'seo_deferred' =>
                    $seoResult['deferred'],
            ];
        } catch (Throwable $exception) {
            $completedAt =
                now();

            DB::table(
                'seo_semantic_runs'
            )
                ->where(
                    'id',
                    $runId
                )
                ->update(
                    [
                        'status' =>
                            'failed',

                        'heartbeat_at' =>
                            $completedAt,

                        'completed_at' =>
                            $completedAt,

                        'last_error' =>
                            Str::limit(
                                $exception->getMessage(),
                                4000,
                                ''
                            ),

                        'updated_at' =>
                            $completedAt,
                    ]
                );

            DB::table(
                'seo_semantic_sync_states'
            )
                ->where(
                    'engine_key',
                    'semantic-intelligence'
                )
                ->update(
                    [
                        'last_incremental_completed_at' =>
                            $completedAt,

                        'last_error' =>
                            Str::limit(
                                $exception->getMessage(),
                                4000,
                                ''
                            ),

                        'updated_at' =>
                            $completedAt,
                    ]
                );

            throw $exception;
        }
    }

    /**
     * Enrich only videos whose membership revision was acknowledged and stayed
     * quiet long enough for canonical source-term writes to settle.
     *
     * A video that became dirty again after acknowledgement is deliberately
     * skipped here. The newer queue revision must refresh memberships first.
     *
     * Deleted videos are also skipped; their persisted SEO row is removed by
     * the video_seo_contents foreign-key cascade.
     */
    private function enrichVideoSeo(
        array $candidateIds
    ): array {
        $candidateIds =
            array_values(
                array_unique(
                    array_map(
                        'intval',
                        $candidateIds
                    )
                )
            );

        sort(
            $candidateIds,
            SORT_NUMERIC
        );

        $result = [
            'candidate_count' =>
                count(
                    $candidateIds
                ),

            'ready_count' =>
                0,

            'selected' =>
                0,

            'processed' =>
                0,

            'created' =>
                0,

            'updated' =>
                0,

            'skipped_unchanged' =>
                0,

            'quality_hold' =>
                0,

            'failed' =>
                0,

            'errors' =>
                [],

            'fatal_error' =>
                false,

            'fatal_error_type' =>
                '',

            'fatal_error_message' =>
                '',

            'deferred' =>
                false,
        ];

        if (
            $candidateIds === []
        ) {
            return $result;
        }

        try {
            $dirtyAgainIds =
                DB::table(
                    'seo_catalog_dirty_videos'
                )
                    ->whereIn(
                        'video_id',
                        $candidateIds
                    )
                    ->pluck(
                        'video_id'
                    )
                    ->map(
                        fn ($id): int =>
                            (int) $id
                    )
                    ->all();

            $readyIds =
                array_values(
                    array_diff(
                        $candidateIds,
                        $dirtyAgainIds
                    )
                );

            if (
                $readyIds === []
            ) {
                return $result;
            }

            $existingIds =
                DB::table(
                    'videos'
                )
                    ->whereIn(
                        'id',
                        $readyIds
                    )
                    ->orderBy(
                        'id'
                    )
                    ->pluck(
                        'id'
                    )
                    ->map(
                        fn ($id): int =>
                            (int) $id
                    )
                    ->all();

            $result['ready_count'] =
                count(
                    $existingIds
                );

            if (
                $existingIds === []
            ) {
                return $result;
            }

            $videos =
                collect(
                    $existingIds
                )
                    ->map(
                        fn (int $id): object =>
                            (object) [
                                'id' =>
                                    $id,
                            ]
                    );

            $builderResult =
                $this
                    ->videoSeoContentBuilder
                    ->buildBatch(
                        $videos
                    );

            foreach (
                [
                    'selected',
                    'processed',
                    'created',
                    'updated',
                    'skipped_unchanged',
                    'quality_hold',
                    'failed',
                    'errors',
                    'fatal_error',
                    'fatal_error_type',
                    'fatal_error_message',
                ]
                as $key
            ) {
                $result[$key] =
                    $builderResult[$key];
            }

            $result['deferred'] =
                $result['fatal_error']
                && $result['fatal_error_type']
                    === 'lock_unavailable';

            if (
                $result['deferred']
            ) {
                $this->requeueSeoCandidates(
                    $existingIds
                );
            }

            return $result;
        } catch (Throwable $exception) {
            $result['fatal_error'] =
                true;

            $result['fatal_error_type'] =
                'service_error';

            $result['fatal_error_message'] =
                $exception->getMessage();

            return $result;
        }
    }

    /**
     * Put acknowledged videos back on the canonical dirty queue when the
     * Video SEO global advisory lock is temporarily unavailable.
     *
     * The database function preserves dirty_revision/no-lost-update semantics
     * if another canonical change arrives concurrently. A zero change mask is
     * intentional: memberships were already reconciled; the next bounded run
     * simply revalidates the current snapshot before retrying SEO enrichment.
     */
    private function requeueSeoCandidates(
        array $videoIds
    ): void {
        $videoIds =
            array_values(
                array_unique(
                    array_filter(
                        array_map(
                            'intval',
                            $videoIds
                        ),
                        fn (int $id): bool =>
                            $id > 0
                    )
                )
            );

        if (
            $videoIds === []
        ) {
            return;
        }

        foreach (
            array_chunk(
                $videoIds,
                500
            )
            as $chunk
        ) {
            $valuePlaceholders =
                implode(
                    ', ',
                    array_fill(
                        0,
                        count(
                            $chunk
                        ),
                        '(?)'
                    )
                );

            DB::statement(
                'SELECT xurvexa_seo_upsert_dirty(candidate.video_id, 0) '
                . 'FROM (VALUES '
                . $valuePlaceholders
                . ') AS candidate(video_id)',
                $chunk
            );
        }
    }

    /**
     * Claim queue rows using PostgreSQL SKIP LOCKED.
     *
     * Multiple future workers can call this safely at the same time.
     */
    private function claimBatch(
        string $processingToken,
        int $limit,
        int $leaseSeconds,
        int $settleSeconds
    ): array {
        $sql =
            <<<'SQL'
WITH candidates AS (
    SELECT
        video_id
    FROM seo_catalog_dirty_videos
    WHERE
        available_at <= CURRENT_TIMESTAMP
        AND last_dirty_at <=
            CURRENT_TIMESTAMP -
            (? * INTERVAL '1 second')
        AND (
            processing_started_at IS NULL
            OR processing_started_at <
                CURRENT_TIMESTAMP -
                (? * INTERVAL '1 second')
        )
    ORDER BY
        available_at ASC,
        video_id ASC
    FOR UPDATE SKIP LOCKED
    LIMIT %d
)
UPDATE seo_catalog_dirty_videos AS queue
SET
    processing_started_at =
        CURRENT_TIMESTAMP,

    processing_token =
        CAST(? AS uuid),

    attempts =
        queue.attempts + 1,

    updated_at =
        CURRENT_TIMESTAMP

FROM candidates

WHERE
    queue.video_id =
        candidates.video_id

RETURNING
    queue.video_id,
    queue.change_mask,
    queue.dirty_revision
SQL;

        $sql =
            sprintf(
                $sql,
                $limit
            );

        return DB::select(
            $sql,
            [
                $settleSeconds,
                $leaseSeconds,
                $processingToken,
            ]
        );
    }

    /**
     * Process one claimed video.
     *
     * Snapshot replacement, downstream dirty propagation and queue
     * acknowledgement are committed atomically.
     */
    private function processOne(
        int $videoId,
        int $claimedRevision,
        string $processingToken
    ): array {
        return DB::transaction(
            function () use (
                $videoId,
                $claimedRevision,
                $processingToken
            ): array {
                $oldCategoryState =
                    $this->loadStoredCategoryState(
                        $videoId
                    );

                $oldTermState =
                    $this->loadStoredTermState(
                        $videoId
                    );

                $newCategoryState =
                    $this->buildCurrentCategoryState(
                        $videoId
                    );

                $newTermState =
                    $this->buildCurrentTermState(
                        $videoId
                    );

                $categoryChanged =
                    $this->encodeState(
                        $oldCategoryState
                    )
                    !==
                    $this->encodeState(
                        $newCategoryState
                    );

                $termChanged =
                    $this->encodeState(
                        $oldTermState
                    )
                    !==
                    $this->encodeState(
                        $newTermState
                    );

                $dirtyCategoryCount = 0;

                $dirtyTermCount = 0;

                if (
                    $categoryChanged
                ) {
                    $affectedCategoryIds =
                        array_values(
                            array_unique(
                                array_merge(
                                    array_keys(
                                        $oldCategoryState
                                    ),
                                    array_keys(
                                        $newCategoryState
                                    )
                                )
                            )
                        );

                    sort(
                        $affectedCategoryIds,
                        SORT_NUMERIC
                    );

                    $this->replaceCategorySnapshot(
                        $videoId,
                        $newCategoryState
                    );

                    foreach (
                        $affectedCategoryIds
                        as $categoryId
                    ) {
                        $this->markCategoryDirty(
                            (int) $categoryId,
                            self::CATEGORY_CHANGE_MEMBERSHIP
                        );

                        $dirtyCategoryCount++;
                    }
                }

                if (
                    $termChanged
                ) {
                    $oldKeys =
                        array_keys(
                            $oldTermState
                        );

                    $newKeys =
                        array_keys(
                            $newTermState
                        );

                    $removedKeys =
                        array_values(
                            array_diff(
                                $oldKeys,
                                $newKeys
                            )
                        );

                    $addedKeys =
                        array_values(
                            array_diff(
                                $newKeys,
                                $oldKeys
                            )
                        );

                    $this->replaceTermSnapshot(
                        $videoId,
                        $newTermState
                    );

                    foreach (
                        $removedKeys
                        as $identityKey
                    ) {
                        $term =
                            $oldTermState[
                                $identityKey
                            ];

                        $this->markTermDirty(
                            $term['source'],
                            $term['term_type'],
                            $term['normalized_term'],
                            self::TERM_CHANGE_REMOVED
                        );

                        $dirtyTermCount++;
                    }

                    foreach (
                        $addedKeys
                        as $identityKey
                    ) {
                        $term =
                            $newTermState[
                                $identityKey
                            ];

                        $this->markTermDirty(
                            $term['source'],
                            $term['term_type'],
                            $term['normalized_term'],
                            self::TERM_CHANGE_ADDED
                        );

                        $dirtyTermCount++;
                    }
                }

                $acknowledged =
                    $this->acknowledgeWork(
                        $videoId,
                        $claimedRevision,
                        $processingToken
                    );

                $requeued =
                    !$acknowledged;

                if (
                    $requeued
                ) {
                    /*
                     * A new provider/import/admin change arrived after this
                     * worker claimed the row.
                     *
                     * dirty_revision no longer matches, therefore the newer
                     * revision must remain pending.
                     */
                    $this->releaseChangedRevision(
                        $videoId,
                        $processingToken
                    );
                }

                return [
                    'changed' =>
                        $categoryChanged
                        || $termChanged,

                    'requeued' =>
                        $requeued,

                    'dirty_category_count' =>
                        $dirtyCategoryCount,

                    'dirty_term_count' =>
                        $dirtyTermCount,
                ];
            },
            3
        );
    }

    /**
     * Load the previously successful category snapshot.
     */
    private function loadStoredCategoryState(
        int $videoId
    ): array {
        $rows =
            DB::table(
                'seo_video_category_memberships'
            )
                ->where(
                    'video_id',
                    $videoId
                )
                ->orderBy(
                    'category_id'
                )
                ->get(
                    [
                        'category_id',
                        'video_source',
                        'evidence_count',
                        'has_primary',
                        'has_alias',
                    ]
                );

        $state = [];

        foreach (
            $rows
            as $row
        ) {
            $categoryId =
                (int) $row->category_id;

            $state[
                $categoryId
            ] = [
                'category_id' =>
                    $categoryId,

                'video_source' =>
                    $row->video_source !== null
                        ? (string) $row->video_source
                        : null,

                'evidence_count' =>
                    (int) $row->evidence_count,

                'has_primary' =>
                    (bool) $row->has_primary,

                'has_alias' =>
                    (bool) $row->has_alias,
            ];
        }

        ksort(
            $state,
            SORT_NUMERIC
        );

        return $state;
    }

    /**
     * Build the current canonical category memberships.
     *
     * Membership rules deliberately match Xurvexa's canonical taxonomy:
     *
     * - active video only
     * - active primary category
     * - active category aliases
     * - source term must belong to the video's current provider
     * - provider-specific alias OR global wildcard alias (source = '*')
     * - exact term type
     * - exact normalized term
     *
     * Wildcard aliases are canonical cross-provider taxonomy rules. They must
     * therefore participate in the durable semantic snapshot instead of being
     * reinterpreted separately by controllers or downstream SEO engines.
     */
    private function buildCurrentCategoryState(
        int $videoId
    ): array {
        $video =
            DB::table(
                'videos'
            )
                ->where(
                    'id',
                    $videoId
                )
                ->first(
                    [
                        'id',
                        'video_source',
                        'category_id',
                        'is_active',
                    ]
                );

        if (
            $video === null
            || !(bool) $video->is_active
        ) {
            return [];
        }

        $state = [];

        $videoSource =
            $video->video_source !== null
                ? trim(
                    (string) $video->video_source
                )
                : null;

        if (
            $videoSource === ''
        ) {
            $videoSource = null;
        }

        if (
            $video->category_id !== null
        ) {
            $primaryCategoryId =
                (int) $video->category_id;

            $primaryIsActive =
                DB::table(
                    'categories'
                )
                    ->where(
                        'id',
                        $primaryCategoryId
                    )
                    ->where(
                        'is_active',
                        true
                    )
                    ->exists();

            if (
                $primaryIsActive
            ) {
                $state[
                    $primaryCategoryId
                ] = [
                    'category_id' =>
                        $primaryCategoryId,

                    'video_source' =>
                        $videoSource,

                    'evidence_count' =>
                        1,

                    'has_primary' =>
                        true,

                    'has_alias' =>
                        false,
                ];
            }
        }

        if (
            $videoSource !== null
        ) {
            $aliasRows =
                DB::table(
                    'video_source_terms AS vst'
                )
                    ->join(
                        'category_aliases AS ca',
                        function ($join): void {
                            $join
                                ->on(
                                    'ca.alias_type',
                                    '=',
                                    'vst.term_type'
                                )
                                ->on(
                                    'ca.normalized_alias',
                                    '=',
                                    'vst.normalized_term'
                                );
                        }
                    )
                    ->join(
                        'categories AS c',
                        'c.id',
                        '=',
                        'ca.category_id'
                    )
                    ->where(
                        'vst.video_id',
                        $videoId
                    )
                    ->where(
                        'vst.source',
                        $videoSource
                    )
                    ->where(
                        'ca.is_active',
                        true
                    )
                    ->where(
                        'c.is_active',
                        true
                    )
                    ->where(
                        function ($query) use (
                            $videoSource
                        ): void {
                            $query
                                ->where(
                                    'ca.source',
                                    $videoSource
                                )
                                ->orWhere(
                                    'ca.source',
                                    '*'
                                );
                        }
                    )
                    ->groupBy(
                        'ca.category_id'
                    )
                    ->orderBy(
                        'ca.category_id'
                    )
                    ->selectRaw(
                        <<<'SQL'
ca.category_id,
COUNT(DISTINCT vst.normalized_term) AS alias_evidence
SQL
                    )
                    ->get();

            foreach (
                $aliasRows
                as $aliasRow
            ) {
                $categoryId =
                    (int) $aliasRow->category_id;

                $aliasEvidence =
                    max(
                        1,
                        (int) $aliasRow->alias_evidence
                    );

                if (
                    isset(
                        $state[
                            $categoryId
                        ]
                    )
                ) {
                    $state[
                        $categoryId
                    ]['has_alias'] =
                        true;

                    $state[
                        $categoryId
                    ]['evidence_count'] =
                        $aliasEvidence + 1;
                } else {
                    $state[
                        $categoryId
                    ] = [
                        'category_id' =>
                            $categoryId,

                        'video_source' =>
                            $videoSource,

                        'evidence_count' =>
                            $aliasEvidence,

                        'has_primary' =>
                            false,

                        'has_alias' =>
                            true,
                    ];
                }
            }
        }

        ksort(
            $state,
            SORT_NUMERIC
        );

        return $state;
    }

    /**
     * Load the last successfully processed raw-term snapshot.
     */
    private function loadStoredTermState(
        int $videoId
    ): array {
        $rows =
            DB::table(
                'seo_video_term_memberships'
            )
                ->where(
                    'video_id',
                    $videoId
                )
                ->orderBy(
                    'source'
                )
                ->orderBy(
                    'term_type'
                )
                ->orderBy(
                    'normalized_term'
                )
                ->get(
                    [
                        'source',
                        'term_type',
                        'normalized_term',
                    ]
                );

        $state = [];

        foreach (
            $rows
            as $row
        ) {
            $term = [
                'source' =>
                    (string) $row->source,

                'term_type' =>
                    (string) $row->term_type,

                'normalized_term' =>
                    (string) $row->normalized_term,
            ];

            $state[
                $this->termIdentityKey(
                    $term['source'],
                    $term['term_type'],
                    $term['normalized_term']
                )
            ] = $term;
        }

        ksort(
            $state,
            SORT_STRING
        );

        return $state;
    }

    /**
     * Build current raw-term semantic state from the canonical source-term
     * table.
     *
     * Inactive/deleted videos intentionally return an empty state.
     */
    private function buildCurrentTermState(
        int $videoId
    ): array {
        $videoIsActive =
            DB::table(
                'videos'
            )
                ->where(
                    'id',
                    $videoId
                )
                ->where(
                    'is_active',
                    true
                )
                ->exists();

        if (
            !$videoIsActive
        ) {
            return [];
        }

        $rows =
            DB::table(
                'video_source_terms'
            )
                ->where(
                    'video_id',
                    $videoId
                )
                ->whereNotNull(
                    'source'
                )
                ->whereNotNull(
                    'term_type'
                )
                ->whereNotNull(
                    'normalized_term'
                )
                ->where(
                    'normalized_term',
                    '<>',
                    ''
                )
                ->distinct()
                ->orderBy(
                    'source'
                )
                ->orderBy(
                    'term_type'
                )
                ->orderBy(
                    'normalized_term'
                )
                ->get(
                    [
                        'source',
                        'term_type',
                        'normalized_term',
                    ]
                );

        $state = [];

        foreach (
            $rows
            as $row
        ) {
            $source =
                (string) $row->source;

            $termType =
                (string) $row->term_type;

            $normalizedTerm =
                (string) $row->normalized_term;

            $term = [
                'source' =>
                    $source,

                'term_type' =>
                    $termType,

                'normalized_term' =>
                    $normalizedTerm,
            ];

            $state[
                $this->termIdentityKey(
                    $source,
                    $termType,
                    $normalizedTerm
                )
            ] = $term;
        }

        ksort(
            $state,
            SORT_STRING
        );

        return $state;
    }

    /**
     * Replace the durable category snapshot.
     */
    private function replaceCategorySnapshot(
        int $videoId,
        array $state
    ): void {
        DB::table(
            'seo_video_category_memberships'
        )
            ->where(
                'video_id',
                $videoId
            )
            ->delete();

        if (
            $state === []
        ) {
            return;
        }

        $timestamp =
            now();

        $rows = [];

        foreach (
            $state
            as $membership
        ) {
            $fingerprint =
                hash(
                    'sha256',
                    $this->encodeState(
                        [
                            'video_id' =>
                                $videoId,

                            'category_id' =>
                                $membership[
                                    'category_id'
                                ],

                            'video_source' =>
                                $membership[
                                    'video_source'
                                ],

                            'evidence_count' =>
                                $membership[
                                    'evidence_count'
                                ],

                            'has_primary' =>
                                $membership[
                                    'has_primary'
                                ],

                            'has_alias' =>
                                $membership[
                                    'has_alias'
                                ],
                        ]
                    )
                );

            $rows[] = [
                'video_id' =>
                    $videoId,

                'category_id' =>
                    $membership[
                        'category_id'
                    ],

                'video_source' =>
                    $membership[
                        'video_source'
                    ],

                'evidence_count' =>
                    $membership[
                        'evidence_count'
                    ],

                'has_primary' =>
                    $membership[
                        'has_primary'
                    ],

                'has_alias' =>
                    $membership[
                        'has_alias'
                    ],

                'source_fingerprint' =>
                    $fingerprint,

                'calculated_at' =>
                    $timestamp,

                'created_at' =>
                    $timestamp,

                'updated_at' =>
                    $timestamp,
            ];
        }

        DB::table(
            'seo_video_category_memberships'
        )->insert(
            $rows
        );
    }

    /**
     * Replace the durable raw-term snapshot.
     */
    private function replaceTermSnapshot(
        int $videoId,
        array $state
    ): void {
        DB::table(
            'seo_video_term_memberships'
        )
            ->where(
                'video_id',
                $videoId
            )
            ->delete();

        if (
            $state === []
        ) {
            return;
        }

        $timestamp =
            now();

        $rows = [];

        foreach (
            $state
            as $term
        ) {
            $rows[] = [
                'video_id' =>
                    $videoId,

                'source' =>
                    $term['source'],

                'term_type' =>
                    $term['term_type'],

                'normalized_term' =>
                    $term[
                        'normalized_term'
                    ],

                'calculated_at' =>
                    $timestamp,

                'created_at' =>
                    $timestamp,

                'updated_at' =>
                    $timestamp,
            ];
        }

        /*
         * Keep individual INSERT statements safely below database parameter
         * limits during unusually metadata-heavy provider imports.
         */
        foreach (
            array_chunk(
                $rows,
                500
            )
            as $chunk
        ) {
            DB::table(
                'seo_video_term_memberships'
            )->insert(
                $chunk
            );
        }
    }

    /**
     * Mark a category for downstream Relation Engine processing.
     *
     * Repeated changes merge into one queue row and increase dirty_revision.
     * We deliberately do not clear an existing processing lease here. If a
     * downstream worker is already processing revision N, the revision change
     * guarantees it cannot accidentally acknowledge revision N+1.
     */
    private function markCategoryDirty(
        int $categoryId,
        int $changeMask
    ): void {
        DB::statement(
            <<<'SQL'
INSERT INTO seo_catalog_dirty_categories (
    category_id,
    change_mask,
    dirty_revision,
    first_dirty_at,
    last_dirty_at,
    available_at,
    processing_started_at,
    processing_token,
    attempts,
    last_error,
    created_at,
    updated_at
)
VALUES (
    ?,
    ?,
    1,
    CURRENT_TIMESTAMP,
    CURRENT_TIMESTAMP,
    CURRENT_TIMESTAMP,
    NULL,
    NULL,
    0,
    NULL,
    CURRENT_TIMESTAMP,
    CURRENT_TIMESTAMP
)
ON CONFLICT (category_id)
DO UPDATE SET
    change_mask =
        seo_catalog_dirty_categories.change_mask
        |
        EXCLUDED.change_mask,

    dirty_revision =
        seo_catalog_dirty_categories.dirty_revision
        + 1,

    last_dirty_at =
        CURRENT_TIMESTAMP,

    available_at =
        LEAST(
            seo_catalog_dirty_categories.available_at,
            CURRENT_TIMESTAMP
        ),

    attempts =
        0,

    last_error =
        NULL,

    updated_at =
        CURRENT_TIMESTAMP
SQL,
            [
                $categoryId,
                $changeMask,
            ]
        );
    }

    /**
     * Mark one provider-scoped normalized term for downstream Concept/Topic
     * Intelligence processing.
     */
    private function markTermDirty(
        string $source,
        string $termType,
        string $normalizedTerm,
        int $changeMask
    ): void {
        DB::statement(
            <<<'SQL'
INSERT INTO seo_catalog_dirty_terms (
    source,
    term_type,
    normalized_term,
    change_mask,
    dirty_revision,
    first_dirty_at,
    last_dirty_at,
    available_at,
    processing_started_at,
    processing_token,
    attempts,
    last_error,
    created_at,
    updated_at
)
VALUES (
    ?,
    ?,
    ?,
    ?,
    1,
    CURRENT_TIMESTAMP,
    CURRENT_TIMESTAMP,
    CURRENT_TIMESTAMP,
    NULL,
    NULL,
    0,
    NULL,
    CURRENT_TIMESTAMP,
    CURRENT_TIMESTAMP
)
ON CONFLICT (
    source,
    term_type,
    normalized_term
)
DO UPDATE SET
    change_mask =
        seo_catalog_dirty_terms.change_mask
        |
        EXCLUDED.change_mask,

    dirty_revision =
        seo_catalog_dirty_terms.dirty_revision
        + 1,

    last_dirty_at =
        CURRENT_TIMESTAMP,

    available_at =
        LEAST(
            seo_catalog_dirty_terms.available_at,
            CURRENT_TIMESTAMP
        ),

    attempts =
        0,

    last_error =
        NULL,

    updated_at =
        CURRENT_TIMESTAMP
SQL,
            [
                $source,
                $termType,
                $normalizedTerm,
                $changeMask,
            ]
        );
    }

    /**
     * Remove work only when the exact claimed revision is still current.
     *
     * This is the no-lost-update acknowledgement.
     */
    private function acknowledgeWork(
        int $videoId,
        int $claimedRevision,
        string $processingToken
    ): bool {
        $deleted =
            DB::delete(
                <<<'SQL'
DELETE FROM seo_catalog_dirty_videos
WHERE
    video_id = ?
    AND dirty_revision = ?
    AND processing_token =
        CAST(? AS uuid)
SQL,
                [
                    $videoId,
                    $claimedRevision,
                    $processingToken,
                ]
            );

        return $deleted === 1;
    }

    /**
     * A newer revision arrived while the worker was running.
     *
     * Preserve the row, remove only this worker's lease and make the new
     * revision immediately available.
     */
    private function releaseChangedRevision(
        int $videoId,
        string $processingToken
    ): void {
        DB::update(
            <<<'SQL'
UPDATE seo_catalog_dirty_videos
SET
    processing_started_at =
        NULL,

    processing_token =
        NULL,

    available_at =
        LEAST(
            available_at,
            CURRENT_TIMESTAMP
        ),

    updated_at =
        CURRENT_TIMESTAMP

WHERE
    video_id = ?
    AND processing_token =
        CAST(? AS uuid)
SQL,
            [
                $videoId,
                $processingToken,
            ]
        );
    }

    /**
     * Release a failed claim.
     *
     * If the row is still the same revision, use bounded retry backoff.
     *
     * If a newer revision arrived during the failure, make it immediately
     * available because that newer change may already remove the cause of the
     * failure.
     */
    private function releaseFailedWork(
        int $videoId,
        int $claimedRevision,
        string $processingToken,
        Throwable $exception
    ): void {
        $message =
            Str::limit(
                $exception->getMessage(),
                4000,
                ''
            );

        DB::update(
            <<<'SQL'
UPDATE seo_catalog_dirty_videos
SET
    processing_started_at =
        NULL,

    processing_token =
        NULL,

    available_at =
        CASE
            WHEN dirty_revision = ?
            THEN
                CURRENT_TIMESTAMP
                +
                (
                    LEAST(
                        3600,
                        GREATEST(
                            60,
                            attempts * 60
                        )
                    )
                    *
                    INTERVAL '1 second'
                )
            ELSE
                CURRENT_TIMESTAMP
        END,

    last_error =
        CASE
            WHEN dirty_revision = ?
            THEN ?
            ELSE NULL
        END,

    updated_at =
        CURRENT_TIMESTAMP

WHERE
    video_id = ?
    AND processing_token =
        CAST(? AS uuid)
SQL,
            [
                $claimedRevision,
                $claimedRevision,
                $message,
                $videoId,
                $processingToken,
            ]
        );
    }

    /**
     * Stable term identity.
     */
    private function termIdentityKey(
        string $source,
        string $termType,
        string $normalizedTerm
    ): string {
        return $this->encodeState(
            [
                $source,
                $termType,
                $normalizedTerm,
            ]
        );
    }

    /**
     * Produce stable comparable state encoding.
     */
    private function encodeState(
        array $state
    ): string {
        return json_encode(
            $state,
            JSON_THROW_ON_ERROR
            | JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
        );
    }
}
