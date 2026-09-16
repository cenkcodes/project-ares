<?php

namespace App\Services;

use App\Models\VideoImportJob;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

class VideoImportRunner
{
    private const PROVIDER_BATCH_SCRIPTS = [
        'xvideos' => '/var/www/project-ares/ops/video-import/xvideos-batch.php',
        'eporner' => '/var/www/project-ares/ops/video-import/eporner-batch.php',
    ];

    private const PROVIDER_BATCH_SHA256 = [
        'xvideos' => '876b668171dd1744a7887390121edbe32522673d6cebefef6d1ebd8fa201d3a7',
        'eporner' => '6af038866edf286657a5eae5049ba275b31c82984f183573b53acd12a7f8c72c',
    ];

    public static function supportedSources(): array
    {
        return array_keys(self::PROVIDER_BATCH_SCRIPTS);
    }

    public function selfTest(): int
    {
        echo 'RUNNER_BOOTSTRAP=PASS' . PHP_EOL;
        echo 'VIDEO_IMPORT_JOBS_TABLE='
            . (Schema::hasTable('video_import_jobs') ? 'YES' : 'NO')
            . PHP_EOL;

        if (! Schema::hasTable('video_import_jobs')) {
            echo 'ERROR=video_import_jobs table is missing.' . PHP_EOL;

            return 1;
        }

        foreach (self::PROVIDER_BATCH_SCRIPTS as $source => $script) {
            $label = strtoupper($source);

            if (! is_file($script)) {
                echo 'ERROR=' . $label . ' batch script is missing.' . PHP_EOL;

                return 1;
            }

            $sha = hash_file('sha256', $script);
            echo $label . '_BATCH_SHA=' . $sha . PHP_EOL;

            $expectedSha = self::PROVIDER_BATCH_SHA256[$source] ?? null;

            if ($expectedSha === null || $sha !== $expectedSha) {
                echo 'ERROR=Protected ' . $label
                    . ' batch hash changed.' . PHP_EOL;

                return 1;
            }
        }

        echo 'SUPPORTED_VIDEO_IMPORT_SOURCES='
            . implode(',', self::supportedSources())
            . PHP_EOL;

        $logDirectory = storage_path('logs/video-import-center');
        File::ensureDirectoryExists($logDirectory);

        if (! is_writable($logDirectory)) {
            echo 'ERROR=Video Import Center log directory is not writable.'
                . PHP_EOL;

            return 1;
        }

        echo 'VIDEO_IMPORT_CENTER_LOG_DIR=WRITABLE' . PHP_EOL;
        echo 'RUNNER_SELF_TEST=PASS' . PHP_EOL;

        return 0;
    }

    public function run(int $jobId): int
    {
        $job = VideoImportJob::query()->find($jobId);

        if ($job === null) {
            fwrite(STDERR, 'Video import job not found: ' . $jobId . PHP_EOL);

            return 1;
        }

        if (! in_array($job->status, [
            VideoImportJob::STATUS_QUEUED,
            VideoImportJob::STATUS_LAUNCHING,
        ], true)) {
            fwrite(
                STDERR,
                'Video import job is not launchable. Status='
                . $job->status . PHP_EOL
            );

            return 1;
        }

        $logDirectory = storage_path('logs/video-import-center');
        File::ensureDirectoryExists($logDirectory);

        $logPath = $logDirectory . '/job-' . $job->id . '.log';
        File::put($logPath, '');

        $job->update([
            'status' => VideoImportJob::STATUS_RUNNING,
            'stage' => 'starting',
            'progress_current' => 0,
            'progress_total' => $job->target_count,
            'log_path' => $logPath,
            'message' => 'Import process is running.',
            'started_at' => now(),
            'finished_at' => null,
            'exit_code' => null,
        ]);

        try {
            $script = self::PROVIDER_BATCH_SCRIPTS[$job->source] ?? null;

            if ($script === null) {
                throw new RuntimeException(
                    'Unsupported video provider: ' . $job->source
                );
            }

            if (! is_file($script)) {
                throw new RuntimeException(
                    'Provider batch script not found: ' . $script
                );
            }

            $expectedSha = self::PROVIDER_BATCH_SHA256[$job->source] ?? null;

            if ($expectedSha === null) {
                throw new RuntimeException(
                    'Protected batch hash is not configured for provider: '
                    . $job->source
                );
            }

            if (hash_file('sha256', $script) !== $expectedSha) {
                throw new RuntimeException(
                    'Protected ' . strtoupper($job->source)
                    . ' batch hash changed. Import refused.'
                );
            }

            $modeArgument = $job->mode === 'prepare-only'
                ? '--prepare-only'
                : '--import';

            $command = [
                PHP_BINARY ?: '/usr/bin/php',
                $script,
                $job->category_slug,
                (string) $job->minimum_count,
                $modeArgument,
                '--target=' . $job->target_count,
                '--max-pages=' . $job->max_pages,
            ];

            $this->appendLog(
                $logPath,
                'VIDEO_IMPORT_CENTER_JOB_ID=' . $job->id . PHP_EOL
                . 'VIDEO_IMPORT_CENTER_COMMAND=' . implode(
                    ' ',
                    array_map('escapeshellarg', $command)
                ) . PHP_EOL
            );

            $process = new Process(
                $command,
                '/var/www/project-ares/backend'
            );

            $process->setTimeout(null);
            $process->setIdleTimeout(null);

            $buffer = '';

            $process->run(
                function (string $type, string $chunk) use (
                    $job,
                    $logPath,
                    &$buffer,
                ): void {
                    $this->appendLog($logPath, $chunk);
                    fwrite(STDOUT, $chunk);

                    $buffer .= $chunk;

                    while (($position = strpos($buffer, "\n")) !== false) {
                        $line = substr($buffer, 0, $position);
                        $buffer = substr($buffer, $position + 1);
                        $this->consumeProgressLine($job, trim($line));
                    }
                }
            );

            if ($buffer !== '') {
                $this->consumeProgressLine($job, trim($buffer));
            }

            $exitCode = $process->getExitCode() ?? 1;
            $logContents = File::exists($logPath)
                ? File::get($logPath)
                : '';

            $expectedMarker = $job->mode === 'prepare-only'
                ? 'BATCH_PREPARATION=PASS'
                : 'AUTO_IMPORT=PASS';

            $successful = $exitCode === 0
                && str_contains($logContents, $expectedMarker);

            $summary = $this->buildResultSummary($logContents);

            if (! $successful) {
                $job->update([
                    'status' => VideoImportJob::STATUS_FAIL,
                    'stage' => 'failed',
                    'exit_code' => $exitCode,
                    'message' => $this->failureMessage(
                        $logContents,
                        $exitCode,
                    ),
                    'result_summary' => $summary,
                    'finished_at' => now(),
                ]);

                return 1;
            }

            $completedCount = (int) (
                $summary['ACTUAL_IMPORTED']
                ?? $summary['ACTUAL_ACCEPTED']
                ?? $summary['ROWS_ACCEPTED']
                ?? $job->progress_current
            );

            if ($completedCount < 1) {
                $completedCount = $job->target_count;
            }

            $job->update([
                'status' => VideoImportJob::STATUS_PASS,
                'stage' => 'complete',
                'progress_current' => $completedCount,
                'progress_total' => $completedCount,
                'exit_code' => 0,
                'message' => $job->mode === 'prepare-only'
                    ? 'Prepare-only job completed successfully.'
                    : 'Import completed successfully.',
                'result_summary' => $summary,
                'finished_at' => now(),
            ]);

            return 0;
        } catch (Throwable $exception) {
            $this->appendLog(
                $logPath,
                PHP_EOL . 'VIDEO_IMPORT_CENTER_EXCEPTION='
                . $exception->getMessage() . PHP_EOL
            );

            $job->update([
                'status' => VideoImportJob::STATUS_FAIL,
                'stage' => 'exception',
                'exit_code' => 1,
                'message' => $exception->getMessage(),
                'finished_at' => now(),
            ]);

            report($exception);

            return 1;
        }
    }

    private function consumeProgressLine(
        VideoImportJob $job,
        string $line,
    ): void {
        if ($line === '') {
            return;
        }

        $stage = null;
        $current = null;
        $total = null;

        if (str_contains($line, '===== COLLECT CANDIDATES =====')) {
            $stage = 'collecting';
        } elseif (str_contains($line, '===== BUILD CANONICAL CSV =====')) {
            $stage = 'building';
        } elseif (str_contains($line, '===== BATCH STRUCTURE VALIDATION =====')) {
            $stage = 'validating-batch';
        } elseif (str_contains($line, '===== VIDEOIMPORTER TRANSACTIONAL SMOKE =====')) {
            $stage = 'smoke-test';
        } elseif (str_contains($line, '===== REAL VIDEO IMPORT VIA EXISTING VIDEOIMPORTER =====')) {
            $stage = 'importing-videos';
        } elseif (str_contains($line, '===== SOURCE-TERM DRY RUN =====')) {
            $stage = 'source-term-dry-run';
        } elseif (str_contains($line, '===== SOURCE-TERM REAL IMPORT =====')) {
            $stage = 'importing-source-terms';
        } elseif (str_contains($line, '===== FINAL DB INTEGRITY CHECK =====')) {
            $stage = 'final-integrity';
        }

        if (preg_match('/^ROWS_ACCEPTED=(\d+)$/', $line, $matches) === 1) {
            $stage ??= 'building';
            $total = (int) $matches[1];
        }

        if (preg_match('/\bTOTAL=(\d+)\b/', $line, $matches) === 1) {
            $stage ??= 'collecting';
            $current = (int) $matches[1];
            $total = $job->target_count;
        }

        if (preg_match('/^\[(\d+)\/(\d+)\]\s+/', $line, $matches) === 1) {
            $stage = 'building';
            $current = (int) $matches[1];
            $total = (int) $matches[2];
        }

        if (
            preg_match(
                '/^VIDEO_IMPORT_PROGRESS=(\d+)\/(\d+)$/',
                $line,
                $matches,
            ) === 1
        ) {
            $stage = 'importing-videos';
            $current = (int) $matches[1];
            $total = (int) $matches[2];
        }

        $updates = [];

        if ($stage !== null && $stage !== $job->stage) {
            $updates['stage'] = $stage;
            $job->stage = $stage;
        }

        if ($current !== null) {
            $updates['progress_current'] = $current;
            $job->progress_current = $current;
        }

        if ($total !== null && $total > 0) {
            $updates['progress_total'] = $total;
            $job->progress_total = $total;
        }

        if ($updates !== []) {
            $job->forceFill($updates)->save();
        }
    }

    private function buildResultSummary(string $logContents): array
    {
        $keys = [
            'ROWS_ACCEPTED',
            'ROWS_REJECTED',
            'SOURCE_TERMS_COUNT',
            'ACTUAL_ACCEPTED',
            'ACTUAL_IMPORTED',
            'TOTAL_VIDEOS_BEFORE',
            'TOTAL_VIDEOS_AFTER',
            'CATEGORY_VIDEOS_BEFORE',
            'CATEGORY_VIDEOS_AFTER',
            'SOURCE_TERMS_BEFORE',
            'SOURCE_TERMS_AFTER',
            'PRIMARY_ALIAS_MATCH_VIDEOS',
            'MULTI_CATEGORY_MATCH_VIDEOS',
            'NO_ALIAS_MATCH_VIDEOS',
            'NORMALIZATION_MISMATCHES',
            'MISSING_VIDEOS',
            'SOURCE_MISMATCHES',
            'ORPHAN_SOURCE_TERMS',
        ];

        $summary = [];

        foreach ($keys as $key) {
            if (
                preg_match_all(
                    '/^' . preg_quote($key, '/') . '=(.+)$/m',
                    $logContents,
                    $matches,
                ) > 0
            ) {
                $value = trim((string) end($matches[1]));
                $summary[$key] = ctype_digit($value)
                    ? (int) $value
                    : $value;
            }
        }

        return $summary;
    }

    private function failureMessage(
        string $logContents,
        int $exitCode,
    ): string {
        if (
            preg_match_all('/^ERROR=(.+)$/m', $logContents, $matches) > 0
        ) {
            return trim((string) end($matches[1]));
        }

        if (
            preg_match(
                '/Another [^\r\n]+ batch process is already running\./i',
                $logContents,
                $matches,
            ) === 1
        ) {
            return trim($matches[0]);
        }

        if (
            preg_match_all(
                '/^VIDEO_IMPORT_CENTER_EXCEPTION=(.+)$/m',
                $logContents,
                $matches,
            ) > 0
        ) {
            return trim((string) end($matches[1]));
        }

        return 'Import process failed with exit code ' . $exitCode . '.';
    }

    private function appendLog(string $path, string $contents): void
    {
        File::append($path, $contents);
    }
}
