<?php

namespace Tests\Feature;

use App\Filament\Pages\VideoHealthMonitor;
use App\Models\User;
use App\Models\VideoHealthRun;
use App\Services\VideoHealth\VideoHealthAuditRunner;
use App\Services\VideoHealth\VideoHealthLauncher;
use App\Services\VideoHealth\VideoHealthRunCoordinator;
use App\Services\VideoWorkloadAdmission;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class VideoHealthMonitorPageTest extends TestCase
{
    use RefreshDatabase {
        migrateFreshUsing as private defaultMigrateFreshUsing;
    }

    private VideoHealthRunCoordinator $coordinator;
    private VideoHealthLauncher $launcher;
    private User $admin;

    protected function migrateFreshUsing(): array
    {
        return array_merge($this->defaultMigrateFreshUsing(), [
            '--path' => array_map(fn (string $file): string => database_path('migrations/'.$file), [
                '0001_01_01_000000_create_users_table.php',
                '2026_08_10_220901_create_notifications_table.php',
                '2026_08_21_103500_add_is_admin_to_users_table.php',
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
        $this->admin = User::factory()->create();
        $this->admin->forceFill(['is_admin' => true])->save();
        $this->actingAs($this->admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->coordinator = Mockery::mock(VideoHealthRunCoordinator::class);
        $this->launcher = Mockery::mock(VideoHealthLauncher::class);
        $this->app->instance(VideoHealthRunCoordinator::class, $this->coordinator);
        $this->app->instance(VideoHealthLauncher::class, $this->launcher);
        $runner = Mockery::mock(VideoHealthAuditRunner::class);
        $runner->shouldNotReceive('run');
        $this->app->instance(VideoHealthAuditRunner::class, $runner);
    }

    private function runRecord(array $attributes = []): VideoHealthRun
    {
        return VideoHealthRun::create(array_replace([
            'provider' => 'all', 'trigger' => 'manual',
            'classifier_version' => config('video-health.classifier_version'),
            'config_sha256' => hash('sha256', json_encode(config('video-health'), JSON_THROW_ON_ERROR)),
        ], $attributes))->refresh();
    }

    public function test_page_is_discovered_renderable_and_has_expected_identity_and_form_options(): void
    {
        $this->assertContains(VideoHealthMonitor::class, Filament::getPanel('admin')->getPages());
        $this->assertSame('Video Management', VideoHealthMonitor::getNavigationGroup());
        $this->assertSame('Video Health Monitor', VideoHealthMonitor::getNavigationLabel());
        $page = Livewire::test(VideoHealthMonitor::class)->assertSuccessful()
            ->assertFormSet(['provider' => 'all', 'mode' => 'audit-only', 'confirmation' => null])
            ->assertFormFieldExists('provider', fn (Select $field): bool => array_keys($field->getOptions()) === ['all', 'eporner', 'xvideos', 'xnxx', 'redtube'])
            ->assertFormFieldExists('mode', fn (Select $field): bool => $field->getOptions() === ['audit-only' => 'Audit Only', 'audit-deactivate' => 'Audit + Deactivate Broken']);
        $this->assertSame('Video Health Monitor', $page->instance()->getTitle());
        $this->assertFalse(config('video-health.rollout.automatic_deactivation_enabled'));
    }

    public function test_guest_route_requires_login_and_non_admin_cannot_access_page(): void
    {
        auth()->logout();
        $this->get(VideoHealthMonitor::getUrl())->assertRedirect();
        $this->admin->forceFill(['is_admin' => false])->save();
        $this->actingAs($this->admin);
        $this->assertFalse(VideoHealthMonitor::canAccess());
        Livewire::test(VideoHealthMonitor::class)->assertForbidden();
    }

    #[DataProvider('modes')]
    public function test_run_now_calls_manual_coordinator_then_exact_launcher_once(string $mode): void
    {
        $run = $this->runRecord(['mode' => $mode, 'confirmed_at' => $mode === 'audit-deactivate' ? now() : null]);
        $this->coordinator->shouldReceive('admitManual')->once()->with(Mockery::on(function (array $input) use ($mode): bool {
            $this->assertSame(['provider', 'mode', 'created_by', 'confirmed_at'], array_keys($input));
            $this->assertSame('all', $input['provider']);
            $this->assertSame($mode, $input['mode']);
            $this->assertSame($this->admin->id, $input['created_by']);
            if ($mode === 'audit-deactivate') {
                $this->assertNotNull($input['confirmed_at']);
            } else {
                $this->assertNull($input['confirmed_at']);
            }

            return true;
        }))->andReturn(['run' => $run, 'reason' => null]);
        $this->launcher->shouldReceive('launch')->once()->with($run->id)->andReturn(['launched' => true, 'process_id' => null, 'log_path' => 'private']);
        Livewire::test(VideoHealthMonitor::class)
            ->fillForm(['mode' => $mode, 'confirmation' => $mode === 'audit-deactivate' ? 'confirmed' : null])
            ->call('runNow')->assertHasNoFormErrors()->assertNotified('Detached health process launched')
            ->assertSet('data.confirmation', null);
        $this->assertSame(1, VideoHealthRun::count());
        $this->assertSame('queued', $run->fresh()->status);
    }

    public static function modes(): array
    {
        return [['audit-only'], ['audit-deactivate']];
    }

    public function test_deactivation_requires_confirmation_before_any_admission_or_launch(): void
    {
        $this->coordinator->shouldNotReceive('admitManual');
        $this->launcher->shouldNotReceive('launch');
        Livewire::test(VideoHealthMonitor::class)->fillForm(['mode' => 'audit-deactivate'])
            ->call('runNow')->assertHasFormErrors(['confirmation']);
        $this->assertSame(0, VideoHealthRun::count());
    }

    public function test_stale_confirmation_on_audit_only_is_not_forwarded_to_coordinator(): void
    {
        $run = $this->runRecord();
        $this->coordinator->shouldReceive('admitManual')->once()
            ->with(Mockery::on(fn (array $input): bool => $input['mode'] === 'audit-only' && $input['confirmed_at'] === null))
            ->andReturn(['run' => $run, 'reason' => null]);
        $this->launcher->shouldReceive('launch')->once()->with($run->id)->andReturn(['launched' => true]);
        Livewire::test(VideoHealthMonitor::class)->fillForm(['confirmation' => 'confirmed'])
            ->call('runNow')->assertSet('data.confirmation', null)->assertNotified('Detached health process launched');
    }

    public function test_unconfirmed_launcher_result_requires_review_and_never_auto_retries(): void
    {
        $run = $this->runRecord();
        $this->coordinator->shouldReceive('admitManual')->once()->andReturn(['run' => $run, 'reason' => null]);
        $this->launcher->shouldReceive('launch')->once()->with($run->id)->andReturn(['launched' => false]);
        Livewire::test(VideoHealthMonitor::class)->call('runNow')->assertNotified('Launch requires operator review')->call('refreshStatus');
        $this->assertSame('queued', $run->fresh()->status);
        $this->assertSame(1, VideoHealthRun::count());
    }

    public function test_confirmed_manual_deactivation_retry_uses_existing_confirmation_without_admission(): void
    {
        $run = $this->runRecord(['mode' => 'audit-deactivate', 'confirmed_at' => now()]);
        $this->coordinator->shouldNotReceive('admitManual');
        $this->launcher->shouldReceive('launch')->once()->with($run->id)->andReturn(['launched' => true]);
        Livewire::test(VideoHealthMonitor::class)->assertSee('Retry Launch')->call('retryLaunch', $run->id)
            ->assertNotified('Detached health process launched');
        $this->assertSame(1, VideoHealthRun::count());
    }

    public function test_mode_changes_clear_confirmation_and_audit_only_never_forwards_it(): void
    {
        $page = Livewire::test(VideoHealthMonitor::class)->set('data.mode', 'audit-deactivate')
            ->set('data.confirmation', 'confirmed')->set('data.mode', 'audit-only')
            ->assertSet('data.confirmation', null)->set('data.mode', 'audit-deactivate')
            ->assertSet('data.confirmation', null);
        $this->coordinator->shouldNotReceive('admitManual');
        $page->call('runNow')->assertHasFormErrors(['confirmation']);
    }

    #[DataProvider('providerScopeChanges')]
    public function test_provider_scope_change_clears_confirmation_and_refuses_unconfirmed_deactivation(string $from, string $to): void
    {
        $this->coordinator->shouldNotReceive('admitManual');
        $this->launcher->shouldNotReceive('launch');
        Livewire::test(VideoHealthMonitor::class)->set('data.provider', $from)
            ->set('data.mode', 'audit-deactivate')->set('data.confirmation', 'confirmed')
            ->assertSet('data.confirmation', 'confirmed')->set('data.provider', $to)
            ->assertSet('data.provider', $to)->assertSet('data.confirmation', null)
            ->call('runNow')->assertHasFormErrors(['confirmation']);
        $this->assertSame(0, VideoHealthRun::count());
    }

    public static function providerScopeChanges(): array
    {
        return [['eporner', 'all'], ['eporner', 'xvideos'], ['all', 'redtube'], ['xnxx', 'redtube']];
    }

    public function test_renewed_confirmation_admits_current_all_scope_and_launches_exact_new_run(): void
    {
        $renewed = false;
        $admittedId = null;
        $this->coordinator->shouldReceive('admitManual')->once()
            ->andReturnUsing(function (array $input) use (&$renewed, &$admittedId): array {
                $this->assertTrue($renewed, 'Admission must not occur before renewed confirmation.');
                $this->assertSame('all', $input['provider']);
                $this->assertSame('audit-deactivate', $input['mode']);
                $this->assertNotNull($input['confirmed_at']);
                $this->assertSame(now()->toIso8601String(), $input['confirmed_at']);
                $run = $this->runRecord($input);
                $admittedId = $run->id;

                return ['run' => $run, 'reason' => null];
            });
        $this->launcher->shouldReceive('launch')->once()
            ->with(Mockery::on(function (int $runId) use (&$admittedId): bool {
                $this->assertNotNull($admittedId);
                $this->assertSame($admittedId, $runId);

                return true;
            }))->andReturn(['launched' => true]);
        $this->freezeTime();
        $page = Livewire::test(VideoHealthMonitor::class)->set('data.provider', 'eporner')
            ->set('data.mode', 'audit-deactivate')->set('data.confirmation', 'confirmed')
            ->set('data.provider', 'all')->assertSet('data.confirmation', null)
            ->call('runNow')->assertHasFormErrors(['confirmation']);
        $this->assertSame(0, VideoHealthRun::count());
        $renewed = true;
        $page->set('data.confirmation', 'confirmed')->call('runNow')->assertHasNoFormErrors()
            ->assertNotified('Detached health process launched')->assertSet('data.confirmation', null);
        $this->assertSame(1, VideoHealthRun::count());
        $this->assertSame('all', VideoHealthRun::first()->provider);
        $this->assertNotNull(VideoHealthRun::first()->confirmed_at);
    }

    #[DataProvider('invalidSelections')]
    public function test_invalid_form_selections_do_not_admit(array $data, string $field): void
    {
        $this->coordinator->shouldNotReceive('admitManual');
        $this->launcher->shouldNotReceive('launch');
        Livewire::test(VideoHealthMonitor::class)->fillForm($data)->call('runNow')->assertHasFormErrors([$field]);
    }

    public static function invalidSelections(): array
    {
        return [[['provider' => 'unknown'], 'provider'], [['mode' => 'scheduled'], 'mode']];
    }

    #[DataProvider('admissionRefusals')]
    public function test_admission_refusals_show_safe_notification_without_launch_or_creation(string $reason): void
    {
        $this->coordinator->shouldReceive('admitManual')->once()->andReturn(['run' => null, 'reason' => $reason]);
        $this->launcher->shouldNotReceive('launch');
        Livewire::test(VideoHealthMonitor::class)->call('runNow')->assertNotified('Health run not admitted');
        $this->assertSame(0, VideoHealthRun::count());
    }

    public static function admissionRefusals(): array
    {
        return [[VideoWorkloadAdmission::ACTIVE_IMPORT], [VideoWorkloadAdmission::ACTIVE_HEALTH],
            [VideoWorkloadAdmission::INVALID_INPUT], [VideoWorkloadAdmission::AUTOMATIC_DEACTIVATION_DISABLED]];
    }

    public function test_admission_exception_is_masked_and_no_launch_attempted(): void
    {
        $this->coordinator->shouldReceive('admitManual')->once()->andThrow(new RuntimeException('SECRET SQL'));
        $this->launcher->shouldNotReceive('launch');
        Livewire::test(VideoHealthMonitor::class)->call('runNow')->assertNotified('Unable to admit health run');
        $this->assertStringNotContainsString('SECRET', json_encode(session('filament.notifications', [])));
        $this->assertSame(0, VideoHealthRun::count());
    }

    #[DataProvider('launchFailures')]
    public function test_launch_failures_preserve_existing_queued_run_and_do_not_retry_automatically(string $message, string $title): void
    {
        $run = $this->runRecord();
        $this->coordinator->shouldReceive('admitManual')->once()->andReturn(['run' => $run, 'reason' => null]);
        $this->launcher->shouldReceive('launch')->once()->with($run->id)->andThrow(new RuntimeException($message));
        Livewire::test(VideoHealthMonitor::class)->call('runNow')->assertNotified($title)
            ->assertSee('Retry Launch')->call('refreshStatus');
        $this->assertSame('queued', $run->fresh()->status);
        $this->assertSame(1, VideoHealthRun::count());
        $this->assertStringNotContainsString('SECRET', json_encode(session('filament.notifications', [])));
    }

    public static function launchFailures(): array
    {
        return [
            ['Detached process creation failed; the queued run may be retried.', 'Health process did not start'],
            ['Cannot persist launch reservation; no child was launched.', 'Health process did not start'],
            ['Launch outcome is uncertain; a child may have started.', 'Launch requires operator review'],
            ['No child was launched; reservation cleanup failed. Operator guard recovery/reconciliation is required.', 'Launch requires operator review'],
            ['Child launched, but launcher metadata could not be persisted;', 'Launch requires operator review'],
            ['SECRET SQL launch failure', 'Launch requires operator review'],
        ];
    }

    public function test_retry_launch_operates_only_on_existing_run_and_handles_guard_refusal(): void
    {
        $run = $this->runRecord();
        $this->coordinator->shouldNotReceive('admitManual');
        $this->launcher->shouldReceive('launch')->once()->with($run->id)
            ->andThrow(new \DomainException('A child may already have been launched; review the private log and run before retrying.'));
        Livewire::test(VideoHealthMonitor::class)->call('retryLaunch', $run->id)->assertNotified('Launch requires operator review');
        $this->assertSame(1, VideoHealthRun::count());
    }

    public function test_queued_retry_success_does_not_readmit_or_create_run(): void
    {
        $run = $this->runRecord();
        $this->coordinator->shouldNotReceive('admitManual');
        $this->launcher->shouldReceive('launch')->once()->with($run->id)->andReturn(['launched' => true]);
        Livewire::test(VideoHealthMonitor::class)->call('retryLaunch', $run->id)->assertNotified('Detached health process launched');
        $this->assertSame(1, VideoHealthRun::count());
    }

    #[DataProvider('ineligibleRetries')]
    public function test_ineligible_retry_is_not_rendered_and_server_refuses_it(array $attributes): void
    {
        $run = $this->runRecord($attributes);
        $before = $run->getAttributes();
        $this->launcher->shouldNotReceive('launch');
        $this->coordinator->shouldNotReceive('admitManual');
        Livewire::test(VideoHealthMonitor::class)->assertDontSee('Retry Launch')
            ->call('retryLaunch', $run->id)->assertNotified('Launch retry unavailable');
        $this->assertSame($before, $run->refresh()->getAttributes());
    }

    public static function ineligibleRetries(): array
    {
        $cases = array_map(fn (string $status): array => [['status' => $status]], ['deferred', 'launching', 'running', 'deactivating', 'completed', 'failed', 'aborted']);
        $cases[] = [['mode' => 'audit-deactivate', 'confirmed_at' => null]];
        $cases[] = [['mode' => 'audit-deactivate', 'trigger' => 'scheduled', 'confirmed_at' => '2026-10-14 12:00:00']];

        return $cases;
    }

    public function test_latest_counters_pid_timestamps_and_limited_newest_first_history_render_without_workload(): void
    {
        for ($i = 0; $i < 12; $i++) {
            $this->runRecord(['status' => 'completed']);
        }
        $latest = $this->runRecord(['provider' => 'redtube', 'status' => 'running', 'stage' => 'finalizing',
            'selected_count' => 123, 'scanned_count' => 120, 'broken_count' => 10, 'deactivated_count' => 7,
            'process_id' => 456789, 'started_at' => now(), 'heartbeat_at' => now(), 'message' => 'Operational fixture']);
        $this->launcher->shouldNotReceive('launch');
        $this->coordinator->shouldNotReceive('admitManual');
        Livewire::test(VideoHealthMonitor::class)->assertSee('finalizing')->assertSee('Deactivated')
            ->assertSee('456789')->assertSee('Operational fixture')->assertSee('Heartbeat')
            ->assertViewHas('latestRun', fn ($run): bool => $run->id === $latest->id)
            ->assertViewHas('recentRuns', fn ($runs): bool => $runs->count() === 10 && $runs->pluck('id')->all() === range($latest->id, $latest->id - 9))
            ->call('refreshStatus')->assertSet('refreshKey', 1);
        $this->assertSame(13, VideoHealthRun::count());
    }

    public function test_source_orchestrates_only_existing_services_and_read_only_status_polling(): void
    {
        $source = file_get_contents(app_path('Filament/Pages/VideoHealthMonitor.php'));
        foreach (['VideoHealthRun::create', 'VideoHealthAuditRunner', 'EmbedHealthProbe', 'EmbedHealthClassifier', 'VideoHealthDeactivationGate', 'Artisan::', 'Process::', 'Video::', 'file_get_contents(', 'fopen(', 'admitScheduled', "'is_active' =>"] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $source);
        }
        $view = file_get_contents(resource_path('views/filament/pages/video-health-monitor.blade.php'));
        $this->assertStringContainsString('wire:poll.5s="refreshStatus"', $view);
        $this->assertFalse(config('video-health.rollout.automatic_deactivation_enabled'));
    }
}
