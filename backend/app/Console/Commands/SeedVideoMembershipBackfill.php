<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class SeedVideoMembershipBackfill extends Command
{
    protected $signature =
        'seo:seed-video-membership-backfill
        {--limit=5000 : Maximum active videos to enqueue in this batch}';

    protected $description =
        'Seed existing active videos into the provider-independent semantic membership queue.';

    private const BACKFILL_METADATA_KEY =
        'video_membership_backfill';

    public function handle(): int
    {
        $limit =
            $this->parsePositiveIntegerOption(
                'limit',
                5000
            );

        if ($limit === null) {
            return self::FAILURE;
        }

        $limit =
            max(
                1,
                min(
                    $limit,
                    10000
                )
            );

        $this->newLine();

        $this->info(
            'Semantic Intelligence: video membership backfill seeder'
        );

        $this->line(
            'LIMIT=' .
            $limit
        );

        try {
            $result =
                DB::transaction(
                    function () use ($limit): array {
                        $state =
                            DB::table(
                                'seo_semantic_sync_states'
                            )
                                ->where(
                                    'engine_key',
                                    'semantic-intelligence'
                                )
                                ->lockForUpdate()
                                ->first();

                        if ($state === null) {
                            throw new RuntimeException(
                                'Semantic sync state not found.'
                            );
                        }

                        $metadata =
                            $this->decodeMetadata(
                                $state->metadata
                            );

                        $backfillState =
                            $metadata[
                                self::BACKFILL_METADATA_KEY
                            ] ?? [];

                        $cursor =
                            max(
                                0,
                                (int) (
                                    $backfillState[
                                        'seed_cursor'
                                    ] ?? 0
                                )
                            );

                        $videoIds =
                            DB::table(
                                'videos'
                            )
                                ->where(
                                    'is_active',
                                    true
                                )
                                ->where(
                                    'id',
                                    '>',
                                    $cursor
                                )
                                ->orderBy(
                                    'id'
                                )
                                ->limit(
                                    $limit
                                )
                                ->pluck(
                                    'id'
                                )
                                ->map(
                                    static fn ($id) =>
                                        (int) $id
                                )
                                ->values()
                                ->all();

                        if ($videoIds === []) {
                            $remaining =
                                (int) DB::table(
                                    'videos'
                                )
                                    ->where(
                                        'is_active',
                                        true
                                    )
                                    ->where(
                                        'id',
                                        '>',
                                        $cursor
                                    )
                                    ->count();

                            $metadata[
                                self::BACKFILL_METADATA_KEY
                            ] = [
                                'seed_cursor' =>
                                    $cursor,

                                'seed_complete' =>
                                    true,

                                'seed_completed_at' =>
                                    now()->toDateTimeString(),

                                'seed_updated_at' =>
                                    now()->toDateTimeString(),
                            ];

                            DB::table(
                                'seo_semantic_sync_states'
                            )
                                ->where(
                                    'engine_key',
                                    'semantic-intelligence'
                                )
                                ->update(
                                    [
                                        'metadata' =>
                                            json_encode(
                                                $metadata,
                                                JSON_THROW_ON_ERROR
                                                | JSON_UNESCAPED_SLASHES
                                                | JSON_UNESCAPED_UNICODE
                                            ),

                                        'updated_at' =>
                                            now(),
                                    ]
                                );

                            return [
                                'cursor_before' =>
                                    $cursor,

                                'cursor_after' =>
                                    $cursor,

                                'selected_count' =>
                                    0,

                                'inserted_count' =>
                                    0,

                                'remaining_count' =>
                                    $remaining,

                                'backfill_complete' =>
                                    true,
                            ];
                        }

                        $nextCursor =
                            max(
                                $videoIds
                            );

                        /*
                         * Set-based enqueue.
                         *
                         * Existing dirty rows are deliberately preserved:
                         * a real provider/admin/source-term change contains
                         * fresher information than this baseline seed.
                         *
                         * The membership worker always rebuilds the complete
                         * current category and term snapshots for the video.
                         */
                        $insertedCount =
                            DB::affectingStatement(
                                <<<'SQL'
INSERT INTO seo_catalog_dirty_videos (
    video_id,
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
SELECT
    v.id,
    3,
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
FROM videos AS v
WHERE
    v.is_active = TRUE
    AND v.id > ?
    AND v.id <= ?
ORDER BY
    v.id
ON CONFLICT (video_id)
DO NOTHING
SQL,
                                [
                                    $cursor,
                                    $nextCursor,
                                ]
                            );

                        $remaining =
                            (int) DB::table(
                                'videos'
                            )
                                ->where(
                                    'is_active',
                                    true
                                )
                                ->where(
                                    'id',
                                    '>',
                                    $nextCursor
                                )
                                ->count();

                        /*
                         * IMPORTANT:
                         *
                         * Backfill seed progress lives only inside metadata.
                         *
                         * seo_semantic_sync_states.last_video_id remains
                         * untouched and therefore remains available for the
                         * canonical incremental watermark/reconciliation
                         * mechanism.
                         */
                        $metadata[
                            self::BACKFILL_METADATA_KEY
                        ] = [
                            'seed_cursor' =>
                                $nextCursor,

                            'seed_complete' =>
                                $remaining === 0,

                            'seed_completed_at' =>
                                $remaining === 0
                                    ? now()->toDateTimeString()
                                    : (
                                        $backfillState[
                                            'seed_completed_at'
                                        ] ?? null
                                    ),

                            'seed_updated_at' =>
                                now()->toDateTimeString(),
                        ];

                        DB::table(
                            'seo_semantic_sync_states'
                        )
                            ->where(
                                'engine_key',
                                'semantic-intelligence'
                            )
                            ->update(
                                [
                                    'metadata' =>
                                        json_encode(
                                            $metadata,
                                            JSON_THROW_ON_ERROR
                                            | JSON_UNESCAPED_SLASHES
                                            | JSON_UNESCAPED_UNICODE
                                        ),

                                    'updated_at' =>
                                        now(),
                                ]
                            );

                        return [
                            'cursor_before' =>
                                $cursor,

                            'cursor_after' =>
                                $nextCursor,

                            'selected_count' =>
                                count(
                                    $videoIds
                                ),

                            'inserted_count' =>
                                $insertedCount,

                            'remaining_count' =>
                                $remaining,

                            'backfill_complete' =>
                                $remaining === 0,
                        ];
                    },
                    3
                );
        } catch (Throwable $exception) {
            $this->error(
                'VIDEO_MEMBERSHIP_BACKFILL_SEED=FAIL'
            );

            $this->error(
                'ERROR=' .
                $exception->getMessage()
            );

            return self::FAILURE;
        }

        $this->line(
            'CURSOR_BEFORE=' .
            $result['cursor_before']
        );

        $this->line(
            'CURSOR_AFTER=' .
            $result['cursor_after']
        );

        $this->line(
            'SELECTED_COUNT=' .
            $result['selected_count']
        );

        $this->line(
            'INSERTED_COUNT=' .
            $result['inserted_count']
        );

        $this->line(
            'REMAINING_ACTIVE_AFTER_CURSOR=' .
            $result['remaining_count']
        );

        $this->line(
            'BACKFILL_COMPLETE=' .
            (
                $result['backfill_complete']
                    ? 'YES'
                    : 'NO'
            )
        );

        $this->newLine();

        $this->info(
            'VIDEO_MEMBERSHIP_BACKFILL_SEED=PASS'
        );

        return self::SUCCESS;
    }

    private function decodeMetadata(
        mixed $rawMetadata
    ): array {
        if (
            $rawMetadata === null
            || $rawMetadata === ''
        ) {
            return [];
        }

        if (is_array($rawMetadata)) {
            return $rawMetadata;
        }

        if (is_object($rawMetadata)) {
            return (array) $rawMetadata;
        }

        if (!is_string($rawMetadata)) {
            throw new RuntimeException(
                'Semantic sync metadata has an unsupported format.'
            );
        }

        $decoded =
            json_decode(
                $rawMetadata,
                true,
                512,
                JSON_THROW_ON_ERROR
            );

        if (!is_array($decoded)) {
            throw new RuntimeException(
                'Semantic sync metadata must decode to an object.'
            );
        }

        return $decoded;
    }

    private function parsePositiveIntegerOption(
        string $name,
        int $default
    ): ?int {
        $rawValue =
            $this->option(
                $name
            );

        if (
            $rawValue === null
            || $rawValue === ''
        ) {
            return $default;
        }

        $value =
            filter_var(
                $rawValue,
                FILTER_VALIDATE_INT,
                [
                    'options' =>
                        [
                            'min_range' =>
                                1,
                        ],
                ]
            );

        if ($value === false) {
            $this->error(
                strtoupper($name) .
                '_OPTION_INVALID=' .
                (string) $rawValue
            );

            return null;
        }

        return (int) $value;
    }
}
