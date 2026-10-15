<?php

namespace App\Services\VideoHealth;

use App\Models\VideoHealthRun;
use DomainException;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessStartFailedException;
use Symfony\Component\Process\Process;
use Throwable;

class VideoHealthLauncher
{
    public function __construct(private ?string $phpBinary = null)
    {
    }

    /** @return array{launched: bool, process_id: ?int, log_path: string} */
    public function launch(int $runId): array
    {
        $this->eligibleRun($runId);
        try {
            $root = $this->launcherDirectory();
            $this->ensureDirectory($root);
            $guard = $this->openPrivateFile($root.DIRECTORY_SEPARATOR.'run-'.$runId.'.lock');
        } catch (Throwable $exception) {
            throw new VideoHealthLaunchException(VideoHealthLaunchException::RECONCILIATION_REQUIRED, 'Cannot safely access launch guard; operator reconciliation is required.', $exception);
        }
        try {
            if (!flock($guard, LOCK_EX | LOCK_NB)) {
                throw new VideoHealthLaunchException(VideoHealthLaunchException::RECONCILIATION_REQUIRED, 'A launch attempt is already in progress.');
            }
            // Recheck after acquiring the short, per-run guard. Never change status.
            $run = $this->eligibleRun($runId);
            $state = stream_get_contents($guard);
            if ($state === false) {
                throw new RuntimeException('Cannot read launch reservation.');
            }
            if ($state !== '') {
                throw new VideoHealthLaunchException(VideoHealthLaunchException::RECONCILIATION_REQUIRED, 'A child may already have been launched; review the private log and run before retrying.');
            }
            $logPath = $root.DIRECTORY_SEPARATOR.'run-'.$runId.'.log';
            $log = $this->openPrivateFile($logPath);
            fclose($log);
            $arguments = [$this->resolvedPhpBinary(), base_path('artisan'), 'video-health:run', (string) $runId];
            // Persist intent before spawning. An interrupted/uncertain attempt must not retry blindly.
            try {
                $this->reserve($guard);
            } catch (Throwable $reservationFailure) {
                // No spawn has been attempted. Clean up before releasing LOCK_EX.
                try {
                    $this->releaseReservation($guard);
                } catch (Throwable $cleanupFailure) {
                    // Truncation may have succeeded before cleanup flushing failed.
                    // Restore a blocking marker where possible; never retry here.
                    @rewind($guard);
                    @fwrite($guard, "launch-attempt\n");
                    @fflush($guard);
                    if (function_exists('fsync')) {
                        @fsync($guard);
                    }
                    throw new VideoHealthLaunchException(VideoHealthLaunchException::RECONCILIATION_REQUIRED, 'No child was launched; reservation cleanup failed. Operator guard recovery/reconciliation is required.', $cleanupFailure);
                }
                throw new VideoHealthLaunchException(VideoHealthLaunchException::CONFIRMED_NOT_LAUNCHED, 'Cannot persist launch reservation; no child was launched.', $reservationFailure);
            }
            try {
                $result = $this->spawn($arguments, base_path(), $logPath);
            } catch (Throwable $exception) {
                throw new VideoHealthLaunchException(VideoHealthLaunchException::RECONCILIATION_REQUIRED, 'Launch outcome is uncertain; a child may have started. Review the run and private log before retrying.', $exception);
            }
            if (($result['started'] ?? null) === false) {
                try {
                    $this->releaseReservation($guard);
                } catch (Throwable $exception) {
                    // Cleanup must not leave a retryable marker after a durability failure.
                    @rewind($guard);
                    @fwrite($guard, "launch-attempt\n");
                    @fflush($guard);
                    if (function_exists('fsync')) {
                        @fsync($guard);
                    }
                    throw new VideoHealthLaunchException(VideoHealthLaunchException::RECONCILIATION_REQUIRED, 'Process did not start, but reservation cleanup failed; operator review is required.', $exception);
                }
                throw new VideoHealthLaunchException(VideoHealthLaunchException::CONFIRMED_NOT_LAUNCHED, 'Detached process creation failed; the queued run may be retried.');
            }
            if (($result['started'] ?? null) !== true) {
                throw new VideoHealthLaunchException(VideoHealthLaunchException::RECONCILIATION_REQUIRED, 'Launch outcome is uncertain; operator review is required before retrying.');
            }
            $pid = $result['pid'] ?? null;
            if (!is_int($pid) || $pid < 1) {
                $pid = null;
            }
            try {
                // Update only launcher metadata, even if the child has already claimed/completed.
                $attributes = ['log_path' => $logPath];
                if ($pid !== null) {
                    $attributes['process_id'] = $pid;
                }
                if (!$run->update($attributes)) {
                    throw new RuntimeException('Cannot persist launcher metadata.');
                }
            } catch (Throwable $exception) {
                throw new VideoHealthLaunchException(VideoHealthLaunchException::RECONCILIATION_REQUIRED, 'Child launched, but launcher metadata could not be persisted; operator reconciliation is required. Do not launch another child.', $exception);
            }

            return ['launched' => true, 'process_id' => $pid, 'log_path' => $logPath];
        } catch (VideoHealthLaunchException | DomainException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new VideoHealthLaunchException(VideoHealthLaunchException::RECONCILIATION_REQUIRED, 'Launch guard or process state could not be verified; operator reconciliation is required.', $exception);
        } finally {
            flock($guard, LOCK_UN);
            fclose($guard);
        }
    }

    private function resolvedPhpBinary(): string
    {
        foreach ([$this->phpBinary, config('video-health.launcher.php_binary'), PHP_BINARY] as $value) {
            if ($value === null) {
                continue;
            }
            if (!is_string($value)) {
                throw new RuntimeException('Launcher PHP binary must be a string.');
            }
            $binary = trim($value);
            if ($binary === '') {
                continue;
            }
            if (preg_match('/[\x00-\x1F\x7F]/', $binary)) {
                throw new RuntimeException('Launcher PHP binary contains invalid characters.');
            }

            // One executable argv value, never a command or shell expression.
            return $binary;
        }

        throw new RuntimeException('Launcher PHP binary is unavailable.');
    }

    private function eligibleRun(int $runId): VideoHealthRun
    {
        if ($runId < 1) {
            throw new DomainException('Run ID must be positive.');
        }
        $run = VideoHealthRun::query()->useWritePdo()->find($runId);
        if ($run === null || $run->status !== VideoHealthRun::STATUS_QUEUED
            || !in_array($run->mode, [VideoHealthRun::MODE_AUDIT_ONLY, VideoHealthRun::MODE_AUDIT_DEACTIVATE], true)) {
            throw new DomainException('Only an existing queued video health run may be launched.');
        }
        if ($run->mode === VideoHealthRun::MODE_AUDIT_DEACTIVATE
            && ($run->trigger !== VideoHealthRun::TRIGGER_MANUAL || $run->confirmed_at === null)) {
            throw new DomainException('Audit-deactivate requires manual execution and confirmation.');
        }

        return $run;
    }

    protected function launcherDirectory(): string
    {
        return storage_path('app/private/video-health/launcher');
    }

    /**
     * Only the short shell launcher is awaited, never the scan. A failure before
     * background creation returns started=false; an uncertain outcome throws.
     *
     * @return array{started: bool, pid: ?int}
     */
    protected function spawn(array $arguments, string $workingDirectory, string $logPath): array
    {
        if (!$this->supportsDetachedLaunch()) {
            return ['started' => false, 'pid' => null];
        }
        $process = $this->detachedProcess($arguments, $workingDirectory, $logPath);
        try {
            $process->run();
        } catch (ProcessStartFailedException) {
            // proc_open failed: no shell/scan child exists, so retry is safe.
            return ['started' => false, 'pid' => null];
        }
        $output = trim($process->getOutput());
        if ($output === 'NOT_STARTED' && !$process->isSuccessful()) {
            return ['started' => false, 'pid' => null];
        }
        if (!$process->isSuccessful() || !str_starts_with($output, 'STARTED:')) {
            throw new RuntimeException('Detached launcher did not acknowledge process creation.');
        }
        $value = substr($output, strlen('STARTED:'));
        $pid = preg_match('/\A[1-9][0-9]*\z/', $value)
            ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;

        return ['started' => true, 'pid' => $pid === false ? null : $pid];
    }

    protected function supportsDetachedLaunch(): bool
    {
        return PHP_OS_FAMILY === 'Linux';
    }

    protected function detachedProcess(array $arguments, string $workingDirectory, string $logPath): Process
    {
        // Positional arguments are passed through Symfony's argv API. No free-form
        // values are interpolated into shell syntax. nohup execs PHP in the child.
        $script = <<<'SH'
if ! command -v nohup >/dev/null 2>&1 || [ ! -x "$1" ] || [ ! -f "$2" ]; then
    printf 'NOT_STARTED\n'; exit 1
fi
# PHP_BINARY can identify php-fpm in HTTP; refuse it rather than spawning FPM.
if ! "$1" -r 'exit(PHP_SAPI === "cli" ? 0 : 1);' </dev/null >/dev/null 2>&1; then
    printf 'NOT_STARTED\n'; exit 1
fi
if ! exec 3>>"$5"; then
    printf 'NOT_STARTED\n'; exit 1
fi
nohup "$1" "$2" "$3" "$4" </dev/null >&3 2>&1 3>&- &
printf 'STARTED:%s\n' "$!"
SH;
        $process = new Process(['/bin/bash', '-c', $script, 'video-health-launcher', ...$arguments, $logPath], $workingDirectory);
        $process->setTimeout(10);

        return $process;
    }

    private function ensureDirectory(string $path): void
    {
        clearstatcache(true, $path);
        if (is_link($path)) {
            throw new RuntimeException('Symlink launcher directory rejected.');
        }
        $parent = dirname($path);
        if ($parent !== $path) {
            $this->ensureDirectory($parent);
        }
        if (file_exists($path)) {
            if (!is_dir($path)) {
                throw new RuntimeException('Unsafe launcher directory.');
            }
        } elseif (!@mkdir($path, 0700) && !is_dir($path)) {
            throw new RuntimeException('Cannot create private launcher directory.');
        }
        clearstatcache(true, $path);
        if (is_link($path) || !is_dir($path)) {
            throw new RuntimeException('Unsafe launcher directory.');
        }
    }

    private function openPrivateFile(string $path)
    {
        $this->ensureDirectory(dirname($path));
        $this->checkFile($path);
        $exists = file_exists($path);
        $file = @fopen($path, $exists ? 'c+b' : 'x+b');
        if ($file === false) {
            throw new RuntimeException('Cannot open private launcher file.');
        }
        try {
            $this->checkFile($path);
            $opened = fstat($file);
            $current = lstat($path);
            if ($opened === false || $current === false || $opened['ino'] !== $current['ino'] || $opened['dev'] !== $current['dev']
                || (!$exists && PHP_OS_FAMILY !== 'Windows' && !chmod($path, 0600))) {
                throw new RuntimeException('Cannot secure private launcher file.');
            }
        } catch (Throwable $exception) {
            fclose($file);
            throw $exception;
        }

        return $file;
    }

    private function checkFile(string $path): void
    {
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if (is_link($path) || ($stat !== false && (($stat['mode'] & 0170000) !== 0100000 || $stat['nlink'] !== 1))) {
            throw new RuntimeException('Unsafe private launcher file.');
        }
    }

    private function reserve($guard): void
    {
        if ($this->writeReservation($guard, "launch-attempt\n") !== strlen("launch-attempt\n")
            || !$this->flushReservation($guard) || !$this->syncReservation($guard)) {
            throw new RuntimeException('Cannot persist launch reservation; no child was launched.');
        }
    }

    private function releaseReservation($guard): void
    {
        if (!$this->truncateReservation($guard) || !$this->flushReservation($guard) || !$this->syncReservation($guard)) {
            throw new RuntimeException('Process did not start, but reservation cleanup failed; operator review is required.');
        }
    }

    protected function writeReservation($guard, string $marker): int|false
    {
        return fwrite($guard, $marker);
    }

    protected function flushReservation($guard): bool
    {
        return fflush($guard);
    }

    protected function syncReservation($guard): bool
    {
        return !function_exists('fsync') || fsync($guard);
    }

    protected function truncateReservation($guard): bool
    {
        return ftruncate($guard, 0);
    }
}
