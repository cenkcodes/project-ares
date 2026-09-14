<?php

namespace App\Console\Commands;

use App\Models\Video;
use App\Services\Seo\VideoSeoContentBuilder;
use Illuminate\Console\Command;
use Throwable;

class BuildVideoSeoContents extends Command
{
    protected $signature = 'seo:build-video-contents
        {--limit=500 : Maximum videos selected per bounded selection (1-5000)}
        {--after-id=0 : Exclusive video ID cursor}
        {--video-id= : Select only this video, including inactive videos}
        {--all : Continue bounded selections until all active videos are reconciled}
        {--dry-run : Generate and count planned changes without writes}
        {--force : Regenerate even when version and fingerprint match}';

    protected $description =
        'Build persisted factual video SEO content in bounded, restartable batches';

    private const MAX_LIMIT = 5000;

    private const CHUNK_SIZE = 250;

    public function handle(
        VideoSeoContentBuilder $builder
    ): int {
        $limit =
            $this->integerOption(
                'limit',
                1,
                self::MAX_LIMIT
            );

        $afterId =
            $this->integerOption(
                'after-id',
                0,
                PHP_INT_MAX
            );

        $videoId =
            $this->option('video-id') === null
                ? null
                : $this->integerOption(
                    'video-id',
                    1,
                    PHP_INT_MAX
                );

        if (
            $limit === false
            || $afterId === false
            || $videoId === false
        ) {
            return self::FAILURE;
        }

        $all =
            (bool) $this->option(
                'all'
            );

        if (
            $all
            && $videoId !== null
        ) {
            $this->error(
                '--all cannot be combined with --video-id.'
            );

            return self::FAILURE;
        }

        $dryRun =
            (bool) $this->option(
                'dry-run'
            );

        $force =
            (bool) $this->option(
                'force'
            );

        $counts =
            array_fill_keys(
                [
                    'selected',
                    'processed',
                    'created',
                    'updated',
                    'skipped_unchanged',
                    'quality_hold',
                    'failed',
                ],
                0
            );

        $cursor =
            $afterId;

        $lastId =
            $afterId;

        $nextId =
            $afterId;

        $failedIds = [];

        $batchFailed =
            false;

        $fatalType =
            '';

        $fatalMessage =
            '';

        try {
            while (true) {
                $selectionStartId =
                    $cursor;

                $query =
                    Video::query()
                        ->where(
                            'id',
                            '>',
                            $selectionStartId
                        );

                if (
                    $videoId !== null
                ) {
                    $query->where(
                        'id',
                        $videoId
                    );
                } else {
                    $query->where(
                        'is_active',
                        true
                    );
                }

                $videos =
                    $query
                        ->orderBy(
                            'id'
                        )
                        ->limit(
                            $limit
                        )
                        ->get(
                            [
                                'id',
                                'title',
                                'duration',
                                'category_id',
                                'is_active',
                                'is_hd',
                                'is_4k',
                            ]
                        );

                if (
                    $videos->isEmpty()
                ) {
                    $nextId =
                        $cursor;

                    break;
                }

                $counts['selected'] +=
                    $videos->count();

                $selectionLastId =
                    (int) (
                        $videos->last()?->id
                        ?? $selectionStartId
                    );

                $lastId =
                    $selectionLastId;

                $selectionFailedIds = [];

                foreach (
                    $videos->chunk(
                        self::CHUNK_SIZE
                    )
                    as $chunk
                ) {
                    try {
                        $result =
                            $builder->buildBatch(
                                $chunk,
                                $dryRun,
                                $force
                            );

                        foreach (
                            $counts
                            as $name => $value
                        ) {
                            if (
                                $name !== 'selected'
                            ) {
                                $counts[$name] +=
                                    $result[$name];
                            }
                        }

                        if (
                            $result['fatal_error']
                        ) {
                            $batchFailed =
                                true;

                            $fatalType =
                                $result[
                                    'fatal_error_type'
                                ];

                            $fatalMessage =
                                $result[
                                    'fatal_error_message'
                                ];
                        }

                        foreach (
                            $result['errors']
                            as $id => $message
                        ) {
                            $failedId =
                                (int) $id;

                            $failedIds[] =
                                $failedId;

                            $selectionFailedIds[] =
                                $failedId;

                            $this->error(
                                'VIDEO_ID=' .
                                $failedId .
                                ' ERROR=' .
                                $message
                            );
                        }
                    } catch (Throwable $exception) {
                        $batchFailed =
                            true;

                        $fatalType =
                            'service_error';

                        $fatalMessage =
                            $exception->getMessage();
                    }

                    if (
                        $batchFailed
                    ) {
                        break;
                    }
                }

                if (
                    $batchFailed
                ) {
                    $nextId =
                        $selectionStartId;

                    break;
                }

                if (
                    $selectionFailedIds !== []
                ) {
                    $nextId =
                        max(
                            $selectionStartId,
                            min(
                                $selectionFailedIds
                            ) - 1
                        );

                    break;
                }

                $cursor =
                    $selectionLastId;

                $nextId =
                    $cursor;

                if (
                    !$all
                    || $videoId !== null
                ) {
                    break;
                }
            }
        } catch (Throwable $exception) {
            $batchFailed =
                true;

            $fatalType =
                'selection_error';

            $fatalMessage =
                $exception->getMessage();

            $nextId =
                $cursor;
        }

        foreach (
            $counts
            as $name => $value
        ) {
            $this->line(
                strtoupper(
                    $name
                ) .
                '=' .
                $value
            );
        }

        $failedIds =
            array_values(
                array_unique(
                    $failedIds
                )
            );

        sort(
            $failedIds,
            SORT_NUMERIC
        );

        $this->line(
            'BATCH_FATAL=' .
            ($batchFailed ? '1' : '0')
        );

        $this->line(
            'BATCH_FATAL_TYPE=' .
            $fatalType
        );

        $this->line(
            'BATCH_FATAL_MESSAGE=' .
            preg_replace(
                '/\s+/',
                ' ',
                $fatalMessage
            )
        );

        $this->line(
            'LAST_VIDEO_ID=' .
            $lastId
        );

        $this->line(
            'NEXT_AFTER_ID=' .
            $nextId
        );

        $this->line(
            'FAILED_VIDEO_IDS=' .
            implode(
                ',',
                $failedIds
            )
        );

        $this->line(
            'ALL=' .
            ($all ? 'YES' : 'NO')
        );

        $this->line(
            'DRY_RUN=' .
            ($dryRun ? 'YES' : 'NO')
        );

        $this->line(
            'FORCE=' .
            ($force ? 'YES' : 'NO')
        );

        if (
            $dryRun
        ) {
            $this->line(
                'WOULD_CREATE=' .
                $counts['created']
            );

            $this->line(
                'WOULD_UPDATE=' .
                $counts['updated']
            );

            $this->line(
                'WOULD_SKIP=' .
                $counts['skipped_unchanged']
            );
        }

        return
            $batchFailed
            || $counts['failed'] > 0
                ? self::FAILURE
                : self::SUCCESS;
    }

    private function integerOption(
        string $name,
        int $minimum,
        int $maximum
    ): int|false {
        $value =
            filter_var(
                $this->option(
                    $name
                ),
                FILTER_VALIDATE_INT,
                [
                    'options' =>
                        [
                            'min_range' =>
                                $minimum,

                            'max_range' =>
                                $maximum,
                        ],
                ]
            );

        if (
            $value === false
        ) {
            $this->error(
                '--' .
                $name .
                ' must be an integer between ' .
                $minimum .
                ' and ' .
                $maximum .
                '.'
            );
        }

        return $value;
    }
}
