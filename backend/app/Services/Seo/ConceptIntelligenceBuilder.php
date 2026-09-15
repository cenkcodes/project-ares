<?php

namespace App\Services\Seo;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class ConceptIntelligenceBuilder
{
    public const ENGINE_VERSION = 'concept-intelligence-v1';

    public const ENGINE_STAGE = 'concept-intelligence';

    private const SYNC_ENGINE_KEY = 'concept-intelligence';

    private const MAX_TERM_LENGTH = 150;

    private const NOISE_MIN_VIDEO_COUNT = 100;

    private const NOISE_SOURCE_COVERAGE = 0.70;

    private const MAX_BATCH_LIMIT = 5000;

    private const DEFAULT_LEASE_SECONDS = 900;

    /**
     * Full Concept Intelligence reconciliation.
     *
     * This reads the durable provider-independent term membership snapshot,
     * rebuilds concept/alias intelligence, acknowledges only the dirty-term
     * revisions present at the start of the transaction, and clears the
     * semantic full-rebuild/taxonomy flags only when the transaction commits.
     */
    public function rebuildAll(bool $dryRun = false): array
    {
        $runUuid = (string) Str::uuid();
        $startedAt = now();
        $runId = null;

        if (! $dryRun) {
            $this->ensureConceptSyncState();

            $runId = $this->createRun(
                $runUuid,
                'full',
                $startedAt,
                [
                    'dry_run' => false,
                    'max_term_length' => self::MAX_TERM_LENGTH,
                    'noise_min_video_count' => self::NOISE_MIN_VIDEO_COUNT,
                    'noise_source_coverage' => self::NOISE_SOURCE_COVERAGE,
                ]
            );

            $this->markSyncStarted(
                'full',
                $startedAt
            );
        }

        try {
            $result = $this->runTransactional(
                function () use ($startedAt, $dryRun): array {
                    if ($dryRun) {
                        $this->ensureConceptSyncState();
                    }

                    $this->createDirtySnapshot();
                    $this->createFullAliasStage();
                    $this->createFullConceptStage();

                    $conceptStats = $this->upsertStagedConcepts();
                    $this->retireMissingConcepts();
                    $aliasStats = $this->replaceAllAliasesFromStage();

                    $excludedDirtyRows =
                        (int) DB::table('seo_catalog_dirty_terms')
                            ->join(
                                'tmp_ci_dirty_snapshot as s',
                                function ($join): void {
                                    $join
                                        ->on(
                                            's.id',
                                            '=',
                                            'seo_catalog_dirty_terms.id'
                                        )
                                        ->on(
                                            's.dirty_revision',
                                            '=',
                                            'seo_catalog_dirty_terms.dirty_revision'
                                        );
                                }
                            )
                            ->where(function ($query): void {
                                $query
                                    ->whereNull(
                                        'seo_catalog_dirty_terms.normalized_term'
                                    )
                                    ->orWhere(
                                        'seo_catalog_dirty_terms.normalized_term',
                                        ''
                                    )
                                    ->orWhereRaw(
                                        'CHAR_LENGTH(seo_catalog_dirty_terms.normalized_term) > ?',
                                        [self::MAX_TERM_LENGTH]
                                    );
                            })
                            ->count();

                    $acknowledgedDirtyRows =
                        $this->acknowledgeFullDirtySnapshot();

                    $completedAt = now();

                    $this->markSyncCompleted(
                        'full',
                        $completedAt,
                        [
                            'concept_engine' => self::ENGINE_VERSION,
                            'concept_count' => $conceptStats['concept_count'],
                            'active_concept_count' => $conceptStats['active_concept_count'],
                            'strong_concept_count' => $conceptStats['strong_concept_count'],
                            'qualified_concept_count' => $conceptStats['qualified_concept_count'],
                            'canonical_concept_count' => $conceptStats['canonical_concept_count'],
                            'noise_concept_count' => $conceptStats['noise_concept_count'],
                            'alias_count' => $aliasStats['alias_count'],
                            'active_alias_count' => $aliasStats['active_alias_count'],
                            'noise_alias_count' => $aliasStats['noise_alias_count'],
                            'acknowledged_dirty_rows' => $acknowledgedDirtyRows,
                            'excluded_dirty_rows' => $excludedDirtyRows,
                        ]
                    );

                    return [
                        'run_mode' => 'full',
                        'engine_version' => self::ENGINE_VERSION,
                        'concept_count' => $conceptStats['concept_count'],
                        'active_concept_count' => $conceptStats['active_concept_count'],
                        'strong_concept_count' => $conceptStats['strong_concept_count'],
                        'qualified_concept_count' => $conceptStats['qualified_concept_count'],
                        'canonical_concept_count' => $conceptStats['canonical_concept_count'],
                        'noise_concept_count' => $conceptStats['noise_concept_count'],
                        'alias_count' => $aliasStats['alias_count'],
                        'active_alias_count' => $aliasStats['active_alias_count'],
                        'noise_alias_count' => $aliasStats['noise_alias_count'],
                        'acknowledged_dirty_rows' => $acknowledgedDirtyRows,
                        'excluded_dirty_rows' => $excludedDirtyRows,
                        'dirty_rows_remaining_snapshot' =>
                            (int) DB::table('seo_catalog_dirty_terms')->count(),
                        'started_at' => $startedAt->toDateTimeString(),
                        'completed_at' => $completedAt->toDateTimeString(),
                    ];
                },
                $dryRun
            );

            $result['run_uuid'] = $runUuid;
            $result['dry_run'] = $dryRun;

            if (! $dryRun && $runId !== null) {
                $this->completeRun(
                    $runId,
                    $result
                );
            }

            return $result;
        } catch (Throwable $exception) {
            if (! $dryRun && $runId !== null) {
                $this->failRun(
                    $runId,
                    $exception
                );

                $this->markSyncFailed(
                    'full',
                    $exception
                );
            }

            throw $exception;
        }
    }

    /**
     * Process one dirty-term batch incrementally.
     *
     * Each queue row is provider-scoped, but a concept is global by exact
     * normalized term. Therefore every claimed term is recalculated across all
     * current providers before its claimed queue revisions are acknowledged.
     */
    public function processBatch(
        int $limit = 1000,
        int $leaseSeconds = self::DEFAULT_LEASE_SECONDS,
        bool $dryRun = false
    ): array {
        $limit = max(
            1,
            min(
                $limit,
                self::MAX_BATCH_LIMIT
            )
        );

        $leaseSeconds = max(
            60,
            min(
                $leaseSeconds,
                86400
            )
        );

        $this->assertIncrementalAllowed();

        $runUuid = (string) Str::uuid();
        $processingToken = (string) Str::uuid();
        $startedAt = now();
        $runId = null;

        if (! $dryRun) {
            $this->ensureConceptSyncState();

            $runId = $this->createRun(
                $runUuid,
                'incremental',
                $startedAt,
                [
                    'dry_run' => false,
                    'processing_token' => $processingToken,
                    'limit' => $limit,
                    'lease_seconds' => $leaseSeconds,
                ]
            );

            $this->markSyncStarted(
                'incremental',
                $startedAt
            );
        }

        try {
            $result = $this->runTransactional(
                function () use (
                    $limit,
                    $leaseSeconds,
                    $processingToken,
                    $startedAt
                ): array {
                    $claimedRows = $this->claimDirtyTerms(
                        $processingToken,
                        $limit,
                        $leaseSeconds
                    );

                    $claimedCount = count($claimedRows);

                    if ($claimedCount === 0) {
                        $completedAt = now();

                        $this->markSyncCompleted(
                            'incremental',
                            $completedAt,
                            [
                                'concept_engine' => self::ENGINE_VERSION,
                                'claimed_count' => 0,
                                'processed_term_count' => 0,
                                'acknowledged_dirty_rows' => 0,
                            ]
                        );

                        return [
                            'run_mode' => 'incremental',
                            'engine_version' => self::ENGINE_VERSION,
                            'claimed_count' => 0,
                            'processed_term_count' => 0,
                            'concept_count' => 0,
                            'alias_count' => 0,
                            'acknowledged_dirty_rows' => 0,
                            'excluded_dirty_rows' => 0,
                            'dirty_rows_remaining_snapshot' =>
                                (int) DB::table('seo_catalog_dirty_terms')->count(),
                            'started_at' => $startedAt->toDateTimeString(),
                            'completed_at' => $completedAt->toDateTimeString(),
                        ];
                    }

                    $this->createClaimedRowsStage(
                        $claimedRows
                    );

                    $this->createClaimedTermsStage();
                    $this->createIncrementalAliasStage();
                    $this->createIncrementalConceptStage();

                    $conceptStats = $this->upsertStagedConcepts();
                    $this->retireClaimedMissingConcepts();
                    $aliasStats = $this->replaceClaimedAliasesFromStage();

                    $acknowledgedDirtyRows =
                        $this->acknowledgeClaimedDirtyRows(
                            $processingToken
                        );

                    $excludedDirtyRows =
                        (int) DB::table('tmp_ci_claimed_rows')
                            ->where(function ($query): void {
                                $query
                                    ->whereNull('normalized_term')
                                    ->orWhere('normalized_term', '')
                                    ->orWhereRaw(
                                        'CHAR_LENGTH(normalized_term) > ?',
                                        [self::MAX_TERM_LENGTH]
                                    );
                            })
                            ->count();

                    $completedAt = now();

                    $this->markSyncCompleted(
                        'incremental',
                        $completedAt,
                        [
                            'concept_engine' => self::ENGINE_VERSION,
                            'claimed_count' => $claimedCount,
                            'processed_term_count' =>
                                (int) DB::table('tmp_ci_claimed_terms')->count(),
                            'acknowledged_dirty_rows' => $acknowledgedDirtyRows,
                            'excluded_dirty_rows' => $excludedDirtyRows,
                        ]
                    );

                    return [
                        'run_mode' => 'incremental',
                        'engine_version' => self::ENGINE_VERSION,
                        'claimed_count' => $claimedCount,
                        'processed_term_count' =>
                            (int) DB::table('tmp_ci_claimed_terms')->count(),
                        'concept_count' => $conceptStats['concept_count'],
                        'active_concept_count' => $conceptStats['active_concept_count'],
                        'strong_concept_count' => $conceptStats['strong_concept_count'],
                        'qualified_concept_count' => $conceptStats['qualified_concept_count'],
                        'canonical_concept_count' => $conceptStats['canonical_concept_count'],
                        'noise_concept_count' => $conceptStats['noise_concept_count'],
                        'alias_count' => $aliasStats['alias_count'],
                        'active_alias_count' => $aliasStats['active_alias_count'],
                        'noise_alias_count' => $aliasStats['noise_alias_count'],
                        'acknowledged_dirty_rows' => $acknowledgedDirtyRows,
                        'excluded_dirty_rows' => $excludedDirtyRows,
                        'dirty_rows_remaining_snapshot' =>
                            (int) DB::table('seo_catalog_dirty_terms')->count(),
                        'started_at' => $startedAt->toDateTimeString(),
                        'completed_at' => $completedAt->toDateTimeString(),
                    ];
                },
                $dryRun
            );

            $result['run_uuid'] = $runUuid;
            $result['dry_run'] = $dryRun;

            if (! $dryRun && $runId !== null) {
                $this->completeRun(
                    $runId,
                    $result
                );
            }

            return $result;
        } catch (Throwable $exception) {
            if (! $dryRun && $runId !== null) {
                $this->failRun(
                    $runId,
                    $exception
                );

                $this->markSyncFailed(
                    'incremental',
                    $exception
                );
            }

            throw $exception;
        }
    }

    private function runTransactional(
        callable $callback,
        bool $dryRun
    ): array {
        DB::beginTransaction();

        try {
            DB::statement(
                'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ'
            );

            $result = $callback();

            if ($dryRun) {
                DB::rollBack();
            } else {
                DB::commit();
            }

            return $result;
        } catch (Throwable $exception) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            throw $exception;
        }
    }

    private function createDirtySnapshot(): void
    {
        DB::statement(
            <<<'SQL'
CREATE TEMP TABLE tmp_ci_dirty_snapshot
ON COMMIT DROP
AS
SELECT
    id,
    dirty_revision
FROM seo_catalog_dirty_terms
SQL
        );

        DB::statement(
            'CREATE UNIQUE INDEX tmp_ci_dirty_snapshot_id_idx '
            . 'ON tmp_ci_dirty_snapshot (id)'
        );
    }

    private function createFullAliasStage(): void
    {
        $this->createAliasStage(
            null
        );
    }

    private function createIncrementalAliasStage(): void
    {
        $this->createAliasStage(
            'tmp_ci_claimed_terms'
        );
    }

    private function createAliasStage(?string $termTable): void
    {
        $termJoin = '';

        if ($termTable !== null) {
            $termJoin =
                "INNER JOIN {$termTable} claimed "
                . 'ON claimed.normalized_term = m.normalized_term';
        }

        $sql = sprintf(
            <<<'SQL'
CREATE TEMP TABLE tmp_ci_aliases
ON COMMIT DROP
AS
WITH active_source_counts AS (
    SELECT
        video_source AS source,
        COUNT(*)::bigint AS active_video_count
    FROM videos
    WHERE is_active = TRUE
    GROUP BY video_source
),
alias_support AS (
    SELECT
        m.source,
        m.term_type,
        m.normalized_term,
        COUNT(DISTINCT m.video_id)::bigint AS video_count
    FROM seo_video_term_memberships m
    %s
    WHERE
        m.normalized_term IS NOT NULL
        AND m.normalized_term <> ''
        AND CHAR_LENGTH(m.normalized_term) <= %d
    GROUP BY
        m.source,
        m.term_type,
        m.normalized_term
),
scored AS (
    SELECT
        a.source,
        a.term_type,
        a.normalized_term,
        a.video_count,
        COALESCE(s.active_video_count, 0)::bigint AS active_video_count,
        CASE
            WHEN COALESCE(s.active_video_count, 0) = 0
                THEN 0::numeric
            ELSE
                a.video_count::numeric
                / NULLIF(s.active_video_count, 0)::numeric
        END AS source_coverage
    FROM alias_support a
    LEFT JOIN active_source_counts s
        ON s.source = a.source
)
SELECT
    source,
    term_type,
    normalized_term,
    video_count,
    active_video_count,
    source_coverage,
    CASE
        WHEN
            video_count >= %d
            AND source_coverage >= %f
            THEN FALSE
        ELSE TRUE
    END AS is_active,
    CASE
        WHEN
            video_count >= %d
            AND source_coverage >= %f
            THEN 0.05000000::numeric(12,8)
        ELSE
            LEAST(
                1.00000000::numeric,
                0.35000000::numeric
                + (
                    LN(1 + video_count::numeric)
                    / LN(1001::numeric)
                  ) * 0.65000000::numeric
            )::numeric(12,8)
    END AS confidence_score
FROM scored
SQL,
            $termJoin,
            self::MAX_TERM_LENGTH,
            self::NOISE_MIN_VIDEO_COUNT,
            self::NOISE_SOURCE_COVERAGE,
            self::NOISE_MIN_VIDEO_COUNT,
            self::NOISE_SOURCE_COVERAGE
        );

        DB::statement($sql);

        DB::statement(
            'CREATE UNIQUE INDEX tmp_ci_alias_identity_idx '
            . 'ON tmp_ci_aliases (source, term_type, normalized_term)'
        );

        DB::statement(
            'CREATE INDEX tmp_ci_alias_term_idx '
            . 'ON tmp_ci_aliases (normalized_term, is_active)'
        );
    }

    private function createFullConceptStage(): void
    {
        $this->createConceptStage(
            null
        );
    }

    private function createIncrementalConceptStage(): void
    {
        $this->createConceptStage(
            'tmp_ci_claimed_terms'
        );
    }

    private function createConceptStage(?string $termTable): void
    {
        $termJoin = '';

        if ($termTable !== null) {
            $termJoin =
                "INNER JOIN {$termTable} claimed "
                . 'ON claimed.normalized_term = m.normalized_term';
        }

        $sql = sprintf(
            <<<'SQL'
CREATE TEMP TABLE tmp_ci_concepts
ON COMMIT DROP
AS
WITH all_terms AS (
    SELECT
        normalized_term,
        COUNT(*)::integer AS alias_count
    FROM tmp_ci_aliases
    GROUP BY normalized_term
),
qualified_support AS (
    SELECT
        m.normalized_term,
        COUNT(DISTINCT m.video_id)::bigint AS support_video_count,
        COUNT(DISTINCT m.source)::integer AS provider_count,
        STRING_AGG(
            DISTINCT m.source,
            ','
            ORDER BY m.source
        ) AS active_sources
    FROM seo_video_term_memberships m
    %s
    INNER JOIN tmp_ci_aliases a
        ON a.source = m.source
        AND a.term_type = m.term_type
        AND a.normalized_term = m.normalized_term
        AND a.is_active = TRUE
    GROUP BY m.normalized_term
),
category_terms AS (
    SELECT DISTINCT normalized_alias AS normalized_term
    FROM category_aliases
    WHERE
        is_active = TRUE
        AND normalized_alias IS NOT NULL
        AND normalized_alias <> ''
)
SELECT
    t.normalized_term,
    COALESCE(q.support_video_count, 0)::bigint AS support_video_count,
    COALESCE(q.provider_count, 0)::integer AS provider_count,
    t.alias_count,
    COALESCE(q.active_sources, '') AS active_sources,
    CASE
        WHEN c.normalized_term IS NULL
            THEN FALSE
        ELSE TRUE
    END AS is_category_alias
FROM all_terms t
LEFT JOIN qualified_support q
    ON q.normalized_term = t.normalized_term
LEFT JOIN category_terms c
    ON c.normalized_term = t.normalized_term
SQL,
            $termJoin
        );

        DB::statement($sql);

        DB::statement(
            'CREATE UNIQUE INDEX tmp_ci_concept_term_idx '
            . 'ON tmp_ci_concepts (normalized_term)'
        );
    }

    private function upsertStagedConcepts(): array
    {
        $timestamp = now();
        $rows = [];

        $stats = [
            'concept_count' => 0,
            'active_concept_count' => 0,
            'strong_concept_count' => 0,
            'qualified_concept_count' => 0,
            'canonical_concept_count' => 0,
            'noise_concept_count' => 0,
        ];

        foreach (
            DB::table('tmp_ci_concepts')
                ->orderBy('normalized_term')
                ->cursor()
            as $row
        ) {
            $normalizedTerm = (string) $row->normalized_term;
            $supportVideoCount = (int) $row->support_video_count;
            $providerCount = (int) $row->provider_count;
            $aliasCount = (int) $row->alias_count;
            $isCategoryAlias = (bool) $row->is_category_alias;

            $qualityStatus = $this->qualityStatus(
                $isCategoryAlias,
                $supportVideoCount,
                $providerCount
            );

            $isActive = $supportVideoCount > 0;

            $semanticScore = $this->semanticScore(
                $isCategoryAlias,
                $supportVideoCount,
                $providerCount
            );

            $sourceFingerprint = hash(
                'sha256',
                implode(
                    '|',
                    [
                        self::ENGINE_VERSION,
                        $normalizedTerm,
                        (string) $supportVideoCount,
                        (string) $providerCount,
                        (string) $aliasCount,
                        (string) $row->active_sources,
                        $isCategoryAlias ? '1' : '0',
                        $qualityStatus,
                        number_format(
                            $semanticScore,
                            8,
                            '.',
                            ''
                        ),
                    ]
                )
            );

            $rows[] = [
                'concept_key' => $this->conceptKey($normalizedTerm),
                'display_name' => $this->displayName($normalizedTerm),
                'concept_type' =>
                    $isCategoryAlias
                        ? 'category-alias'
                        : 'term',
                'quality_status' => $qualityStatus,
                'is_active' => $isActive,
                'support_video_count' => $supportVideoCount,
                'provider_count' => $providerCount,
                'alias_count' => $aliasCount,
                'semantic_score' => $semanticScore,
                'source_fingerprint' => $sourceFingerprint,
                'first_seen_at' => $timestamp,
                'last_seen_at' =>
                    $isActive
                        ? $timestamp
                        : null,
                'calculated_at' => $timestamp,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];

            $stats['concept_count']++;

            if ($isActive) {
                $stats['active_concept_count']++;
            }

            if ($qualityStatus === 'strong') {
                $stats['strong_concept_count']++;
            }

            if ($qualityStatus === 'qualified') {
                $stats['qualified_concept_count']++;
            }

            if ($qualityStatus === 'canonical') {
                $stats['canonical_concept_count']++;
            }

            if ($qualityStatus === 'noise') {
                $stats['noise_concept_count']++;
            }

            if (count($rows) >= 500) {
                $this->upsertConceptChunk($rows);
                $rows = [];
            }
        }

        if ($rows !== []) {
            $this->upsertConceptChunk($rows);
        }

        return $stats;
    }

    private function upsertConceptChunk(array $rows): void
    {
        DB::table('seo_term_concepts')->upsert(
            $rows,
            ['concept_key'],
            [
                'display_name',
                'concept_type',
                'quality_status',
                'is_active',
                'support_video_count',
                'provider_count',
                'alias_count',
                'semantic_score',
                'source_fingerprint',
                'last_seen_at',
                'calculated_at',
                'updated_at',
            ]
        );
    }

    private function retireMissingConcepts(): void
    {
        DB::statement(
            <<<'SQL'
UPDATE seo_term_concepts c
SET
    quality_status = 'retired',
    is_active = FALSE,
    support_video_count = 0,
    provider_count = 0,
    alias_count = 0,
    semantic_score = 0,
    last_seen_at = NULL,
    calculated_at = CURRENT_TIMESTAMP,
    updated_at = CURRENT_TIMESTAMP
WHERE
    c.concept_key LIKE 'term:%'
    AND NOT EXISTS (
        SELECT 1
        FROM tmp_ci_concepts t
        WHERE c.concept_key = ('term:' || t.normalized_term)
    )
SQL
        );
    }

    private function retireClaimedMissingConcepts(): void
    {
        DB::statement(
            <<<'SQL'
UPDATE seo_term_concepts c
SET
    quality_status = 'retired',
    is_active = FALSE,
    support_video_count = 0,
    provider_count = 0,
    alias_count = 0,
    semantic_score = 0,
    last_seen_at = NULL,
    calculated_at = CURRENT_TIMESTAMP,
    updated_at = CURRENT_TIMESTAMP
WHERE
    EXISTS (
        SELECT 1
        FROM tmp_ci_claimed_terms claimed
        WHERE c.concept_key = ('term:' || claimed.normalized_term)
    )
    AND NOT EXISTS (
        SELECT 1
        FROM tmp_ci_concepts t
        WHERE c.concept_key = ('term:' || t.normalized_term)
    )
SQL
        );
    }

    private function replaceAllAliasesFromStage(): array
    {
        DB::table('seo_term_concept_aliases')->delete();

        return $this->insertAliasesFromStage();
    }

    private function replaceClaimedAliasesFromStage(): array
    {
        DB::statement(
            <<<'SQL'
DELETE FROM seo_term_concept_aliases a
USING seo_term_concepts c,
      tmp_ci_claimed_terms claimed
WHERE
    a.concept_id = c.id
    AND c.concept_key = ('term:' || claimed.normalized_term)
SQL
        );

        return $this->insertAliasesFromStage();
    }

    private function insertAliasesFromStage(): array
    {
        $timestamp = now();
        $rows = [];

        $stats = [
            'alias_count' => 0,
            'active_alias_count' => 0,
            'noise_alias_count' => 0,
        ];

        $query = DB::table('tmp_ci_aliases as a')
            ->join(
                'seo_term_concepts as c',
                DB::raw("c.concept_key"),
                '=',
                DB::raw("('term:' || a.normalized_term)")
            )
            ->select([
                'c.id as concept_id',
                'a.source',
                'a.term_type',
                'a.normalized_term',
                'a.video_count',
                'a.confidence_score',
                'a.is_active',
            ])
            ->orderBy('a.normalized_term')
            ->orderBy('a.source')
            ->orderBy('a.term_type');

        foreach ($query->cursor() as $row) {
            $isActive = (bool) $row->is_active;

            $rows[] = [
                'concept_id' => (int) $row->concept_id,
                'source' => (string) $row->source,
                'term_type' => (string) $row->term_type,
                'normalized_term' => (string) $row->normalized_term,
                'raw_example' => (string) $row->normalized_term,
                'video_count' => (int) $row->video_count,
                'provider_count' =>
                    (int) $row->video_count > 0
                        ? 1
                        : 0,
                'confidence_score' => (float) $row->confidence_score,
                'is_active' => $isActive,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];

            $stats['alias_count']++;

            if ($isActive) {
                $stats['active_alias_count']++;
            } else {
                $stats['noise_alias_count']++;
            }

            if (count($rows) >= 500) {
                DB::table('seo_term_concept_aliases')->insert($rows);
                $rows = [];
            }
        }

        if ($rows !== []) {
            DB::table('seo_term_concept_aliases')->insert($rows);
        }

        return $stats;
    }

    private function acknowledgeFullDirtySnapshot(): int
    {
        return DB::affectingStatement(
            <<<'SQL'
DELETE FROM seo_catalog_dirty_terms d
USING tmp_ci_dirty_snapshot s
WHERE
    d.id = s.id
    AND d.dirty_revision = s.dirty_revision
SQL
        );
    }

    private function claimDirtyTerms(
        string $processingToken,
        int $limit,
        int $leaseSeconds
    ): array {
        $sql =
            <<<'SQL'
WITH candidates AS (
    SELECT id
    FROM seo_catalog_dirty_terms
    WHERE
        available_at <= CURRENT_TIMESTAMP
        AND (
            processing_started_at IS NULL
            OR processing_started_at <
                CURRENT_TIMESTAMP - (? * INTERVAL '1 second')
        )
    ORDER BY
        last_dirty_at,
        id
    FOR UPDATE SKIP LOCKED
    LIMIT ?
)
UPDATE seo_catalog_dirty_terms d
SET
    processing_started_at = CURRENT_TIMESTAMP,
    processing_token = ?::uuid,
    attempts = d.attempts + 1,
    last_error = NULL,
    updated_at = CURRENT_TIMESTAMP
FROM candidates c
WHERE d.id = c.id
RETURNING
    d.id,
    d.source,
    d.term_type,
    d.normalized_term,
    d.dirty_revision
SQL;

        return DB::select(
            $sql,
            [
                $leaseSeconds,
                $limit,
                $processingToken,
            ]
        );
    }

    private function createClaimedRowsStage(array $claimedRows): void
    {
        DB::statement(
            <<<'SQL'
CREATE TEMP TABLE tmp_ci_claimed_rows (
    id bigint PRIMARY KEY,
    source varchar(64) NOT NULL,
    term_type varchar(64) NOT NULL,
    normalized_term varchar(500) NOT NULL,
    dirty_revision bigint NOT NULL
)
ON COMMIT DROP
SQL
        );

        $rows = [];

        foreach ($claimedRows as $row) {
            $rows[] = [
                'id' => (int) $row->id,
                'source' => (string) $row->source,
                'term_type' => (string) $row->term_type,
                'normalized_term' => (string) $row->normalized_term,
                'dirty_revision' => (int) $row->dirty_revision,
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('tmp_ci_claimed_rows')->insert($chunk);
        }
    }

    private function createClaimedTermsStage(): void
    {
        DB::statement(
            sprintf(
                <<<'SQL'
CREATE TEMP TABLE tmp_ci_claimed_terms
ON COMMIT DROP
AS
SELECT DISTINCT normalized_term
FROM tmp_ci_claimed_rows
WHERE
    normalized_term IS NOT NULL
    AND normalized_term <> ''
    AND CHAR_LENGTH(normalized_term) <= %d
SQL,
                self::MAX_TERM_LENGTH
            )
        );

        DB::statement(
            'CREATE UNIQUE INDEX tmp_ci_claimed_term_idx '
            . 'ON tmp_ci_claimed_terms (normalized_term)'
        );
    }

    private function acknowledgeClaimedDirtyRows(
        string $processingToken
    ): int {
        return DB::affectingStatement(
            <<<'SQL'
DELETE FROM seo_catalog_dirty_terms d
USING tmp_ci_claimed_rows c
WHERE
    d.id = c.id
    AND d.dirty_revision = c.dirty_revision
    AND d.processing_token = ?::uuid
SQL,
            [$processingToken]
        );
    }

    private function assertIncrementalAllowed(): void
    {
        $state = DB::table('seo_semantic_sync_states')
            ->where('engine_key', self::SYNC_ENGINE_KEY)
            ->first();

        if ($state === null) {
            throw new RuntimeException(
                'Concept Intelligence has not been initialized. '
                . 'Run a full reconciliation first.'
            );
        }

        if ((string) $state->engine_version !== self::ENGINE_VERSION) {
            throw new RuntimeException(
                'Concept Intelligence engine version changed. '
                . 'Run a full reconciliation first.'
            );
        }

        if ((bool) $state->full_rebuild_required) {
            throw new RuntimeException(
                'A full Concept Intelligence reconciliation is required '
                . 'before incremental dirty-term processing.'
            );
        }

        $metadata = json_decode(
            (string) ($state->metadata ?? '{}'),
            true
        );

        $storedTaxonomyFingerprint =
            is_array($metadata)
                ? ($metadata['taxonomy_fingerprint'] ?? null)
                : null;

        $currentTaxonomyFingerprint =
            $this->taxonomyFingerprint();

        if (
            ! is_string($storedTaxonomyFingerprint)
            || $storedTaxonomyFingerprint === ''
            || ! hash_equals(
                $storedTaxonomyFingerprint,
                $currentTaxonomyFingerprint
            )
        ) {
            throw new RuntimeException(
                'Category taxonomy changed since the last full Concept '
                . 'Intelligence reconciliation. Run --full first.'
            );
        }
    }

    private function ensureConceptSyncState(): void
    {
        $timestamp = now();

        DB::table('seo_semantic_sync_states')->insertOrIgnore(
            [
                'engine_key' => self::SYNC_ENGINE_KEY,
                'engine_version' => self::ENGINE_VERSION,
                'full_rebuild_required' => true,
                'taxonomy_dirty' => false,
                'taxonomy_dirty_at' => null,
                'last_incremental_started_at' => null,
                'last_incremental_completed_at' => null,
                'last_full_reconcile_started_at' => null,
                'last_full_reconcile_completed_at' => null,
                'last_video_id' => 0,
                'last_video_updated_at' => null,
                'last_source_term_id' => 0,
                'last_source_term_updated_at' => null,
                'last_error' => null,
                'metadata' => json_encode(
                    [
                        'concept_engine' => self::ENGINE_VERSION,
                        'taxonomy_fingerprint' => null,
                    ],
                    JSON_UNESCAPED_SLASHES
                ),
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]
        );
    }

    private function taxonomyFingerprint(): string
    {
        $categories = DB::table('categories')
            ->select([
                'id',
                'name',
                'slug',
                'is_active',
            ])
            ->orderBy('id')
            ->get()
            ->map(
                static fn ($row): array => [
                    'id' => (int) $row->id,
                    'name' => (string) $row->name,
                    'slug' => (string) $row->slug,
                    'is_active' => (bool) $row->is_active,
                ]
            )
            ->all();

        $aliases = DB::table('category_aliases')
            ->select([
                'id',
                'category_id',
                'source',
                'alias_type',
                'normalized_alias',
                'is_active',
            ])
            ->orderBy('id')
            ->get()
            ->map(
                static fn ($row): array => [
                    'id' => (int) $row->id,
                    'category_id' => (int) $row->category_id,
                    'source' => (string) $row->source,
                    'alias_type' => (string) $row->alias_type,
                    'normalized_alias' => (string) $row->normalized_alias,
                    'is_active' => (bool) $row->is_active,
                ]
            )
            ->all();

        return hash(
            'sha256',
            json_encode(
                [
                    'categories' => $categories,
                    'aliases' => $aliases,
                ],
                JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
            ) ?: ''
        );
    }

    private function qualityStatus(
        bool $isCategoryAlias,
        int $supportVideoCount,
        int $providerCount
    ): string {
        if ($supportVideoCount <= 0 || $providerCount <= 0) {
            return 'noise';
        }

        if ($isCategoryAlias) {
            return 'canonical';
        }

        if (
            $providerCount >= 3
            && $supportVideoCount >= 50
        ) {
            return 'strong';
        }

        if (
            (
                $providerCount >= 3
                && $supportVideoCount >= 25
            )
            || (
                $providerCount >= 2
                && $supportVideoCount >= 100
            )
        ) {
            return 'qualified';
        }

        return 'candidate';
    }

    private function semanticScore(
        bool $isCategoryAlias,
        int $supportVideoCount,
        int $providerCount
    ): float {
        if ($supportVideoCount <= 0 || $providerCount <= 0) {
            return 0.0;
        }

        $score =
            log(1 + $supportVideoCount) * 8.0
            + $providerCount * 10.0
            + ($isCategoryAlias ? 10.0 : 0.0);

        return round(
            min(100.0, $score),
            8
        );
    }

    private function conceptKey(string $normalizedTerm): string
    {
        return 'term:' . $normalizedTerm;
    }

    private function displayName(string $normalizedTerm): string
    {
        $acronyms = [
            'bbc' => 'BBC',
            'bdsm' => 'BDSM',
            'bbw' => 'BBW',
            'hd' => 'HD',
            'jav' => 'JAV',
            'pov' => 'POV',
            'xxx' => 'XXX',
        ];

        $parts = array_values(
            array_filter(
                preg_split(
                    '/[-_]+/',
                    $normalizedTerm
                ) ?: [],
                static fn (string $part): bool => $part !== ''
            )
        );

        $displayParts = [];

        foreach ($parts as $part) {
            $lower = strtolower($part);

            $displayParts[] =
                $acronyms[$lower]
                ?? ucfirst($lower);
        }

        $display = trim(
            implode(' ', $displayParts)
        );

        return $display !== ''
            ? $display
            : $normalizedTerm;
    }

    private function createRun(
        string $runUuid,
        string $runMode,
        $startedAt,
        array $metadata
    ): int {
        return (int) DB::table('seo_semantic_runs')
            ->insertGetId(
                [
                    'run_uuid' => $runUuid,
                    'engine_stage' => self::ENGINE_STAGE,
                    'run_mode' => $runMode,
                    'status' => 'running',
                    'engine_version' => self::ENGINE_VERSION,
                    'claimed_count' => 0,
                    'processed_count' => 0,
                    'changed_count' => 0,
                    'skipped_count' => 0,
                    'failed_count' => 0,
                    'started_at' => $startedAt,
                    'heartbeat_at' => $startedAt,
                    'completed_at' => null,
                    'last_error' => null,
                    'metadata' => json_encode(
                        $metadata,
                        JSON_UNESCAPED_SLASHES
                    ),
                    'created_at' => $startedAt,
                    'updated_at' => $startedAt,
                ]
            );
    }

    private function completeRun(
        int $runId,
        array $result
    ): void {
        $completedAt = now();

        DB::table('seo_semantic_runs')
            ->where('id', $runId)
            ->update(
                [
                    'status' => 'completed',
                    'claimed_count' =>
                        (int) ($result['claimed_count']
                            ?? $result['acknowledged_dirty_rows']
                            ?? 0),
                    'processed_count' =>
                        (int) ($result['processed_term_count']
                            ?? $result['concept_count']
                            ?? 0),
                    'changed_count' =>
                        (int) ($result['concept_count'] ?? 0),
                    'skipped_count' =>
                        (int) ($result['excluded_dirty_rows'] ?? 0),
                    'failed_count' => 0,
                    'heartbeat_at' => $completedAt,
                    'completed_at' => $completedAt,
                    'last_error' => null,
                    'metadata' => json_encode(
                        $result,
                        JSON_UNESCAPED_SLASHES
                    ),
                    'updated_at' => $completedAt,
                ]
            );
    }

    private function failRun(
        int $runId,
        Throwable $exception
    ): void {
        $completedAt = now();

        DB::table('seo_semantic_runs')
            ->where('id', $runId)
            ->update(
                [
                    'status' => 'failed',
                    'failed_count' => 1,
                    'heartbeat_at' => $completedAt,
                    'completed_at' => $completedAt,
                    'last_error' => Str::limit(
                        $exception->getMessage(),
                        4000,
                        ''
                    ),
                    'updated_at' => $completedAt,
                ]
            );
    }

    private function markSyncStarted(
        string $mode,
        $startedAt
    ): void {
        $column =
            $mode === 'full'
                ? 'last_full_reconcile_started_at'
                : 'last_incremental_started_at';

        DB::table('seo_semantic_sync_states')
            ->where('engine_key', self::SYNC_ENGINE_KEY)
            ->update(
                [
                    $column => $startedAt,
                    'updated_at' => $startedAt,
                ]
            );
    }

    private function markSyncCompleted(
        string $mode,
        $completedAt,
        array $conceptMetadata
    ): void {
        $state = DB::table('seo_semantic_sync_states')
            ->where('engine_key', self::SYNC_ENGINE_KEY)
            ->first();

        if ($state === null) {
            throw new RuntimeException(
                'Semantic Intelligence sync state is missing.'
            );
        }

        $metadata = json_decode(
            (string) ($state->metadata ?? '{}'),
            true
        );

        if (! is_array($metadata)) {
            $metadata = [];
        }

        $metadata['concept_engine'] = self::ENGINE_VERSION;
        $metadata['taxonomy_fingerprint'] = $this->taxonomyFingerprint();
        $metadata['concept_intelligence'] = $conceptMetadata;

        $updates = [
            'last_error' => null,
            'metadata' => json_encode(
                $metadata,
                JSON_UNESCAPED_SLASHES
            ),
            'updated_at' => $completedAt,
        ];

        if ($mode === 'full') {
            $updates['engine_version'] = self::ENGINE_VERSION;
            $updates['last_full_reconcile_completed_at'] = $completedAt;
            $updates['full_rebuild_required'] = false;
            $updates['taxonomy_dirty'] = false;
            $updates['taxonomy_dirty_at'] = null;
        } else {
            $updates['last_incremental_completed_at'] = $completedAt;
        }

        DB::table('seo_semantic_sync_states')
            ->where('engine_key', self::SYNC_ENGINE_KEY)
            ->update($updates);
    }

    private function markSyncFailed(
        string $mode,
        Throwable $exception
    ): void {
        $completedAt = now();

        $updates = [
            'last_error' => Str::limit(
                $exception->getMessage(),
                4000,
                ''
            ),
            'updated_at' => $completedAt,
        ];

        if ($mode === 'full') {
            $updates['engine_version'] = self::ENGINE_VERSION;
            $updates['last_full_reconcile_completed_at'] = $completedAt;
        } else {
            $updates['last_incremental_completed_at'] = $completedAt;
        }

        DB::table('seo_semantic_sync_states')
            ->where('engine_key', self::SYNC_ENGINE_KEY)
            ->update($updates);
    }
}
