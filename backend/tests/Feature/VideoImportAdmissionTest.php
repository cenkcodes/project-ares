<?php

namespace Tests\Feature;

use App\Filament\Pages\VideoImportCenter;
use App\Jobs\LaunchVideoImportJob;
use App\Models\Category;
use App\Models\User;
use App\Models\VideoImportJob;
use App\Models\VideoProvider;
use App\Services\VideoHealth\VideoHealthRunCoordinator;
use App\Services\VideoWorkloadAdmission;
use Filament\Schemas\Schema;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class VideoImportAdmissionTest extends TestCase
{
    use RefreshDatabase {
        migrateFreshUsing as private defaultMigrateFreshUsing;
    }

    protected function migrateFreshUsing(): array
    {
        return array_merge($this->defaultMigrateFreshUsing(), [
            '--path' => array_map(fn (string $file): string => database_path('migrations/'.$file), [
                '2026_07_10_102948_create_categories_table.php',
                '2026_08_17_125156_create_video_providers_table.php',
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
        Queue::fake();
        VideoProvider::create(['name' => 'Eporner', 'slug' => 'eporner', 'is_active' => true]);
        Category::create(['name' => 'Test', 'slug' => 'test', 'is_active' => true]);
    }

    private function page(array $overrides = []): VideoImportCenter
    {
        // Exercise the real action with validated form state, without rendering unrelated admin views.
        $data = array_replace([
            'source' => 'eporner', 'category' => 'test', 'mode' => 'prepare-only',
            'minimum_count' => 10, 'target_count' => 20, 'search_depth' => 'auto',
            'start_page' => 0, 'import_confirmation' => null,
        ], $overrides);
        $schema = Mockery::mock(Schema::class);
        $schema->shouldReceive('getState')->once()->andReturn($data);
        $page = new class extends VideoImportCenter {
            public Schema $testSchema;

            public function __get($property): mixed
            {
                return $property === 'form' ? $this->testSchema : parent::__get($property);
            }
        };
        $page->testSchema = $schema;
        $page->data = $data;

        return $page;
    }

    #[DataProvider('healthStatuses')]
    public function test_import_rejects_each_active_health_status_without_dispatch(string $status): void
    {
        $run = app(VideoHealthRunCoordinator::class)->admitManual(['provider' => 'all', 'mode' => 'audit-only'])['run'];
        $run->update(['status' => $status]);
        $this->page()->startJob();
        $this->assertSame(0, VideoImportJob::count());
        Queue::assertNothingPushed();
        $this->assertSame('A video health run is already active', collect(session('filament.notifications'))->last()['title']);
    }

    public static function healthStatuses(): array
    {
        return [['queued'], ['launching'], ['running'], ['deactivating']];
    }

    #[DataProvider('importStatuses')]
    public function test_import_rejects_each_active_import_status_without_dispatch(string $status): void
    {
        VideoImportJob::create([
            'source' => 'eporner', 'category_slug' => 'test', 'mode' => 'prepare-only',
            'minimum_count' => 10, 'target_count' => 20, 'status' => $status,
        ]);
        $this->page()->startJob();
        $this->assertSame(1, VideoImportJob::count());
        Queue::assertNothingPushed();
        $this->assertSame('Another video import job is already active', collect(session('filament.notifications'))->last()['title']);
    }

    public static function importStatuses(): array
    {
        return [['queued'], ['launching'], ['running']];
    }

    #[DataProvider('importModes')]
    public function test_idle_import_preserves_job_fields_and_confirmation_reset(string $mode, ?string $confirmation, string $depth, int $startPage, int $maxPages): void
    {
        $page = $this->page([
            'mode' => $mode, 'import_confirmation' => $confirmation,
            'search_depth' => $depth, 'start_page' => $startPage,
        ]);
        $page->startJob();
        $job = VideoImportJob::sole();
        $this->assertSame('eporner', $job->source);
        $this->assertSame('test', $job->category_slug);
        $this->assertSame($mode, $job->mode);
        $this->assertSame(10, $job->minimum_count);
        $this->assertSame(20, $job->target_count);
        $this->assertSame($maxPages, $job->max_pages);
        $this->assertSame($startPage, $job->start_page);
        $this->assertSame('queued', $job->status);
        $this->assertSame('queued', $job->stage);
        $this->assertSame(0, $job->progress_current);
        $this->assertSame(20, $job->progress_total);
        $this->assertSame('Waiting for launcher queue worker.', $job->message);
        $this->assertNull($job->created_by);
        $this->assertNull($page->data['import_confirmation']);
        $this->assertSame(1, $page->refreshKey);
        Queue::assertPushed(LaunchVideoImportJob::class, fn (LaunchVideoImportJob $queued): bool => $queued->videoImportJobId === $job->id);
        Queue::assertPushed(LaunchVideoImportJob::class, 1);
    }

    public static function importModes(): array
    {
        return [
            ['prepare-only', null, 'auto', 0, 40],
            ['import', 'confirmed', '10', 7, 10],
            ['prepare-only', null, '20', 15, 20],
        ];
    }

    public function test_deferred_health_does_not_block_import(): void
    {
        $run = app(VideoHealthRunCoordinator::class)->admitManual(['provider' => 'all', 'mode' => 'audit-only'])['run'];
        $run->update(['status' => 'deferred']);
        $this->page()->startJob();
        $this->assertSame(1, VideoImportJob::count());
        Queue::assertPushed(LaunchVideoImportJob::class, 1);
    }

    public function test_import_preserves_authenticated_creator(): void
    {
        auth()->setUser((new User)->forceFill(['id' => 42]));
        $this->page()->startJob();
        $this->assertSame(42, VideoImportJob::sole()->created_by);
    }

    public function test_admission_failure_shows_no_internal_error_and_dispatches_nothing(): void
    {
        $admission = Mockery::mock(VideoWorkloadAdmission::class);
        $admission->shouldReceive('underExclusiveAdmission')->once()
            ->andThrow(new RuntimeException('Sensitive database error details'));
        $this->app->instance(VideoWorkloadAdmission::class, $admission);
        $this->page()->startJob();
        $notification = collect(session('filament.notifications'))->last();
        $this->assertSame('Unable to queue video import job', $notification['title']);
        $this->assertSame('Please try again or contact an administrator.', $notification['body']);
        $this->assertSame(0, VideoImportJob::count());
        Queue::assertNothingPushed();
    }

    #[DataProvider('invalidInputs')]
    public function test_existing_action_validation_prevents_admission(array $overrides, string $field): void
    {
        try {
            $this->page($overrides)->startJob();
            $this->fail('Expected validation failure.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('data.'.$field, $exception->errors());
        }
        $this->assertSame(0, VideoImportJob::count());
        Queue::assertNothingPushed();
    }

    public static function invalidInputs(): array
    {
        return [
            [['target_count' => 5], 'target_count'], [['start_page' => -1], 'start_page'],
            [['mode' => 'unknown'], 'mode'], [['mode' => 'import'], 'import_confirmation'],
            [['search_depth' => '30'], 'search_depth'], [['source' => 'unknown'], 'source'],
            [['category' => 'unknown'], 'category'],
        ];
    }

    public function test_dispatch_waits_for_actual_outer_commit(): void
    {
        // Remove RefreshDatabase's wrapper and testing callback manager for a real commit boundary.
        DB::rollBack();
        DB::connection()->setTransactionManager(new DatabaseTransactionsManager);
        VideoProvider::create(['name' => 'Eporner', 'slug' => 'eporner']);
        Category::create(['name' => 'Test', 'slug' => 'test']);
        try {
            DB::beginTransaction();
            $this->page()->startJob();
            $this->assertSame(1, VideoImportJob::count());
            Queue::assertNothingPushed();
            DB::commit();
            $this->assertSame(0, DB::transactionLevel());
            Queue::assertPushed(LaunchVideoImportJob::class, 1);
        } finally {
            VideoImportJob::query()->delete();
            VideoProvider::query()->delete();
            Category::query()->delete();
            $this->beginDatabaseTransaction();
        }
    }

    public function test_outer_rollback_discards_import_and_dispatch(): void
    {
        DB::beginTransaction();
        $this->page()->startJob();
        Queue::assertNothingPushed();
        DB::rollBack();
        $this->assertSame(0, VideoImportJob::count());
        Queue::assertNothingPushed();
    }
}
