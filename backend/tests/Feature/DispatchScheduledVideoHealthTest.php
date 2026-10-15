<?php

namespace Tests\Feature;

use App\Models\VideoHealthRun;
use App\Models\VideoImportJob;
use App\Services\VideoHealth\EmbedHealthProbe;
use App\Services\VideoHealth\VideoHealthAuditRunner;
use App\Services\VideoHealth\VideoHealthLaunchException;
use App\Services\VideoHealth\VideoHealthLauncher;
use App\Services\VideoHealth\VideoHealthRunCoordinator;
use App\Services\VideoWorkloadAdmission;
use DomainException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class DispatchScheduledVideoHealthTest extends TestCase
{
    use RefreshDatabase {
        migrateFreshUsing as private defaultMigrateFreshUsing;
    }

    protected function migrateFreshUsing(): array
    {
        return array_merge($this->defaultMigrateFreshUsing(), [
            '--path' => array_map(fn (string $file): string => database_path('migrations/'.$file), [
                '2026_09_03_110000_create_video_import_jobs_table.php',
                '2026_09_26_120000_add_start_page_to_video_import_jobs_table.php',
                '2026_10_12_160000_create_video_health_runs_table.php',
            ]),
            '--realpath' => true,
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-19 12:00:00', 'UTC'));
        $this->app->instance(VideoHealthAuditRunner::class, Mockery::mock(VideoHealthAuditRunner::class));
        $this->app->instance(EmbedHealthProbe::class, Mockery::mock(EmbedHealthProbe::class));
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    private function key(string $boundary = '2026-10-18T01:00Z'): string
    {
        return 'video-health:weekly:audit-only:all:'.$boundary;
    }

    private function cohort(array $attributes = []): VideoHealthRun
    {
        return VideoHealthRun::create(array_replace([
            'provider' => 'all', 'mode' => 'audit-only', 'trigger' => 'scheduled',
            'schedule_key' => $this->key(),
            'classifier_version' => config('video-health.classifier_version'),
            'config_sha256' => hash('sha256', json_encode(config('video-health'), JSON_THROW_ON_ERROR)),
        ], $attributes))->refresh();
    }

    private function launcher(): \Mockery\MockInterface
    {
        $launcher = Mockery::mock(VideoHealthLauncher::class);
        $this->app->instance(VideoHealthLauncher::class, $launcher);

        return $launcher;
    }

    private function dispatch(int $exit = 0): void
    {
        $this->artisan('video-health:dispatch-scheduled')->assertExitCode($exit);
    }

    private function busyImport(): VideoImportJob
    {
        return VideoImportJob::create([
            'source' => 'eporner', 'category_slug' => 'test', 'mode' => 'prepare-only',
            'minimum_count' => 10, 'target_count' => 20, 'status' => 'running',
        ]);
    }

    #[DataProvider('cohortTimes')]
    public function test_utc_boundary_and_fixed_policy(string $time, string $boundary): void
    {
        config(['app.timezone' => 'Pacific/Auckland']);
        $this->travelTo(Carbon::parse($time));
        $coordinator = Mockery::mock(VideoHealthRunCoordinator::class);
        $run = $this->cohort(['schedule_key' => $this->key($boundary)]);
        $coordinator->shouldReceive('admitScheduled')->once()->with([
            'provider' => 'all', 'mode' => 'audit-only', 'schedule_key' => $this->key($boundary),
        ])->andReturn(['run' => $run, 'reason' => null]);
        $this->app->instance(VideoHealthRunCoordinator::class, $coordinator);
        $this->launcher()->shouldReceive('launch')->once()->with($run->id)->andReturn([
            'launched' => true, 'process_id' => null, 'log_path' => 'private-test-log',
        ]);
        $this->dispatch();
        $this->assertSame('queued', $run->refresh()->status);
        $this->assertNull($run->started_at);
    }

    public static function cohortTimes(): array
    {
        return [
            ['2026-10-18T00:59:00Z', '2026-10-11T01:00Z'],
            ['2026-10-18T01:00:00Z', '2026-10-18T01:00Z'],
            ['2026-10-18T01:01:00Z', '2026-10-18T01:00Z'],
            ['2026-10-19T12:00:00Z', '2026-10-18T01:00Z'],
            ['2026-10-24T23:59:00Z', '2026-10-18T01:00Z'],
            ['2026-10-25T01:00:00Z', '2026-10-25T01:00Z'],
            ['2026-10-18T03:59:00+03:00', '2026-10-11T01:00Z'],
        ];
    }

    public function test_idle_admission_launches_exact_id_without_claiming_run(): void
    {
        $this->launcher()->shouldReceive('launch')->once()->withArgs(function (int $id): bool {
            $run = VideoHealthRun::findOrFail($id);
            $this->assertSame($this->key(), $run->schedule_key);
            $this->assertSame('scheduled', $run->trigger);
            $this->assertSame('audit-only', $run->mode);
            $this->assertSame('all', $run->provider);
            $this->assertNull($run->confirmed_at);

            return true;
        })->andReturn(['launched' => true, 'process_id' => null, 'log_path' => 'private-test-log']);
        $this->dispatch();
        $run = VideoHealthRun::sole();
        $this->assertSame('queued', $run->status);
        $this->assertNull($run->process_id);
        $this->assertNull($run->started_at);
        $this->assertFalse(config('video-health.rollout.automatic_deactivation_enabled'));
    }

    #[DataProvider('existingStatuses')]
    public function test_existing_cohort_is_not_reset_or_recreated(string $status, int $exit): void
    {
        $run = $this->cohort(['status' => $status]);
        $before = $run->getAttributes();
        $this->launcher()->shouldNotReceive('launch');
        $this->dispatch($exit);
        $this->assertSame($before, $run->refresh()->getAttributes());
        $this->assertSame(1, VideoHealthRun::count());
    }

    public static function existingStatuses(): array
    {
        return [['running', 0], ['deactivating', 0], ['completed', 0], ['failed', 0], ['aborted', 0], ['launching', 1]];
    }

    public function test_repeated_busy_ticks_reuse_same_cohort_then_promote_and_launch_same_id(): void
    {
        $import = $this->busyImport();
        $launcher = $this->launcher();
        $this->dispatch();
        $run = VideoHealthRun::sole();
        $this->assertSame('deferred', $run->status);
        $before = $run->getAttributes();
        $this->travelTo(Carbon::parse('2026-10-19 13:00:00', 'UTC'));
        $this->dispatch();
        $this->assertSame($before, $run->refresh()->getAttributes());
        $import->update(['status' => 'pass']);
        $launcher->shouldReceive('launch')->once()->with($run->id)->andReturn(['launched' => true, 'process_id' => 123, 'log_path' => 'private-test-log']);
        $this->dispatch();
        $this->assertSame('queued', $run->refresh()->status);
        $this->assertSame('queued', $run->stage);
        $this->assertSame(1, VideoHealthRun::count());
    }

    public function test_active_health_keeps_current_cohort_deferred(): void
    {
        $this->cohort(['trigger' => 'manual', 'schedule_key' => null, 'status' => 'running']);
        $this->launcher()->shouldNotReceive('launch');
        $this->dispatch();
        $this->assertSame('deferred', VideoHealthRun::where('schedule_key', $this->key())->sole()->status);
        $this->assertSame(2, VideoHealthRun::count());
    }

    public function test_next_cohort_never_queries_or_promotes_older_deferred_rows(): void
    {
        $old = $this->cohort(['schedule_key' => $this->key('2026-10-11T01:00Z'), 'status' => 'deferred', 'stage' => 'deferred']);
        $before = $old->getAttributes();
        $queries = [];
        DB::listen(function ($query) use (&$queries): void { $queries[] = [$query->sql, $query->bindings]; });
        $this->launcher()->shouldReceive('launch')->twice()->withArgs(fn (int $id): bool => $id !== $old->id)
            ->andReturn(['launched' => true, 'process_id' => null, 'log_path' => 'private-test-log']);
        $this->dispatch();
        $current = VideoHealthRun::where('schedule_key', $this->key())->sole();
        $current->update(['status' => 'completed']);
        $this->travelTo(Carbon::parse('2026-10-25 01:00:00', 'UTC'));
        $this->dispatch();
        $captured = $queries;
        $this->assertSame($before, $old->refresh()->getAttributes());
        $this->assertSame(3, VideoHealthRun::count());
        $this->assertSame(1, VideoHealthRun::where('schedule_key', $this->key('2026-10-25T01:00Z'))->count());
        foreach ($captured as [$sql, $bindings]) {
            if (str_starts_with(strtolower($sql), 'select')) {
                $this->assertNotContains($old->schedule_key, $bindings);
                $this->assertStringNotContainsString('order by', strtolower($sql));
            }
        }
    }

    public function test_confirmed_no_child_failure_only_retries_on_later_invocation(): void
    {
        $run = $this->cohort();
        $before = $run->getAttributes();
        $launcher = $this->launcher();
        $launcher->shouldReceive('launch')->once()->with($run->id)->andThrow(new VideoHealthLaunchException(
            VideoHealthLaunchException::CONFIRMED_NOT_LAUNCHED, 'SECRET arbitrary text',
        ));
        $this->artisan('video-health:dispatch-scheduled')
            ->expectsOutput('No audit child launched; this tick failed. A later tick may retry the queued run.')->assertExitCode(1);
        $this->assertSame($before, $run->refresh()->getAttributes());
        $this->assertSame(1, VideoHealthRun::count());
        $this->travelTo(Carbon::parse('2026-10-19 13:00:00', 'UTC'));
        $this->launcher()->shouldReceive('launch')->once()->with($run->id)
            ->andReturn(['launched' => true, 'process_id' => null, 'log_path' => 'private-test-log']);
        $this->dispatch();
        $this->assertSame($before, $run->refresh()->getAttributes());
    }

    #[DataProvider('launchFailures')]
    public function test_other_launch_failures_never_retry_or_mutate_cohort(string $failure): void
    {
        $run = $this->cohort();
        $before = $run->getAttributes();
        $exception = match ($failure) {
            'reconcile' => new VideoHealthLaunchException(VideoHealthLaunchException::RECONCILIATION_REQUIRED, 'SECRET arbitrary text'),
            'eligibility' => new DomainException('SECRET arbitrary text'),
            default => new RuntimeException('SECRET arbitrary text'),
        };
        $this->launcher()->shouldReceive('launch')->once()->with($run->id)->andThrow($exception);
        $this->dispatch(1);
        $this->assertSame($before, $run->refresh()->getAttributes());
        $this->assertSame(1, VideoHealthRun::count());
    }

    public static function launchFailures(): array
    {
        return [['reconcile'], ['eligibility'], ['unexpected']];
    }

    #[DataProvider('incoherentCohorts')]
    public function test_incoherent_returned_cohort_fails_closed(array $attributes): void
    {
        $run = $this->cohort($attributes);
        $this->launcher()->shouldNotReceive('launch');
        $this->dispatch(1);
        $this->assertSame(1, VideoHealthRun::count());
    }

    public static function incoherentCohorts(): array
    {
        return [[['provider' => 'eporner']], [['mode' => 'audit-deactivate']], [['trigger' => 'manual']],
            [['classifier_version' => 'old']], [['config_sha256' => str_repeat('0', 64)]],
            [['status' => 'deferred', 'stage' => 'deferred', 'selected_count' => 1]]];
    }

    public function test_invalid_coordinator_result_and_unexpected_exception_fail_safely(): void
    {
        $coordinator = Mockery::mock(VideoHealthRunCoordinator::class);
        $coordinator->shouldReceive('admitScheduled')->once()->andReturn(['run' => null, 'reason' => VideoWorkloadAdmission::INVALID_INPUT]);
        $this->app->instance(VideoHealthRunCoordinator::class, $coordinator);
        $this->launcher()->shouldNotReceive('launch');
        $this->dispatch(1);
        $this->assertSame(0, VideoHealthRun::count());
        $coordinator = Mockery::mock(VideoHealthRunCoordinator::class);
        $coordinator->shouldReceive('admitScheduled')->once()->andThrow(new RuntimeException('SECRET SQL'));
        $this->app->instance(VideoHealthRunCoordinator::class, $coordinator);
        $this->artisan('video-health:dispatch-scheduled')->expectsOutput('Weekly audit dispatch failed; operator review is required.')->assertExitCode(1);
    }

    public function test_promotion_cannot_substitute_another_run(): void
    {
        $run = $this->cohort(['status' => 'deferred', 'stage' => 'deferred']);
        $other = clone $run;
        $other->id = $run->id + 1;
        $other->status = 'queued';
        $coordinator = Mockery::mock(VideoHealthRunCoordinator::class);
        $coordinator->shouldReceive('admitScheduled')->once()->andReturn(['run' => $run, 'reason' => null]);
        $coordinator->shouldReceive('promoteDeferredScheduled')->once()->with($run->id)->andReturn(['run' => $other, 'reason' => null]);
        $this->app->instance(VideoHealthRunCoordinator::class, $coordinator);
        $this->launcher()->shouldNotReceive('launch');
        $this->dispatch(1);
        $this->assertSame('deferred', $run->refresh()->status);
    }

    #[DataProvider('promotionResults')]
    public function test_promotion_result_controls_only_the_exact_admitted_run(string $status, ?string $reason, int $exit, bool $launch): void
    {
        $run = $this->cohort(['status' => 'deferred', 'stage' => 'deferred']);
        $promoted = clone $run;
        $promoted->status = $status;
        $promoted->stage = $status;
        $coordinator = Mockery::mock(VideoHealthRunCoordinator::class);
        $coordinator->shouldReceive('admitScheduled')->once()->with([
            'provider' => 'all', 'mode' => 'audit-only', 'schedule_key' => $this->key(),
        ])->andReturn(['run' => $run, 'reason' => null]);
        $coordinator->shouldReceive('promoteDeferredScheduled')->once()->with($run->id)
            ->andReturn(['run' => $promoted, 'reason' => $reason]);
        $this->app->instance(VideoHealthRunCoordinator::class, $coordinator);
        $launcher = $this->launcher();
        if ($launch) {
            $launcher->shouldReceive('launch')->once()->with($run->id)
                ->andReturn(['launched' => true, 'process_id' => null, 'log_path' => 'private-test-log']);
        } else {
            $launcher->shouldNotReceive('launch');
        }
        $this->dispatch($exit);
        $this->assertSame(1, VideoHealthRun::count());
    }

    public static function promotionResults(): array
    {
        return [
            ['queued', null, 0, true],
            ['deferred', VideoWorkloadAdmission::ACTIVE_IMPORT, 0, false],
            ['deferred', VideoWorkloadAdmission::ACTIVE_HEALTH, 0, false],
            ['deferred', null, 1, false],
            ['queued', VideoWorkloadAdmission::ACTIVE_HEALTH, 1, false],
            ['completed', null, 1, false],
            ['unknown', null, 1, false],
        ];
    }

    public function test_unacknowledged_launch_fails_without_reset_or_second_attempt(): void
    {
        $run = $this->cohort();
        $before = $run->getAttributes();
        $this->launcher()->shouldReceive('launch')->once()->with($run->id)
            ->andReturn(['launched' => false, 'process_id' => null, 'log_path' => 'private-test-log']);
        $this->dispatch(1);
        $this->assertSame($before, $run->refresh()->getAttributes());
    }

    public function test_scheduler_registration_preserves_seo_and_only_schedules_hourly_dispatch(): void
    {
        $this->app->make(Kernel::class)->bootstrap();
        $events = $this->app->make(Schedule::class)->events();
        $health = array_values(array_filter($events, fn ($event): bool => str_contains($event->command ?? '', 'video-health:')));
        $this->assertCount(1, $health);
        $this->assertStringContainsString('video-health:dispatch-scheduled', $health[0]->command);
        $this->assertSame('0 * * * *', $health[0]->expression);
        $this->assertSame('UTC', $health[0]->timezone);
        $this->assertTrue($health[0]->withoutOverlapping);
        $this->assertSame(10, $health[0]->expiresAt);
        $this->assertFalse($health[0]->onOneServer);
        foreach (['seo:process-video-memberships' => ['* * * * *', 20], 'seo:build-video-contents' => ['20 4 * * *', 180]] as $name => [$cron, $expiry]) {
            $seo = array_values(array_filter($events, fn ($event): bool => str_contains($event->command ?? '', $name)));
            $this->assertCount(1, $seo);
            $this->assertSame($cron, $seo[0]->expression);
            $this->assertTrue($seo[0]->withoutOverlapping);
            $this->assertSame($expiry, $seo[0]->expiresAt);
            $this->assertTrue($seo[0]->filtersPass($this->app));
            $import = $this->busyImport();
            $this->assertFalse($seo[0]->filtersPass($this->app));
            $import->update(['status' => 'pass']);
        }
    }

    public function test_dispatch_source_has_no_foreground_work_or_guard_manipulation(): void
    {
        $source = file_get_contents(app_path('Console/Commands/DispatchScheduledVideoHealth.php'));
        foreach (['VideoHealthAuditRunner', 'EmbedHealthProbe', 'EmbedHealthClassifier', 'VideoHealthDeactivationGate',
            'Video::', 'VideoHealthRun::create', 'VideoHealthRun::query', 'getMessage(', 'ftruncate(', 'unlink(', 'flock(',
            'sleep(', 'MODE_AUDIT_DEACTIVATE'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $source);
        }
        $this->assertFalse(config('video-health.rollout.automatic_deactivation_enabled'));
    }
}
