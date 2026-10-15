<?php

namespace Tests\Feature;

use App\Models\Video;
use App\Models\VideoHealthRun;
use App\Services\VideoHealth\EmbedHealthProbe;
use App\Services\VideoHealth\VideoHealthAuditRunner;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class RunVideoHealthAuditCommandTest extends TestCase
{
    use RefreshDatabase {
        migrateFreshUsing as private defaultMigrateFreshUsing;
    }

    protected function migrateFreshUsing(): array
    {
        return array_merge($this->defaultMigrateFreshUsing(), [
            '--path' => array_map(fn (string $file): string => database_path('migrations/'.$file), [
                '2026_07_10_102948_create_categories_table.php',
                '2026_07_10_105917_create_videos_table.php',
                '2026_10_12_160000_create_video_health_runs_table.php',
            ]),
            '--realpath' => true,
        ]);
    }

    private function runRecord(array $attributes = []): VideoHealthRun
    {
        return VideoHealthRun::create(array_replace([
            'provider' => 'all', 'trigger' => 'manual',
            'classifier_version' => config('video-health.classifier_version'),
            'config_sha256' => hash('sha256', json_encode(config('video-health'), JSON_THROW_ON_ERROR)),
        ], $attributes))->refresh();
    }

    private function snapshot(): array
    {
        return [DB::table('video_health_runs')->get()->toJson(), DB::table('videos')->get()->toJson()];
    }

    public function test_positive_id_invokes_runner_and_completed_result_exits_zero_without_independent_mutations(): void
    {
        $run = $this->runRecord();
        Video::create([
            'title' => 'Fixture', 'slug' => 'fixture', 'video_source' => 'xvideos',
            'embed_url' => 'https://www.xvideos.com/embedframe/test', 'is_active' => true,
        ]);
        $snapshot = $this->snapshot();
        $returned = clone $run;
        $returned->status = 'completed';
        $returned->scanned_count = 4;
        $runner = Mockery::mock(VideoHealthAuditRunner::class);
        $runner->shouldReceive('run')->once()->with($run->id)->andReturn($returned);
        $this->app->instance(VideoHealthAuditRunner::class, $runner);
        $this->artisan('video-health:run', ['run_id' => (string) $run->id])
            ->expectsOutput('Audit '.$run->id.' completed: 4 videos scanned; no videos changed.')
            ->assertExitCode(0);
        $this->assertSame($snapshot, $this->snapshot());
        $this->assertSame(1, VideoHealthRun::count());
    }

    #[DataProvider('invalidIds')]
    public function test_invalid_id_is_rejected_without_calling_runner_or_creating_run(string $value): void
    {
        $runner = Mockery::mock(VideoHealthAuditRunner::class);
        $runner->shouldNotReceive('run');
        $this->app->instance(VideoHealthAuditRunner::class, $runner);
        $this->artisan('video-health:run', ['run_id' => $value])
            ->expectsOutput('Run ID must be a positive integer.')
            ->assertExitCode(1);
        $this->assertSame(0, VideoHealthRun::count());
        $this->assertSame(0, Video::count());
    }

    public static function invalidIds(): array
    {
        return array_map(fn (string $value): array => [$value], ['0', '-1', 'abc', '1.5', '01', '+1', ' 1', '999999999999999999999999']);
    }

    #[DataProvider('failedResults')]
    public function test_noncompleted_result_exits_nonzero_without_creating_runs(string $status): void
    {
        $run = $this->runRecord();
        $snapshot = $this->snapshot();
        $returned = clone $run;
        $returned->status = $status;
        $runner = Mockery::mock(VideoHealthAuditRunner::class);
        $runner->shouldReceive('run')->once()->with($run->id)->andReturn($returned);
        $this->app->instance(VideoHealthAuditRunner::class, $runner);
        $this->artisan('video-health:run', ['run_id' => $run->id])
            ->expectsOutput('Audit did not complete; inspect the run and private artifacts.')
            ->assertExitCode(1);
        $this->assertSame($snapshot, $this->snapshot());
    }

    public static function failedResults(): array
    {
        return [['failed'], ['running'], ['deferred']];
    }

    #[DataProvider('exceptionTypes')]
    public function test_refusal_and_unexpected_execution_failure_exit_nonzero(string $type): void
    {
        $run = $this->runRecord();
        $snapshot = $this->snapshot();
        $exception = $type === 'refusal' ? new DomainException('Only queued audit-only runs may execute.') : new RuntimeException('SECRET database payload');
        $runner = Mockery::mock(VideoHealthAuditRunner::class);
        $runner->shouldReceive('run')->once()->with($run->id)->andThrow($exception);
        $this->app->instance(VideoHealthAuditRunner::class, $runner);
        $this->artisan('video-health:run', ['run_id' => $run->id])
            ->expectsOutput($type === 'refusal' ? $exception->getMessage() : 'Audit execution failed; inspect the run and private artifacts. Operator review is required if deactivation was requested.')
            ->assertExitCode(1);
        $this->assertSame($snapshot, $this->snapshot());
    }

    public static function exceptionTypes(): array
    {
        return [['refusal'], ['unexpected']];
    }

    public function test_command_resolves_real_runner_and_refuses_deferred_without_network_or_admission(): void
    {
        $run = $this->runRecord(['status' => 'deferred']);
        $snapshot = $this->snapshot();
        $probe = Mockery::mock(EmbedHealthProbe::class);
        $probe->shouldNotReceive('probe');
        $this->app->instance(EmbedHealthProbe::class, $probe);
        $this->artisan('video-health:run', ['run_id' => $run->id])
            ->expectsOutput('Only queued audit-only or confirmed manual audit-deactivate runs may execute.')
            ->assertExitCode(1);
        $this->assertSame($snapshot, $this->snapshot());
    }

    public function test_completed_audit_deactivate_reports_actual_counter_and_performs_no_independent_mutation(): void
    {
        $run = $this->runRecord(['mode' => 'audit-deactivate', 'confirmed_at' => now()]);
        Video::create(['title' => 'Fixture', 'slug' => 'fixture', 'video_source' => 'xvideos',
            'embed_url' => 'https://www.xvideos.com/embedframe/test', 'is_active' => true]);
        $snapshot = $this->snapshot();
        $returned = clone $run;
        $returned->status = 'completed';
        $returned->scanned_count = 4;
        $returned->deactivated_count = 2;
        $runner = Mockery::mock(VideoHealthAuditRunner::class);
        $runner->shouldReceive('run')->once()->with($run->id)->andReturn($returned);
        $this->app->instance(VideoHealthAuditRunner::class, $runner);
        $this->artisan('video-health:run', ['run_id' => $run->id])
            ->expectsOutput('Audit '.$run->id.' completed: 4 videos scanned; 2 videos deactivated.')
            ->assertExitCode(0);
        $this->assertSame($snapshot, $this->snapshot());
        $this->assertTrue(Video::first()->is_active);
        $this->assertSame(1, VideoHealthRun::count());
    }

    #[DataProvider('deactivationFailures')]
    public function test_deactivation_failure_and_reconciliation_outcomes_exit_nonzero_without_independent_writes(bool $committed): void
    {
        $run = $this->runRecord(['mode' => 'audit-deactivate', 'confirmed_at' => now()]);
        $snapshot = $this->snapshot();
        $returned = clone $run;
        $returned->status = 'failed';
        $returned->deactivated_count = $committed ? 1 : 0;
        $returned->result_summary = ['reconciliation_required' => true, 'gate_result_available' => $committed];
        $runner = Mockery::mock(VideoHealthAuditRunner::class);
        $runner->shouldReceive('run')->once()->with($run->id)->andReturn($returned);
        $this->app->instance(VideoHealthAuditRunner::class, $runner);
        $this->artisan('video-health:run', ['run_id' => $run->id])
            ->expectsOutput('Audit-deactivate did not complete; operator review is required. Deactivation may have committed; inspect private artifacts.')
            ->assertExitCode(1);
        $this->assertSame($snapshot, $this->snapshot());
    }

    public static function deactivationFailures(): array
    {
        return [[false], [true]];
    }

    public function test_reconciliation_exception_is_masked_and_command_cannot_mutate_videos(): void
    {
        $run = $this->runRecord(['mode' => 'audit-deactivate', 'confirmed_at' => now()]);
        $snapshot = $this->snapshot();
        $runner = Mockery::mock(VideoHealthAuditRunner::class);
        $runner->shouldReceive('run')->once()->andThrow(new RuntimeException('SECRET reconciliation payload'));
        $this->app->instance(VideoHealthAuditRunner::class, $runner);
        $this->artisan('video-health:run', ['run_id' => $run->id])
            ->expectsOutput('Audit execution failed; inspect the run and private artifacts. Operator review is required if deactivation was requested.')
            ->assertExitCode(1);
        $this->assertSame($snapshot, $this->snapshot());
        foreach ([app_path('Services/VideoHealth/VideoHealthAuditRunner.php'), app_path('Console/Commands/RunVideoHealthAudit.php')] as $path) {
            $source = file_get_contents($path);
            foreach (["'is_active' => false", '$video->update(', '$video->save(', '->delete(', 'UPDATE videos'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $source);
            }
        }
    }
}
