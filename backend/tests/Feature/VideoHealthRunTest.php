<?php

namespace Tests\Feature;

use App\Models\VideoHealthRun;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class VideoHealthRunTest extends TestCase
{
    use RefreshDatabase {
        migrateFreshUsing as private defaultMigrateFreshUsing;
    }

    private array $attributes;

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
        $this->attributes = [
            'provider' => 'all',
            'trigger' => 'manual',
            'classifier_version' => 'video-health-classifier-v1',
            'config_sha256' => str_repeat('a', 64),
        ];
    }

    public function test_persists_a_manual_run_with_safe_database_defaults(): void
    {
        $run = VideoHealthRun::create($this->attributes)->refresh();
        expect($run->provider)->toBe('all')->and($run->trigger)->toBe('manual')
            ->and($run->mode)->toBe('audit-only')->and($run->status)->toBe('queued')->and($run->stage)->toBe('queued');
        foreach (['selected_count', 'scanned_count', 'pass_count', 'broken_count', 'review_count', 'unverifiable_count', 'deactivated_count'] as $counter) {
            expect($run->{$counter})->toBe(0);
        }
    }

    public function test_round_trips_nested_JSON_summaries(): void
    {
        $summary = ['counts' => ['pass' => 3], 'notes' => ['first', 'second'], 'complete' => false];
        $run = VideoHealthRun::create($this->attributes + ['result_summary' => $summary])->refresh();
        expect($run->result_summary)->toBe($summary);
    }

    public function test_casts_lifecycle_timestamps_including_audit_deactivate_confirmation(): void
    {
        $dates = ['confirmed_at', 'started_at', 'heartbeat_at', 'finished_at'];
        $attributes = $this->attributes + ['mode' => 'audit-deactivate'];
        foreach ($dates as $date) {
            $attributes[$date] = '2026-10-12 12:00:00';
        }
        $run = VideoHealthRun::create($attributes)->refresh();
        expect($run->mode)->toBe('audit-deactivate');
        foreach ($dates as $date) {
            expect($run->{$date})->toBeInstanceOf(Carbon::class)
                ->and($run->{$date}->format('Y-m-d H:i:s'))->toBe('2026-10-12 12:00:00');
        }
    }

    public function test_keeps_nullable_attribution_and_process_fields_and_casts_stored_integers(): void
    {
        $run = VideoHealthRun::create($this->attributes)->refresh();
        expect($run->created_by)->toBeNull()->and($run->process_id)->toBeNull()->and($run->exit_code)->toBeNull();
        $run->update(['created_by' => '42', 'process_id' => '123', 'exit_code' => '-1']);
        $run->refresh();
        expect($run->created_by)->toBe(42)->and($run->process_id)->toBe(123)->and($run->exit_code)->toBe(-1);
    }

    #[DataProvider('lifecycleStatuses')]
    public function test_agrees_between_lifecycle_predicates_and_database_scopes(string $status, bool $active, bool $terminal): void
    {
        $run = VideoHealthRun::create($this->attributes + ['status' => $status])->refresh();
        expect($run->isActive())->toBe($active)->and($run->isTerminal())->toBe($terminal)
            ->and(VideoHealthRun::active()->whereKey($run->id)->exists())->toBe($active)
            ->and(VideoHealthRun::terminal()->whereKey($run->id)->exists())->toBe($terminal);
    }

    public static function lifecycleStatuses(): array
    {
        return [
            ['queued', true, false], ['launching', true, false], ['running', true, false], ['deactivating', true, false],
            ['deferred', false, false], ['completed', false, true], ['failed', false, true], ['aborted', false, true],
        ];
    }

    public function test_allows_multiple_NULL_schedule_keys(): void
    {
        VideoHealthRun::create($this->attributes);
        VideoHealthRun::create($this->attributes);
        expect(VideoHealthRun::whereNull('schedule_key')->count())->toBe(2);
    }

    public function test_rejects_duplicate_non_null_schedule_keys_at_the_database_layer(): void
    {
        VideoHealthRun::create($this->attributes + ['schedule_key' => 'redtube:2026-10-12']);
        expect(fn () => VideoHealthRun::create($this->attributes + ['schedule_key' => 'redtube:2026-10-12']))
            ->toThrow(QueryException::class);
    }

    #[DataProvider('counters')]
    public function test_rejects_each_negative_counter_even_through_direct_inserts(string $counter): void
    {
        expect(fn () => DB::table('video_health_runs')->insert($this->attributes + [$counter => -1]))
            ->toThrow(QueryException::class);
    }

    public static function counters(): array
    {
        return array_map(fn (string $counter): array => [$counter], [
            'selected_count', 'scanned_count', 'pass_count', 'broken_count', 'review_count', 'unverifiable_count', 'deactivated_count',
        ]);
    }

    public function test_rejects_deactivation_counts_greater_than_broken_counts(): void
    {
        expect(fn () => DB::table('video_health_runs')->insert($this->attributes + ['broken_count' => 2, 'deactivated_count' => 3]))
            ->toThrow(QueryException::class);
    }

    public function test_allows_partial_progress_and_matching_broken_and_deactivated_counts(): void
    {
        $run = VideoHealthRun::create($this->attributes + ['scanned_count' => 10, 'broken_count' => 2, 'deactivated_count' => 2])->refresh();
        expect($run->scanned_count)->toBe(10)->and($run->pass_count)->toBe(0)->and($run->deactivated_count)->toBe(2);
    }

    #[DataProvider('lifecycleFields')]
    public function test_rejects_invalid_constrained_lifecycle_values(string $field): void
    {
        $attributes = array_replace($this->attributes, [$field => 'invalid']);
        expect(fn () => DB::table('video_health_runs')->insert($attributes))->toThrow(QueryException::class);
    }

    public static function lifecycleFields(): array
    {
        return [['provider'], ['mode'], ['trigger'], ['status']];
    }

    public function test_has_the_practical_lifecycle_indexes(): void
    {
        $indexes = collect(Schema::getIndexes('video_health_runs'));
        foreach ([['status', 'created_at'], ['provider', 'created_at'], ['heartbeat_at']] as $columns) {
            expect($indexes->contains(fn (array $index) => $index['columns'] === $columns))->toBeTrue();
        }
        expect($indexes->contains(fn (array $index) => $index['columns'] === ['schedule_key'] && $index['unique']))->toBeTrue();
    }
}
