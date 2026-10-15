<?php

namespace Tests\Unit;

use App\Models\VideoHealthRun;
use App\Services\VideoHealth\VideoHealthLauncher;
use App\Services\VideoHealth\VideoHealthLaunchException;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessStartFailedException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class VideoHealthLauncherTest extends TestCase
{
    use RefreshDatabase {
        migrateFreshUsing as private defaultMigrateFreshUsing;
    }

    private string $root;
    private LauncherSpy $launcher;

    protected function migrateFreshUsing(): array
    {
        return array_merge($this->defaultMigrateFreshUsing(), [
            '--path' => [database_path('migrations/2026_10_12_160000_create_video_health_runs_table.php')],
            '--realpath' => true,
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['video-health.launcher.php_binary' => null]);
        $this->root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'video-health-launcher-test-'.bin2hex(random_bytes(12));
        $this->launcher = new LauncherSpy($this->root);
    }

    protected function tearDown(): void
    {
        VideoHealthRun::flushEventListeners();
        if (is_dir($this->root)) {
            $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($items as $item) {
                if ($item->isLink() || !$item->isDir()) {
                    unlink($item->getPathname());
                } else {
                    rmdir($item->getPathname());
                }
            }
            rmdir($this->root);
        }
        parent::tearDown();
    }

    private function runRecord(array $attributes = []): VideoHealthRun
    {
        return VideoHealthRun::create(array_replace([
            'provider' => 'all', 'trigger' => 'manual',
            'classifier_version' => config('video-health.classifier_version'),
            'config_sha256' => hash('sha256', json_encode(config('video-health'), JSON_THROW_ON_ERROR)),
        ], $attributes))->refresh();
    }

    #[DataProvider('acceptedModes')]
    public function test_eligible_run_launches_fixed_command_and_preserves_lifecycle(array $attributes): void
    {
        $run = $this->runRecord($attributes);
        $before = $run->getAttributes();
        $result = $this->launcher->launch($run->id);
        $this->assertTrue($result['launched']);
        $this->assertSame(4321, $result['process_id']);
        $this->assertSame(1, $this->launcher->calls);
        $this->assertSame([PHP_BINARY, base_path('artisan'), 'video-health:run', (string) $run->id], $this->launcher->arguments);
        $this->assertSame(base_path(), $this->launcher->cwd);
        $this->assertSame($this->root.DIRECTORY_SEPARATOR.'run-'.$run->id.'.log', $result['log_path']);
        $run->refresh();
        $this->assertSame(4321, $run->process_id);
        $this->assertSame($result['log_path'], $run->log_path);
        foreach (['process_id', 'log_path', 'updated_at'] as $key) {
            unset($before[$key]);
        }
        $after = $run->getAttributes();
        foreach (['process_id', 'log_path', 'updated_at'] as $key) {
            unset($after[$key]);
        }
        $this->assertSame($before, $after);
        $this->assertSame(1, VideoHealthRun::count());
        $this->assertSame('queued', $run->status);
        $this->assertNull($run->started_at);
        $this->assertNull($run->finished_at);
        $this->assertNull($run->exit_code);
        $this->assertFalse(config('video-health.rollout.automatic_deactivation_enabled'));
    }

    public static function acceptedModes(): array
    {
        return [[[]], [['mode' => 'audit-deactivate', 'confirmed_at' => '2026-10-14 12:00:00']], [['trigger' => 'scheduled']]];
    }

    #[DataProvider('invalidIds')]
    public function test_invalid_or_missing_id_never_spawns(int $id): void
    {
        try {
            $this->launcher->launch($id);
            $this->fail('Expected refusal.');
        } catch (DomainException) {
            $this->assertSame(0, $this->launcher->calls);
            $this->assertSame(0, VideoHealthRun::count());
            $this->assertDirectoryDoesNotExist($this->root);
        }
    }

    public static function invalidIds(): array
    {
        return [[0], [-1], [999]];
    }

    #[DataProvider('refusedRuns')]
    public function test_ineligible_runs_remain_unchanged(array $attributes): void
    {
        $run = $this->runRecord($attributes);
        $before = $run->getAttributes();
        try {
            $this->launcher->launch($run->id);
            $this->fail('Expected refusal.');
        } catch (DomainException) {
            $this->assertSame($before, $run->refresh()->getAttributes());
            $this->assertSame(0, $this->launcher->calls);
            $this->assertDirectoryDoesNotExist($this->root);
        }
    }

    public static function refusedRuns(): array
    {
        $cases = array_map(fn (string $status): array => [['status' => $status]], [
            'deferred', 'launching', 'running', 'deactivating', 'completed', 'failed', 'aborted',
        ]);
        $cases[] = [['mode' => 'audit-deactivate']];
        $cases[] = [['mode' => 'audit-deactivate', 'trigger' => 'scheduled', 'confirmed_at' => '2026-10-14 12:00:00']];

        return $cases;
    }

    public function test_missing_pid_does_not_fail_successful_launch_or_overwrite_existing_pid(): void
    {
        $run = $this->runRecord(['process_id' => 987]);
        $this->launcher->result = ['started' => true, 'pid' => null];
        $result = $this->launcher->launch($run->id);
        $this->assertTrue($result['launched']);
        $this->assertNull($result['process_id']);
        $this->assertSame(987, $run->fresh()->process_id);
        $this->assertSame('queued', $run->fresh()->status);
    }

    public function test_creation_failure_preserves_run_and_allows_explicit_retry(): void
    {
        $run = $this->runRecord();
        $before = $run->getAttributes();
        $this->launcher->result = ['started' => false, 'pid' => null];
        try {
            $this->launcher->launch($run->id);
            $this->fail('Expected creation failure.');
        } catch (VideoHealthLaunchException $exception) {
            $this->assertLaunchOutcome($exception, VideoHealthLaunchException::CONFIRMED_NOT_LAUNCHED);
        }
        $this->assertSame($before, $run->refresh()->getAttributes());
        $this->assertSame('', file_get_contents($this->root.'/run-'.$run->id.'.lock'));
        $this->launcher->result = ['started' => true, 'pid' => 5678];
        $this->assertSame(5678, $this->launcher->launch($run->id)['process_id']);
        $this->assertSame(2, $this->launcher->calls);
    }

    public function test_launched_metadata_failure_requires_reconciliation_and_prevents_second_child(): void
    {
        $run = $this->runRecord();
        VideoHealthRun::updating(function (): void {
            throw new RuntimeException('SECRET metadata failure');
        });
        try {
            $this->launcher->launch($run->id);
            $this->fail('Expected persistence failure.');
        } catch (VideoHealthLaunchException $exception) {
            $this->assertLaunchOutcome($exception, VideoHealthLaunchException::RECONCILIATION_REQUIRED);
            $this->assertNotNull($exception->getPrevious());
            $this->assertStringContainsString('reconciliation', $exception->getMessage());
            $this->assertStringNotContainsString('SECRET', $exception->getMessage());
        }
        $this->assertSame('queued', $run->fresh()->status);
        $this->assertNull($run->fresh()->process_id);
        $this->assertSecondLaunchRefused($run);
    }

    public function test_uncertain_spawn_exception_does_not_clear_reservation_or_automatically_retry(): void
    {
        $run = $this->runRecord();
        $this->launcher->throwOnSpawn = true;
        try {
            $this->launcher->launch($run->id);
            $this->fail('Expected uncertain launch.');
        } catch (VideoHealthLaunchException $exception) {
            $this->assertLaunchOutcome($exception, VideoHealthLaunchException::RECONCILIATION_REQUIRED);
            $this->assertNotNull($exception->getPrevious());
        }
        $this->assertNull($run->fresh()->process_id);
        $this->assertSame('queued', $run->fresh()->status);
        $this->assertSecondLaunchRefused($run);
    }

    public function test_durable_launch_guard_survives_new_launcher_instance(): void
    {
        $run = $this->runRecord();
        $this->launcher->launch($run->id);
        $reopened = new LauncherSpy($this->root);
        try {
            $reopened->launch($run->id);
            $this->fail('Expected duplicate refusal.');
        } catch (VideoHealthLaunchException $exception) {
            $this->assertLaunchOutcome($exception, VideoHealthLaunchException::RECONCILIATION_REQUIRED);
            $this->assertSame(0, $reopened->calls);
            $this->assertSame(1, $this->launcher->calls);
        }
    }

    private function assertSecondLaunchRefused(VideoHealthRun $run): void
    {
        try {
            $this->launcher->launch($run->id);
            $this->fail('Expected duplicate refusal.');
        } catch (VideoHealthLaunchException $exception) {
            $this->assertLaunchOutcome($exception, VideoHealthLaunchException::RECONCILIATION_REQUIRED);
            $this->assertSame(1, $this->launcher->calls);
        }
    }

    public function test_child_claim_and_completion_are_not_overwritten_by_launcher_metadata(): void
    {
        $run = $this->runRecord();
        $this->launcher->afterSpawn = function () use ($run): void {
            $run->update(['status' => 'completed', 'stage' => 'completed', 'finished_at' => now(), 'exit_code' => 0]);
        };
        $this->launcher->launch($run->id);
        $this->assertSame('completed', $run->fresh()->status);
        $this->assertSame('completed', $run->fresh()->stage);
        $this->assertSame(0, $run->fresh()->exit_code);
        $this->assertNotNull($run->fresh()->finished_at);
        $this->assertSame(4321, $run->fresh()->process_id);
    }

    public function test_detached_linux_plan_uses_only_fixed_shell_and_positional_arguments(): void
    {
        $arguments = [PHP_BINARY, base_path('artisan'), 'video-health:run', '123'];
        $process = $this->launcher->plan($arguments, base_path(), $this->root.'/run-123.log');
        $this->assertSame(base_path(), $process->getWorkingDirectory());
        $this->assertSame(10.0, $process->getTimeout());
        $command = $process->getCommandLine();
        foreach (['/bin/bash', 'nohup', 'video-health:run', '123', 'STARTED:', 'NOT_STARTED', '</dev/null', '>&3 2>&1', '3>&- &', 'PHP_SAPI', 'cli'] as $part) {
            $this->assertStringContainsString($part, $command);
        }
        $this->assertStringNotContainsString('wait ', $command);
        $this->assertStringNotContainsString('/var/www/', $command);
        $this->assertSame(storage_path('app/private/video-health/launcher'), $this->launcher->productionDirectory());
        $source = file_get_contents(app_path('Services/VideoHealth/VideoHealthLauncher.php'));
        foreach (['Video::', 'VideoHealthRun::create', 'VideoWorkloadAdmission', 'EmbedHealthProbe', 'EmbedHealthClassifier', 'Http::', "'status' =>", "'is_active' =>", 'public_path(', "storage_path('app/public"] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $source);
        }
    }

    public function test_existing_nonregular_guard_or_log_is_rejected_before_spawn(): void
    {
        $run = $this->runRecord();
        mkdir($this->root);
        mkdir($this->root.'/run-'.$run->id.'.log');
        try {
            $this->launcher->launch($run->id);
            $this->fail('Expected unsafe log refusal.');
        } catch (VideoHealthLaunchException $exception) {
            $this->assertLaunchOutcome($exception, VideoHealthLaunchException::RECONCILIATION_REQUIRED);
            $this->assertSame(0, $this->launcher->calls);
            $this->assertNull($run->fresh()->process_id);
        }
    }

    #[DataProvider('acknowledgements')]
    public function test_real_spawn_boundary_interprets_only_launcher_acknowledgement(string $output, bool $success, array $expected): void
    {
        $process = $this->createMock(Process::class);
        $process->expects($this->once())->method('run')->willReturn($success ? 0 : 1);
        $process->method('getOutput')->willReturn($output);
        $process->method('isSuccessful')->willReturn($success);
        $launcher = new ProcessBoundarySpy($process);
        $this->assertSame($expected, $launcher->invokeSpawn());
    }

    public static function acknowledgements(): array
    {
        return [
            ["STARTED:456\n", true, ['started' => true, 'pid' => 456]],
            ["STARTED:\n", true, ['started' => true, 'pid' => null]],
            ["STARTED:999999999999999999999999\n", true, ['started' => true, 'pid' => null]],
            ["NOT_STARTED\n", false, ['started' => false, 'pid' => null]],
        ];
    }

    public function test_proc_open_failure_is_known_pre_spawn_failure(): void
    {
        $process = $this->createMock(Process::class);
        $process->expects($this->once())->method('run')->willThrowException(new ProcessStartFailedException(new Process(['missing']), 'Injected creation failure'));
        $this->assertSame(['started' => false, 'pid' => null], (new ProcessBoundarySpy($process))->invokeSpawn());
    }

    public function test_missing_acknowledgement_is_uncertain_and_not_a_retryable_creation_failure(): void
    {
        $process = $this->createMock(Process::class);
        $process->method('run')->willReturn(1);
        $process->method('getOutput')->willReturn('');
        $process->method('isSuccessful')->willReturn(false);
        $this->expectException(RuntimeException::class);
        (new ProcessBoundarySpy($process))->invokeSpawn();
    }

    public function test_busy_guard_refuses_without_blocking_or_spawning(): void
    {
        $run = $this->runRecord();
        mkdir($this->root);
        $guard = fopen($this->root.'/run-'.$run->id.'.lock', 'x+b');
        flock($guard, LOCK_EX);
        try {
            $this->launcher->launch($run->id);
            $this->fail('Expected busy guard refusal.');
        } catch (VideoHealthLaunchException $exception) {
            $this->assertLaunchOutcome($exception, VideoHealthLaunchException::RECONCILIATION_REQUIRED);
            $this->assertSame(0, $this->launcher->calls);
        } finally {
            flock($guard, LOCK_UN);
            fclose($guard);
        }
    }

    #[DataProvider('symlinkTargets')]
    public function test_symlink_paths_are_rejected_before_spawn(string $target): void
    {
        $run = $this->runRecord();
        mkdir($this->root);
        mkdir($this->root.'/destination');
        $path = $target === 'directory' ? $this->root.'/link' : $this->root.'/run-'.$run->id.'.'.$target;
        if (!@symlink($this->root.'/destination', $path)) {
            $this->markTestSkipped('Symlink creation is unavailable on this platform/account.');
        }
        if ($target === 'directory') {
            $this->launcher = new LauncherSpy($path);
        }
        try {
            $this->launcher->launch($run->id);
            $this->fail('Expected symlink refusal.');
        } catch (VideoHealthLaunchException $exception) {
            $this->assertLaunchOutcome($exception, VideoHealthLaunchException::RECONCILIATION_REQUIRED);
            $this->assertSame(0, $this->launcher->calls);
            $this->assertNull($run->fresh()->process_id);
        }
    }

    public static function symlinkTargets(): array
    {
        return [['directory'], ['lock'], ['log']];
    }

    #[DataProvider('reservationFailures')]
    public function test_reservation_failure_cleans_marker_under_lock_and_allows_later_retry(string $failure): void
    {
        $run = $this->runRecord();
        $before = $run->getAttributes();
        $this->launcher->reservationFailure = $failure;
        try {
            $this->launcher->launch($run->id);
            $this->fail('Expected reservation failure.');
        } catch (VideoHealthLaunchException $exception) {
            $this->assertLaunchOutcome($exception, VideoHealthLaunchException::CONFIRMED_NOT_LAUNCHED);
            $this->assertNotNull($exception->getPrevious());
        }
        $this->assertSame(0, $this->launcher->calls);
        $this->assertSame($before, $run->refresh()->getAttributes());
        $this->assertNotSame('', $this->launcher->markerBeforeCleanup);
        $this->assertTrue($this->launcher->cleanupWasLocked);
        $this->assertSame('', file_get_contents($this->root.'/run-'.$run->id.'.lock'));
        $this->launcher->reservationFailure = null;
        $this->assertTrue($this->launcher->launch($run->id)['launched']);
        $this->assertSame(1, $this->launcher->calls);
    }

    public static function reservationFailures(): array
    {
        return [['partial-write'], ['failed-write'], ['flush'], ['sync']];
    }

    #[DataProvider('cleanupFailures')]
    public function test_reservation_cleanup_failure_requires_operator_recovery_and_does_not_spawn(string $cleanup): void
    {
        $run = $this->runRecord();
        $before = $run->getAttributes();
        $this->launcher->reservationFailure = 'flush';
        $this->launcher->cleanupFailure = $cleanup;
        try {
            $this->launcher->launch($run->id);
            $this->fail('Expected cleanup failure.');
        } catch (VideoHealthLaunchException $exception) {
            $this->assertLaunchOutcome($exception, VideoHealthLaunchException::RECONCILIATION_REQUIRED);
            $this->assertStringContainsString('reservation cleanup failed', $exception->getMessage());
            $this->assertStringContainsString('Operator guard recovery/reconciliation is required', $exception->getMessage());
            $this->assertNotNull($exception->getPrevious());
        }
        $this->assertSame(0, $this->launcher->calls);
        $this->assertTrue($this->launcher->cleanupWasLocked);
        $this->assertSame($before, $run->refresh()->getAttributes());
        $this->assertNotSame('', file_get_contents($this->root.'/run-'.$run->id.'.lock'));
        $reopened = new LauncherSpy($this->root);
        try {
            $reopened->launch($run->id);
            $this->fail('Expected stale guard refusal.');
        } catch (VideoHealthLaunchException $exception) {
            $this->assertLaunchOutcome($exception, VideoHealthLaunchException::RECONCILIATION_REQUIRED);
            $this->assertSame(0, $reopened->calls);
        }
    }

    #[DataProvider('cleanupFailures')]
    public function test_definite_spawn_failure_with_cleanup_failure_remains_blocked(string $cleanup): void
    {
        $run = $this->runRecord();
        $this->launcher->result = ['started' => false, 'pid' => null];
        $this->launcher->cleanupFailure = $cleanup;
        try {
            $this->launcher->launch($run->id);
            $this->fail('Expected cleanup failure.');
        } catch (VideoHealthLaunchException $exception) {
            $this->assertLaunchOutcome($exception, VideoHealthLaunchException::RECONCILIATION_REQUIRED);
            $this->assertNotNull($exception->getPrevious());
        }
        $this->assertNotSame('', file_get_contents($this->root.'/run-'.$run->id.'.lock'));
        $this->assertTrue($this->launcher->cleanupWasLocked);
        $this->assertSecondLaunchRefused($run);
    }

    public function test_unrecognized_spawn_result_requires_reconciliation(): void
    {
        $run = $this->runRecord();
        $this->launcher->result = [];
        try {
            $this->launcher->launch($run->id);
            $this->fail('Expected uncertain launch.');
        } catch (VideoHealthLaunchException $exception) {
            $this->assertLaunchOutcome($exception, VideoHealthLaunchException::RECONCILIATION_REQUIRED);
        }
        $this->assertSecondLaunchRefused($run);
    }

    #[DataProvider('binarySelections')]
    public function test_php_binary_resolution_preserves_one_exact_executable_argument(?string $injected, mixed $configured, string $expected): void
    {
        config(['video-health.launcher.php_binary' => $configured]);
        $this->launcher = new LauncherSpy($this->root, $injected);
        $run = $this->runRecord(['provider' => 'xvideos', 'created_by' => 123, 'message' => 'untrusted executable suggestion']);
        $result = $this->launcher->launch($run->id);
        $this->assertTrue($result['launched']);
        $this->assertSame([$expected, base_path('artisan'), 'video-health:run', (string) $run->id], $this->launcher->arguments);
        $this->assertSame(1, $this->launcher->calls);
        $this->assertSame('queued', $run->refresh()->status);
    }

    public static function binarySelections(): array
    {
        return [
            ['custom-cli', 'configured-cli', 'custom-cli'],
            ['  custom-cli  ', 'configured-cli', 'custom-cli'],
            [null, 'configured-cli', 'configured-cli'],
            ['', 'configured-cli', 'configured-cli'],
            ['   ', 'configured-cli', 'configured-cli'],
            [null, '  configured-cli  ', 'configured-cli'],
            [null, '', PHP_BINARY],
            [null, '   ', PHP_BINARY],
            [null, null, PHP_BINARY],
            [null, 'C:\\Program Files\\Portable PHP\\php.exe', 'C:\\Program Files\\Portable PHP\\php.exe'],
            [null, '/opt/portable php/bin/php;literal$(value)', '/opt/portable php/bin/php;literal$(value)'],
            ['custom-cli', false, 'custom-cli'],
        ];
    }

    public function test_missing_launcher_config_preserves_php_binary_fallback(): void
    {
        $config = config('video-health');
        unset($config['launcher']);
        config(['video-health' => $config]);
        $run = $this->runRecord();
        $this->launcher->launch($run->id);
        $this->assertSame(PHP_BINARY, $this->launcher->arguments[0]);
    }

    #[DataProvider('invalidBinaryValues')]
    public function test_invalid_configured_binary_fails_closed_before_spawn_or_reservation(mixed $value): void
    {
        config(['video-health.launcher.php_binary' => $value]);
        $run = $this->runRecord();
        try {
            $this->launcher->launch($run->id);
            $this->fail('Expected invalid binary refusal.');
        } catch (VideoHealthLaunchException $exception) {
            $this->assertLaunchOutcome($exception, VideoHealthLaunchException::RECONCILIATION_REQUIRED);
        }
        $this->assertSame(0, $this->launcher->calls);
        $this->assertSame('', file_get_contents($this->root.'/run-'.$run->id.'.lock'));
        $this->assertSame('queued', $run->refresh()->status);
    }

    public static function invalidBinaryValues(): array
    {
        return [[false], [123], [[]], ["php\0other"], ["php\nother"]];
    }

    private function assertLaunchOutcome(VideoHealthLaunchException $exception, string $outcome): void
    {
        $this->assertSame($outcome, $exception->outcome());
        $this->assertSame($outcome === VideoHealthLaunchException::CONFIRMED_NOT_LAUNCHED, $exception->confirmedNotLaunched());
        $this->assertSame($outcome === VideoHealthLaunchException::RECONCILIATION_REQUIRED, $exception->requiresReconciliation());
    }

    public static function cleanupFailures(): array
    {
        return [['truncate'], ['flush'], ['sync']];
    }
}

class LauncherSpy extends VideoHealthLauncher
{
    public int $calls = 0;
    public array $arguments = [];
    public string $cwd = '';
    public array $result = ['started' => true, 'pid' => 4321];
    public bool $throwOnSpawn = false;
    public ?\Closure $afterSpawn = null;
    public ?string $reservationFailure = null;
    public ?string $cleanupFailure = null;
    public string $markerBeforeCleanup = '';
    public bool $cleanupWasLocked = false;
    private bool $cleaningReservation = false;
    private bool $reservationFailureInjected = false;

    public function __construct(private string $root, ?string $phpBinary = null)
    {
        parent::__construct($phpBinary);
    }

    protected function launcherDirectory(): string
    {
        return $this->root;
    }

    public function productionDirectory(): string
    {
        return parent::launcherDirectory();
    }

    protected function spawn(array $arguments, string $workingDirectory, string $logPath): array
    {
        $this->calls++;
        $this->arguments = $arguments;
        $this->cwd = $workingDirectory;
        if ($this->throwOnSpawn) {
            throw new RuntimeException('Injected uncertain spawn.');
        }
        if ($this->afterSpawn !== null) {
            ($this->afterSpawn)();
        }

        return $this->result;
    }

    public function plan(array $arguments, string $workingDirectory, string $logPath): Process
    {
        return $this->detachedProcess($arguments, $workingDirectory, $logPath);
    }

    protected function writeReservation($guard, string $marker): int|false
    {
        if (in_array($this->reservationFailure, ['partial-write', 'failed-write'], true) && !$this->reservationFailureInjected) {
            $this->reservationFailureInjected = true;
            $written = parent::writeReservation($guard, substr($marker, 0, 4));

            return $this->reservationFailure === 'failed-write' ? false : $written;
        }

        return parent::writeReservation($guard, $marker);
    }

    protected function flushReservation($guard): bool
    {
        if ($this->cleaningReservation && $this->cleanupFailure === 'flush') {
            return false;
        }
        if ($this->reservationFailure === 'flush' && !$this->reservationFailureInjected) {
            $this->reservationFailureInjected = true;

            return false;
        }

        return parent::flushReservation($guard);
    }

    protected function syncReservation($guard): bool
    {
        if ($this->cleaningReservation && $this->cleanupFailure === 'sync') {
            return false;
        }
        if ($this->reservationFailure === 'sync' && !$this->reservationFailureInjected) {
            $this->reservationFailureInjected = true;

            return false;
        }

        return parent::syncReservation($guard);
    }

    protected function truncateReservation($guard): bool
    {
        $this->cleaningReservation = true;
        $position = ftell($guard);
        rewind($guard);
        $this->markerBeforeCleanup = stream_get_contents($guard);
        fseek($guard, $position);
        $metadata = stream_get_meta_data($guard);
        $other = fopen($metadata['uri'], 'c+b');
        $this->cleanupWasLocked = !flock($other, LOCK_EX | LOCK_NB);
        fclose($other);
        if ($this->cleanupFailure === 'truncate') {
            return false;
        }

        return parent::truncateReservation($guard);
    }
}

class ProcessBoundarySpy extends VideoHealthLauncher
{
    public function __construct(private Process $process)
    {
        parent::__construct();
    }

    protected function supportsDetachedLaunch(): bool
    {
        return true;
    }

    protected function detachedProcess(array $arguments, string $workingDirectory, string $logPath): Process
    {
        return $this->process;
    }

    public function invokeSpawn(): array
    {
        return $this->spawn([PHP_BINARY, base_path('artisan'), 'video-health:run', '1'], base_path(), 'unused-private-test-log');
    }
}
