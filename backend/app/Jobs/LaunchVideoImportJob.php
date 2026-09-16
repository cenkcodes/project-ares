<?php

namespace App\Jobs;

use App\Models\VideoImportJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

class LaunchVideoImportJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 20;

    public bool $failOnTimeout = true;

    public function __construct(
        public int $videoImportJobId,
    ) {
        $this->onConnection('database');
        $this->onQueue('default');
    }

    public function handle(): void
    {
        $job = VideoImportJob::query()->find($this->videoImportJobId);

        if ($job === null) {
            return;
        }

        if (! in_array($job->status, [
            VideoImportJob::STATUS_QUEUED,
            VideoImportJob::STATUS_LAUNCHING,
        ], true)) {
            return;
        }

        try {
            $job->update([
                'status' => VideoImportJob::STATUS_LAUNCHING,
                'stage' => 'launching',
                'message' => 'Starting detached import process.',
            ]);

            $launchLogDirectory = storage_path('logs/video-import-center');
            File::ensureDirectoryExists($launchLogDirectory);

            $launchLog = $launchLogDirectory
                . '/launch-job-' . $job->id . '.log';

            $phpBinary = PHP_BINARY ?: '/usr/bin/php';
            $runner = base_path('scripts/video-import-center-runner.php');

            if (! is_file($runner)) {
                throw new RuntimeException(
                    'Video Import Center runner is missing: ' . $runner
                );
            }

            $shellCommand = sprintf(
                'nohup %s %s %d >> %s 2>&1 < /dev/null & echo $!',
                escapeshellarg($phpBinary),
                escapeshellarg($runner),
                $job->id,
                escapeshellarg($launchLog),
            );

            $process = new Process([
                '/bin/bash',
                '-lc',
                $shellCommand,
            ], base_path());

            $process->setTimeout(10);
            $process->mustRun();

            $pid = (int) trim($process->getOutput());

            if ($pid < 1) {
                throw new RuntimeException(
                    'Detached process did not return a valid PID.'
                );
            }

            $job->update([
                'process_id' => $pid,
                'message' => 'Detached import process started.',
            ]);
        } catch (Throwable $exception) {
            $job->update([
                'status' => VideoImportJob::STATUS_FAIL,
                'stage' => 'launch-failed',
                'exit_code' => 1,
                'message' => $exception->getMessage(),
                'finished_at' => now(),
            ]);

            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        $job = VideoImportJob::query()->find($this->videoImportJobId);

        if ($job === null || ! $job->isActive()) {
            return;
        }

        $job->update([
            'status' => VideoImportJob::STATUS_FAIL,
            'stage' => 'launcher-failed',
            'exit_code' => 1,
            'message' => $exception?->getMessage()
                ?: 'Launcher queue job failed.',
            'finished_at' => now(),
        ]);
    }
}
