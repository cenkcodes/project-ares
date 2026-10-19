<?php

namespace Tests\Feature;

use App\Models\VideoHealthRun;
use App\Models\VideoImportJob;
use App\Services\VideoHealth\VideoHealthRunCoordinator;
use App\Services\VideoWorkloadAdmission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class VideoHealthRunCoordinatorTest extends TestCase
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

    private function manual(array $overrides = []): array
    {
        return app(VideoHealthRunCoordinator::class)->admitManual(array_replace([
            'provider' => 'all', 'mode' => 'audit-only',
        ], $overrides));
    }

    private function scheduled(array $overrides = []): array
    {
        return app(VideoHealthRunCoordinator::class)->admitScheduled(array_replace([
            'provider' => 'all', 'mode' => 'audit-only', 'schedule_key' => 'all:2026-10-13',
        ], $overrides));
    }

    private function import(string $status): void
    {
        VideoImportJob::create([
            'source' => 'eporner', 'category_slug' => 'test', 'mode' => 'prepare-only',
            'minimum_count' => 10, 'target_count' => 20, 'status' => $status,
        ]);
    }

    public function test_manual_audit_only_is_queued_with_config_snapshot_and_attribution(): void
    {
        $result = $this->manual(['created_by' => 42]);
        $run = $result['run']->refresh();
        $this->assertNull($result['reason']);
        $this->assertSame('queued', $run->status);
        $this->assertSame('queued', $run->stage);
        $this->assertSame('manual', $run->trigger);
        $this->assertSame(42, $run->created_by);
        $this->assertNull($run->confirmed_at);
        $this->assertNull($run->schedule_key);
        $this->assertSame(config('video-health.classifier_version'), $run->classifier_version);
        $this->assertSame(hash('sha256', json_encode(config('video-health'), JSON_THROW_ON_ERROR)), $run->config_sha256);
    }

    #[DataProvider('importStatuses')]
    public function test_manual_rejects_each_active_import_status(string $status): void
    {
        $this->import($status);
        $result = $this->manual();
        $this->assertNull($result['run']);
        $this->assertSame(VideoWorkloadAdmission::ACTIVE_IMPORT, $result['reason']);
        $this->assertSame(0, VideoHealthRun::count());
    }

    public static function importStatuses(): array
    {
        return [['queued'], ['launching'], ['running']];
    }

    #[DataProvider('healthStatuses')]
    public function test_manual_rejects_each_active_health_status(string $status): void
    {
        $this->manual()['run']->update(['status' => $status]);
        $result = $this->manual();
        $this->assertNull($result['run']);
        $this->assertSame(VideoWorkloadAdmission::ACTIVE_HEALTH, $result['reason']);
        $this->assertSame(1, VideoHealthRun::count());
    }

    public static function healthStatuses(): array
    {
        return [['queued'], ['launching'], ['running'], ['deactivating']];
    }

    public function test_deferred_health_does_not_block_manual_admission(): void
    {
        $this->manual()['run']->update(['status' => 'deferred']);
        $this->assertSame('queued', $this->manual()['run']->status);
    }

    public function test_manual_deactivation_requires_confirmation_and_stores_it_when_supplied(): void
    {
        $result = $this->manual([
            'provider' => 'xvideos',
            'mode' => 'audit-deactivate',
        ]);
        $this->assertSame(VideoWorkloadAdmission::INVALID_INPUT, $result['reason']);
        $this->assertSame(0, VideoHealthRun::count());

        $run = $this->manual([
            'provider' => 'xvideos',
            'mode' => 'audit-deactivate',
            'confirmed_at' => '2026-10-13 12:00:00',
        ])['run']->refresh();

        $this->assertSame('audit-deactivate', $run->mode);
        $this->assertSame('2026-10-13 12:00:00', $run->confirmed_at->format('Y-m-d H:i:s'));
    }

    public function test_manual_all_provider_deactivation_is_always_rejected(): void
    {
        $result = $this->manual([
            'provider' => 'all',
            'mode' => 'audit-deactivate',
            'confirmed_at' => '2026-10-13 12:00:00',
        ]);

        $this->assertSame(VideoWorkloadAdmission::INVALID_INPUT, $result['reason']);
        $this->assertNull($result['run']);
        $this->assertSame(0, VideoHealthRun::count());
    }

    public function test_scheduled_is_queued_when_idle_and_reuses_schedule_key(): void
    {
        $first = $this->scheduled()['run'];
        $again = $this->scheduled()['run'];
        $this->assertSame('scheduled', $first->trigger);
        $this->assertSame('queued', $first->status);
        $this->assertSame($first->id, $again->id);
        $this->assertSame(1, VideoHealthRun::count());
    }

    public function test_scheduled_defers_for_import_and_reuses_existing_deferred_without_promoting(): void
    {
        $this->import('running');
        $result = $this->scheduled();
        $this->assertSame(VideoWorkloadAdmission::ACTIVE_IMPORT, $result['reason']);
        $this->assertSame('deferred', $result['run']->status);
        $this->assertFalse($result['run']->isActive());
        VideoImportJob::query()->update(['status' => 'pass']);
        $again = $this->scheduled()['run'];
        $this->assertSame($result['run']->id, $again->id);
        $this->assertSame('deferred', $again->status);
        $this->assertSame(1, VideoHealthRun::count());
    }

    public function test_scheduled_defers_for_active_health(): void
    {
        $this->manual();
        $result = $this->scheduled();
        $this->assertSame(VideoWorkloadAdmission::ACTIVE_HEALTH, $result['reason']);
        $this->assertSame('deferred', $result['run']->status);
    }

    public function test_scheduled_deactivation_is_disabled_by_default(): void
    {
        $this->assertFalse(config('video-health.rollout.automatic_deactivation_enabled'));

        $result = $this->scheduled([
            'provider' => 'xvideos',
            'mode' => 'audit-deactivate',
            'schedule_key' => 'xvideos:2026-10-13',
        ]);

        $this->assertSame(VideoWorkloadAdmission::AUTOMATIC_DEACTIVATION_DISABLED, $result['reason']);
        $this->assertNull($result['run']);
        $this->assertSame(0, VideoHealthRun::count());
    }

    public function test_enabled_scheduled_deactivation_does_not_need_human_confirmation(): void
    {
        config(['video-health.rollout.automatic_deactivation_enabled' => true]);

        $run = $this->scheduled([
            'provider' => 'xvideos',
            'mode' => 'audit-deactivate',
            'schedule_key' => 'xvideos:2026-10-13',
        ])['run']->refresh();

        $this->assertSame('queued', $run->status);
        $this->assertNull($run->confirmed_at);
    }

    public function test_scheduled_all_provider_deactivation_is_rejected_even_when_enabled(): void
    {
        config(['video-health.rollout.automatic_deactivation_enabled' => true]);

        $result = $this->scheduled([
            'provider' => 'all',
            'mode' => 'audit-deactivate',
        ]);

        $this->assertSame(VideoWorkloadAdmission::INVALID_INPUT, $result['reason']);
        $this->assertNull($result['run']);
        $this->assertSame(0, VideoHealthRun::count());
    }

    #[DataProvider('invalidInputs')]
    public function test_invalid_input_creates_no_run(bool $scheduled, array $input): void
    {
        $result = $scheduled ? $this->scheduled($input) : $this->manual($input);
        $this->assertSame(VideoWorkloadAdmission::INVALID_INPUT, $result['reason']);
        $this->assertNull($result['run']);
        $this->assertSame(0, VideoHealthRun::count());
    }

    public static function invalidInputs(): array
    {
        return [
            [false, ['provider' => 'unknown']], [false, ['mode' => 'unknown']],
            [false, ['created_by' => -1]], [false, ['confirmed_at' => 'invalid']],
            [false, ['schedule_key' => 'manual-key']], [true, ['schedule_key' => '']],
            [true, ['schedule_key' => '   ']], [true, ['schedule_key' => str_repeat('a', 256)]],
        ];
    }

    public function test_coordinator_never_dispatches_any_work(): void
    {
        Bus::fake();
        Queue::fake();
        $this->manual();
        $this->scheduled();
        Bus::assertNothingDispatched();
        Queue::assertNothingPushed();
    }

    private function deferred(array $overrides = []): VideoHealthRun
    {
        $run = $this->scheduled()['run'];
        $run->update(array_replace(['status' => 'deferred', 'stage' => 'deferred'], $overrides));

        return $run->refresh();
    }

    public function test_idle_deferred_promotion_uses_shared_admission_and_preserves_exact_run(): void
    {
        $run = $this->deferred();
        $before = $run->getAttributes();
        $admission = Mockery::mock(VideoWorkloadAdmission::class)->makePartial();
        $admission->shouldReceive('underExclusiveAdmission')->once()->passthru();
        $admission->shouldReceive('activeReason')->once()->andReturnUsing(function (): ?string {
            $this->assertGreaterThan(0, DB::connection()->transactionLevel());

            return null;
        });
        Bus::fake();
        Queue::fake();
        $coordinator = new VideoHealthRunCoordinator($admission);
        $result = $coordinator->promoteDeferredScheduled($run->id);
        $this->assertNull($result['reason']);
        $this->assertSame($run->id, $result['run']->id);
        $this->assertSame('queued', $run->refresh()->status);
        $this->assertSame('queued', $run->stage);
        $after = $run->getAttributes();
        foreach (['status', 'stage', 'updated_at'] as $key) {
            unset($before[$key], $after[$key]);
        }
        $this->assertSame($before, $after);
        $this->assertNull($run->process_id);
        $this->assertNull($run->started_at);
        $this->assertSame(1, VideoHealthRun::count());
        $this->assertSame(VideoWorkloadAdmission::INVALID_INPUT, app(VideoHealthRunCoordinator::class)->promoteDeferredScheduled($run->id)['reason']);
        Bus::assertNothingDispatched();
        Queue::assertNothingPushed();
    }

    #[DataProvider('promotionBusyReasons')]
    public function test_busy_workload_keeps_exact_deferred_row_unchanged(string $workload, string $reason): void
    {
        $run = $this->deferred();
        if ($workload === 'import') {
            $this->import('running');
        } else {
            $this->manual();
        }
        $before = $run->getAttributes();
        $result = app(VideoHealthRunCoordinator::class)->promoteDeferredScheduled($run->id);
        $this->assertSame($reason, $result['reason']);
        $this->assertSame($run->id, $result['run']->id);
        $this->assertSame($before, $run->refresh()->getAttributes());
    }

    public static function promotionBusyReasons(): array
    {
        return [['import', VideoWorkloadAdmission::ACTIVE_IMPORT], ['health', VideoWorkloadAdmission::ACTIVE_HEALTH]];
    }

    #[DataProvider('unsafePromotions')]
    public function test_unsafe_deferred_promotion_never_resets_persisted_state(array $attributes): void
    {
        $run = $this->deferred($attributes);
        $before = $run->getAttributes();
        $result = app(VideoHealthRunCoordinator::class)->promoteDeferredScheduled($run->id);
        $this->assertNull($result['run']);
        $this->assertSame(VideoWorkloadAdmission::INVALID_INPUT, $result['reason']);
        $this->assertSame($before, $run->refresh()->getAttributes());
        $this->assertSame(1, VideoHealthRun::count());
    }

    public static function unsafePromotions(): array
    {
        $cases = [
            [['trigger' => 'manual']], [['mode' => 'audit-deactivate']], [['provider' => 'eporner']],
            [['classifier_version' => 'outdated']], [['config_sha256' => str_repeat('0', 64)]],
            [['stage' => 'scanning']], [['schedule_key' => null]],
        ];
        foreach (['queued', 'launching', 'running', 'deactivating', 'completed', 'failed', 'aborted'] as $status) {
            $cases[] = [['status' => $status]];
        }
        foreach (['started_at', 'finished_at', 'heartbeat_at', 'confirmed_at'] as $field) {
            $cases[] = [[$field => '2026-10-15 12:00:00']];
        }
        foreach (['process_id', 'exit_code', 'created_by'] as $field) {
            $cases[] = [[$field => 1]];
        }
        foreach (['selected_count', 'scanned_count', 'pass_count', 'broken_count', 'review_count', 'unverifiable_count'] as $field) {
            $cases[] = [[$field => 1]];
        }
        $cases[] = [['broken_count' => 1, 'deactivated_count' => 1]];
        foreach (['artifact_directory', 'log_path', 'manifest_sha256', 'message'] as $field) {
            $cases[] = [[$field => 'previous-execution']];
        }
        $cases[] = [['result_summary' => []]];

        return $cases;
    }

    public function test_missing_promotion_target_creates_nothing(): void
    {
        $result = app(VideoHealthRunCoordinator::class)->promoteDeferredScheduled(999);
        $this->assertNull($result['run']);
        $this->assertSame(VideoWorkloadAdmission::INVALID_INPUT, $result['reason']);
        $this->assertSame(0, VideoHealthRun::count());
    }

}
