<?php

namespace App\Console\Commands;

use App\Services\Seo\IncrementalVideoMembershipWorker;
use Illuminate\Console\Command;
use Throwable;

class ProcessIncrementalVideoMemberships extends Command
{
    /**
     * The name and signature of the console command.
     *
     * One invocation processes one bounded queue batch.
     *
     * A scheduler or supervisor can invoke the command repeatedly later.
     * We intentionally do not create an endless loop inside the command.
     */
    protected $signature =
        'seo:process-video-memberships
        {--limit=100 : Maximum number of dirty videos to claim in this run}
        {--lease=900 : Processing lease timeout in seconds}
        {--settle=0 : Minimum quiet seconds since the last dirty update}';

    /**
     * The console command description.
     */
    protected $description =
        'Process provider-independent incremental semantic video memberships.';

    /**
     * Execute the console command.
     */
    public function handle(
        IncrementalVideoMembershipWorker $worker
    ): int {
        $limit =
            $this->parsePositiveIntegerOption(
                'limit',
                100
            );

        if ($limit === null) {
            return self::FAILURE;
        }

        $leaseSeconds =
            $this->parsePositiveIntegerOption(
                'lease',
                900
            );

        if ($leaseSeconds === null) {
            return self::FAILURE;
        }

        $settleSeconds =
            $this->parseNonNegativeIntegerOption(
                'settle',
                0
            );

        if ($settleSeconds === null) {
            return self::FAILURE;
        }

        /*
         * Keep command-side values inside the same operational limits enforced
         * by the worker. This makes CLI output accurately describe the actual
         * requested work.
         */
        $limit =
            max(
                1,
                min(
                    $limit,
                    1000
                )
            );

        $leaseSeconds =
            max(
                60,
                min(
                    $leaseSeconds,
                    86400
                )
            );

        $settleSeconds =
            max(
                0,
                min(
                    $settleSeconds,
                    3600
                )
            );

        $this->newLine();

        $this->info(
            'Semantic Intelligence: incremental video membership worker'
        );

        $this->line(
            'ENGINE_STAGE=' .
            IncrementalVideoMembershipWorker::ENGINE_STAGE
        );

        $this->line(
            'ENGINE_VERSION=' .
            IncrementalVideoMembershipWorker::ENGINE_VERSION
        );

        $this->line(
            'SEO_ENRICHMENT_VERSION=' .
            IncrementalVideoMembershipWorker::SEO_ENRICHMENT_VERSION
        );

        $this->line(
            'LIMIT=' .
            $limit
        );

        $this->line(
            'LEASE_SECONDS=' .
            $leaseSeconds
        );

        $this->line(
            'SETTLE_SECONDS=' .
            $settleSeconds
        );

        $this->newLine();

        try {
            $result =
                $worker->processBatch(
                    $limit,
                    $leaseSeconds,
                    $settleSeconds
                );
        } catch (Throwable $exception) {
            $this->error(
                'VIDEO_MEMBERSHIP_WORKER=FAIL'
            );

            $this->error(
                'ERROR=' .
                $exception->getMessage()
            );

            return self::FAILURE;
        }

        $this->line(
            'RUN_UUID=' .
            $result['run_uuid']
        );

        $this->line(
            'CLAIMED_COUNT=' .
            $result['claimed_count']
        );

        $this->line(
            'PROCESSED_COUNT=' .
            $result['processed_count']
        );

        $this->line(
            'CHANGED_COUNT=' .
            $result['changed_count']
        );

        $this->line(
            'SKIPPED_COUNT=' .
            $result['skipped_count']
        );

        $this->line(
            'FAILED_COUNT=' .
            $result['failed_count']
        );

        $this->line(
            'REQUEUED_COUNT=' .
            $result['requeued_count']
        );

        $this->line(
            'DIRTY_CATEGORY_COUNT=' .
            $result['dirty_category_count']
        );

        $this->line(
            'DIRTY_TERM_COUNT=' .
            $result['dirty_term_count']
        );

        $this->line(
            'SEO_CANDIDATE_COUNT=' .
            $result['seo_candidate_count']
        );

        $this->line(
            'SEO_READY_COUNT=' .
            $result['seo_ready_count']
        );

        $this->line(
            'SEO_SELECTED=' .
            $result['seo_selected']
        );

        $this->line(
            'SEO_PROCESSED=' .
            $result['seo_processed']
        );

        $this->line(
            'SEO_CREATED=' .
            $result['seo_created']
        );

        $this->line(
            'SEO_UPDATED=' .
            $result['seo_updated']
        );

        $this->line(
            'SEO_SKIPPED_UNCHANGED=' .
            $result['seo_skipped_unchanged']
        );

        $this->line(
            'SEO_QUALITY_HOLD=' .
            $result['seo_quality_hold']
        );

        $this->line(
            'SEO_FAILED=' .
            $result['seo_failed']
        );

        $this->line(
            'SEO_FATAL_ERROR=' .
            ($result['seo_fatal_error'] ? '1' : '0')
        );

        $this->line(
            'SEO_FATAL_ERROR_TYPE=' .
            $result['seo_fatal_error_type']
        );

        $this->line(
            'SEO_FATAL_ERROR_MESSAGE=' .
            preg_replace(
                '/\s+/',
                ' ',
                $result['seo_fatal_error_message']
            )
        );

        $this->line(
            'SEO_DEFERRED=' .
            ($result['seo_deferred'] ? '1' : '0')
        );

        $this->newLine();

        /*
         * A run containing even one failed video must return a non-zero shell
         * exit code.
         *
         * This is important when the command is later executed by cron,
         * systemd, a scheduler or deployment automation. Operational failures
         * must never look successful merely because other rows passed.
         */
        $seoTechnicalFailure =
            !$result['seo_deferred']
            && (
                (bool) $result['seo_fatal_error']
                || (int) $result['seo_failed'] > 0
            );

        if (
            (int) $result['failed_count'] > 0
            || $seoTechnicalFailure
        ) {
            $this->error(
                'VIDEO_MEMBERSHIP_WORKER=COMPLETED_WITH_ERRORS'
            );

            return self::FAILURE;
        }

        if (
            $result['seo_deferred']
        ) {
            $this->warn(
                'VIDEO_SEO_ENRICHMENT=DEFERRED_LOCK_UNAVAILABLE'
            );
        } else {
            $this->info(
                'VIDEO_SEO_ENRICHMENT=PASS'
            );
        }

        $this->info(
            'VIDEO_MEMBERSHIP_WORKER=PASS'
        );

        return self::SUCCESS;
    }

    /**
     * Parse a required positive integer CLI option.
     *
     * Returning null rather than silently accepting malformed input protects
     * scheduled production runs from accidental values such as:
     *
     *     --limit=abc
     *     --lease=-10
     */
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

        if (
            $value === false
        ) {
            $this->error(
                strtoupper($name) .
                '_OPTION_INVALID=' .
                (string) $rawValue
            );

            return null;
        }

        return (int) $value;
    }
    /**
     * Parse an integer option that may be zero.
     */
    private function parseNonNegativeIntegerOption(
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
                                0,
                        ],
                ]
            );

        if (
            $value === false
        ) {
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
