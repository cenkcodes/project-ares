<?php

namespace Tests\Feature;

use App\Models\Video;
use App\Models\VideoHealthRun;
use App\Services\VideoHealth\EmbedHealthClassifier;
use App\Services\VideoHealth\EmbedHealthProbe;
use App\Services\VideoHealth\VideoHealthArtifacts;
use App\Services\VideoHealth\VideoHealthAuditRunner;
use App\Services\VideoHealth\VideoHealthDeactivationGate;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class VideoHealthAuditRunnerTest extends TestCase
{
    use RefreshDatabase {
        migrateFreshUsing as private defaultMigrateFreshUsing;
    }

    private string $root;
    private EmbedHealthProbe $probe;
    private AuditArtifactSpy $artifacts;

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

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'video-health-audit-test-'.bin2hex(random_bytes(12));
        $this->probe = Mockery::mock(EmbedHealthProbe::class);
        $this->travelTo(Carbon::parse('2026-10-14 12:00:00'));
        Bus::fake();
    }

    protected function tearDown(): void
    {
        VideoHealthRun::flushEventListeners();
        if (isset($this->root) && is_dir($this->root)) {
            if (!str_starts_with($this->root, sys_get_temp_dir().DIRECTORY_SEPARATOR.'video-health-audit-test-')) {
                throw new RuntimeException('Unsafe cleanup root.');
            }
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
        ], $attributes));
    }

    private function video(int $id, string $provider = 'xvideos', bool $active = true): Video
    {
        $video = new Video([
            'title' => 'Test '.$id, 'slug' => 'test-'.$id, 'video_source' => $provider,
            'embed_url' => 'https://www.xvideos.com/embedframe/test'.$id.'?stored=exact', 'is_active' => $active,
        ]);
        $video->id = $id;
        $video->save();

        return $video;
    }

    private function evidence(string $provider, string $url, int $status = 200, string $body = ''): array
    {
        return [
            'provider' => $provider, 'requested_url' => $url, 'effective_url' => $url,
            'http_status' => $status, 'redirect_approved' => true, 'redirect_urls' => [],
            'response_complete' => true, 'truncated' => false, 'timeout' => false, 'transport_error' => null, 'body' => $body,
        ];
    }

    private function runner(VideoHealthRun $run, ?string $failure = null, ?EmbedHealthClassifier $classifier = null, int $progressRows = 2, ?VideoHealthDeactivationGate $gate = null): VideoHealthAuditRunner
    {
        $this->artifacts = new AuditArtifactSpy($run->id, $this->root);
        $this->artifacts->failure = $failure;

        return new VideoHealthAuditRunner($this->probe, $classifier, fn (int $id): VideoHealthArtifacts => $this->artifacts, 2, $progressRows, 10, $gate);
    }

    private function rows(string $name): array
    {
        return array_map(fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR), file($this->artifacts->directory().DIRECTORY_SEPARATOR.$name));
    }

    private function summary(): array
    {
        return json_decode(file_get_contents($this->artifacts->directory().'/summary.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_complete_audit_freezes_supported_active_catalog_and_preserves_every_video_field(): void
    {
        $run = $this->runRecord();
        $videos = [
            $this->video(42, 'redtube'), $this->video(3, 'xvideos'),
            $this->video(20, 'xnxx'), $this->video(12, 'eporner'),
        ];
        $videos[0]->update(['embed_url' => 'https://embed.redtube.com/?id=42']);
        $videos[2]->update(['embed_url' => 'https://www.xnxx.com/embedframe/test20']);
        $videos[3]->update(['embed_url' => 'https://www.eporner.com/embed/test12/']);
        $this->video(4, 'xvideos', false);
        $this->video(5, 'youtube');
        $before = DB::table('videos')->orderBy('id')->get()->toJson();
        $classifier = Mockery::mock(EmbedHealthClassifier::class, [config('video-health')])->makePartial();
        $runner = $this->runner($run, classifier: $classifier);
        $statuses = [3 => 200, 12 => 404, 20 => 200, 42 => 403];
        $calls = [];
        foreach ($videos as $video) {
            $body = $video->id === 3 ? '<script>const video_url="https://cdn.example/test.mp4";</script>PRIVATE_HTML' : 'PRIVATE_HTML';
            $evidence = $this->evidence($video->video_source, $video->embed_url, $statuses[$video->id], $body);
            $classifier->shouldReceive('classify')->once()->with($evidence)->passthru();
            $this->probe->shouldReceive('probe')->once()->with($video->video_source, $video->embed_url)
                ->andReturnUsing(function () use ($video, $evidence, $run, &$calls): array {
                    $calls[] = $video->id;
                    $this->assertSame([3, 12, 20, 42], array_column($this->rows('catalog.jsonl'), 'video_id'));
                    $state = json_decode(file_get_contents($this->artifacts->directory().'/.finalized.json'), true, 512, JSON_THROW_ON_ERROR);
                    $this->assertArrayHasKey('catalog.jsonl', $state);
                    $this->assertSame('running', $run->fresh()->status);
                    // Only RefreshDatabase's test wrapper remains; the claim transaction ended.
                    $this->assertSame(1, DB::transactionLevel());
                    if ($video->id === 20) {
                        $progress = $run->fresh();
                        $this->assertSame(2, $progress->scanned_count);
                        $this->assertTrue($progress->heartbeat_at->gt($progress->started_at));
                    }
                    $this->travel(1)->seconds();

                    return $evidence;
                });
        }
        DB::listen(function ($query): void {
            if (str_starts_with($query->sql, 'update') && in_array('completed', $query->bindings, true)) {
                $this->assertTrue($this->artifacts->summaryFinalized);
            }
        });
        $completed = $runner->run($run->id);
        $this->assertSame([3, 12, 20, 42], $calls);
        foreach (['selected_count' => 4, 'scanned_count' => 4, 'pass_count' => 1, 'broken_count' => 1, 'review_count' => 1, 'unverifiable_count' => 1, 'deactivated_count' => 0] as $counter => $value) {
            $this->assertSame($value, $completed->{$counter});
        }
        $this->assertSame('completed', $completed->status);
        $this->assertSame('completed', $completed->stage);
        $this->assertSame(0, $completed->exit_code);
        $this->assertNotNull($completed->finished_at);
        $this->assertSame($completed->finished_at->toIso8601String(), $completed->heartbeat_at->toIso8601String());
        $this->assertSame($before, DB::table('videos')->orderBy('id')->get()->toJson());
        $catalog = $this->rows('catalog.jsonl');
        foreach ($catalog as $row) {
            $this->assertSame(hash('sha256', json_encode([
                'video_id' => $row['video_id'], 'provider' => $row['provider'], 'embed_url' => $row['embed_url'],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), $row['fingerprint']);
        }
        $results = $this->rows('results.jsonl');
        $this->assertSame(['PASS', 'BROKEN', 'REVIEW', 'UNVERIFIABLE'], array_column($results, 'classification'));
        $this->assertCount(4, $results);
        $this->assertStringNotContainsString('PRIVATE_HTML', file_get_contents($this->artifacts->directory().'/results.jsonl'));
        foreach ($results as $row) {
            $this->assertArrayNotHasKey('body', $row);
            foreach (['embed_url', 'fingerprint', 'effective_url', 'http_status', 'redirect_approved', 'truncated', 'timeout', 'transport_error', 'scanned_at'] as $key) {
                $this->assertArrayHasKey($key, $row);
            }
        }
        $summary = $this->summary();
        $this->assertSame('completed', $summary['status']);
        $this->assertSame(4, $summary['selected']);
        $this->assertSame(4, $summary['scanned']);
        $this->assertSame(1, $summary['pass']);
        $this->assertSame(1, $summary['broken']);
        $this->assertSame(1, $summary['review']);
        $this->assertSame(1, $summary['unverifiable']);
        $this->assertSame(0, $summary['deactivated']);
        $this->assertSame($summary, $completed->result_summary);
        $this->assertSame($this->artifacts->directory(), $completed->artifact_directory);
        $this->assertSame($this->artifacts->sha256('checksums.sha256'), $completed->manifest_sha256);
        $this->assertTrue($this->artifacts->verifyChecksumManifest());
        $events = $this->artifacts->events;
        $this->assertLessThan(array_search('publish', $events), array_search('provisional:completed', $events));
        $this->assertLessThan(array_search('verify', $events), array_search('publish', $events));
        $this->assertLessThan(array_search('seal', $events), array_search('verify', $events));
        $this->assertFileDoesNotExist($this->artifacts->directory().'/db-backup.jsonl');
        $this->assertFileDoesNotExist($this->artifacts->directory().'/reconfirmation.jsonl');
        Bus::assertNothingDispatched();
    }

    #[DataProvider('refusedStates')]
    public function test_refuses_ineligible_lifecycle_states_before_scanning(string $status): void
    {
        $run = $this->runRecord(['status' => $status]);
        $this->probe->shouldNotReceive('probe');
        $this->expectException(DomainException::class);
        $this->runner($run)->run($run->id);
    }

    public static function refusedStates(): array
    {
        return array_map(fn (string $status): array => [$status], ['deferred', 'launching', 'running', 'deactivating', 'completed', 'failed', 'aborted']);
    }

    #[DataProvider('refusedInputs')]
    public function test_refuses_mode_policy_mismatch_and_existing_progress(array $attributes): void
    {
        $run = $this->runRecord($attributes);
        $this->probe->shouldNotReceive('probe');
        try {
            $this->runner($run)->run($run->id);
            $this->fail('Expected refusal.');
        } catch (DomainException) {
            $this->assertSame('queued', $run->fresh()->status);
        }
    }

    public static function refusedInputs(): array
    {
        return array_map(fn (array $attributes): array => [$attributes], [
            ['mode' => 'audit-deactivate'], ['classifier_version' => 'obsolete'],
            ['config_sha256' => str_repeat('0', 64)], ['scanned_count' => 1],
        ]);
    }

    public function test_missing_run_is_explicitly_refused(): void
    {
        $this->probe->shouldNotReceive('probe');
        $runner = new VideoHealthAuditRunner($this->probe);
        $this->expectException(DomainException::class);
        $runner->run(999);
    }

    public function test_provider_filter_uses_stored_url_and_an_empty_catalog_can_complete(): void
    {
        $run = $this->runRecord(['provider' => 'xvideos']);
        $this->video(1, 'redtube');
        $video = $this->video(2);
        $this->probe->shouldReceive('probe')->once()->with('xvideos', $video->embed_url)
            ->andReturn($this->evidence('xvideos', $video->embed_url));
        $this->assertSame(1, $this->runner($run)->run($run->id)->selected_count);
        $empty = $this->runRecord(['provider' => 'eporner']);
        $result = $this->runner($empty)->run($empty->id);
        $this->assertSame('completed', $result->status);
        $this->assertSame(0, $result->selected_count);
        $this->assertSame(0, $result->scanned_count);
        $this->assertSame([], $this->rows('catalog.jsonl'));
        $this->assertSame([], $this->rows('results.jsonl'));
        $this->assertTrue($this->artifacts->verifyChecksumManifest());
    }

    public function test_second_claim_is_refused_before_a_second_scan(): void
    {
        $run = $this->runRecord();
        $video = $this->video(1);
        $runner = $this->runner($run);
        $this->probe->shouldReceive('probe')->once()->andReturnUsing(function () use ($runner, $run, $video): array {
            try {
                $runner->run($run->id);
                $this->fail('Second claimant should be refused.');
            } catch (DomainException) {
                $this->assertSame('running', $run->fresh()->status);
            }

            return $this->evidence('xvideos', $video->embed_url);
        });
        $this->assertSame('completed', $runner->run($run->id)->status);
        $this->expectException(DomainException::class);
        $runner->run($run->id);
    }

    #[DataProvider('partialFailures')]
    public function test_partial_failures_preserve_results_and_only_count_successful_writes(string $failure): void
    {
        $run = $this->runRecord();
        $first = $this->video(1);
        $second = $this->video(2);
        $classifier = Mockery::mock(EmbedHealthClassifier::class);
        $classifier->shouldReceive('classify')->once()->andReturn('PASS');
        $this->probe->shouldReceive('probe')->once()->with('xvideos', $first->embed_url)->andReturn($this->evidence('xvideos', $first->embed_url));
        $secondProbe = $this->probe->shouldReceive('probe')->once()->with('xvideos', $second->embed_url);
        if ($failure === 'probe') {
            $secondProbe->andThrow(new RuntimeException('SECRET exception payload'));
        } else {
            $secondProbe->andReturn($this->evidence('xvideos', $second->embed_url));
            $secondClassify = $classifier->shouldReceive('classify')->once();
            if ($failure === 'classifier') {
                $secondClassify->andThrow(new RuntimeException('SECRET classifier payload'));
            } else {
                $secondClassify->andReturn('BROKEN');
            }
        }
        $result = $this->runner($run, $failure === 'results' ? $failure : null, $classifier)->run($run->id);
        $this->assertSame('failed', $result->status);
        $this->assertSame('failed', $result->stage);
        $this->assertSame(1, $result->exit_code);
        $this->assertSame(2, $result->selected_count);
        $this->assertSame(1, $result->scanned_count);
        $this->assertSame(1, $result->pass_count);
        $this->assertSame(0, $result->broken_count);
        $this->assertSame(0, $result->deactivated_count);
        $this->assertCount(2, $this->rows('catalog.jsonl'));
        $this->assertCount(1, $this->rows('results.jsonl'));
        $this->assertSame('failed', $this->summary()['status']);
        $this->assertSame(1, $this->summary()['scanned']);
        $this->assertStringNotContainsString('SECRET', $result->message);
        $this->assertTrue($first->fresh()->is_active);
        $this->assertTrue($second->fresh()->is_active);
    }

    public static function partialFailures(): array
    {
        return [['probe'], ['classifier'], ['results']];
    }

    #[DataProvider('publicationFailures')]
    public function test_final_publication_failure_never_completes_the_database_and_replaces_provisional_summary(string $failure): void
    {
        $run = $this->runRecord();
        $video = $this->video(1);
        $this->probe->shouldReceive('probe')->once()->andReturn($this->evidence('xvideos', $video->embed_url));
        $result = $this->runner($run, $failure)->run($run->id);
        $this->assertSame('failed', $result->status);
        $this->assertSame(1, $result->scanned_count);
        $this->assertSame(1, $result->review_count);
        $this->assertSame('failed', $this->summary()['status']);
        $state = json_decode(file_get_contents($this->artifacts->directory().'/.finalized.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertArrayNotHasKey('summary.json', $state);
        $this->assertCount(1, $this->rows('results.jsonl'));
    }

    public static function publicationFailures(): array
    {
        return [['checksum'], ['verification'], ['summary-finalization']];
    }

    public function test_catalog_failure_prevents_any_probe_and_persists_actual_partial_selection(): void
    {
        $run = $this->runRecord();
        $this->video(1);
        $this->video(2);
        $this->probe->shouldNotReceive('probe');
        $result = $this->runner($run, 'catalog')->run($run->id);
        $this->assertSame('failed', $result->status);
        $this->assertSame(1, $result->selected_count);
        $this->assertSame(0, $result->scanned_count);
        $this->assertCount(1, $this->rows('catalog.jsonl'));
        $this->assertSame([], $this->rows('results.jsonl'));
    }

    public function test_artifact_factory_failure_marks_claimed_run_failed_without_http(): void
    {
        $run = $this->runRecord();
        $this->probe->shouldNotReceive('probe');
        $runner = new VideoHealthAuditRunner($this->probe, artifactFactory: function (): VideoHealthArtifacts {
            throw new RuntimeException('SECRET storage error');
        });
        $result = $runner->run($run->id);
        $this->assertSame('failed', $result->status);
        $this->assertSame(0, $result->scanned_count);
        $this->assertStringNotContainsString('SECRET', $result->message);
    }

    public function test_counter_mismatch_cannot_be_published_as_completed(): void
    {
        $run = $this->runRecord();
        $this->probe->shouldNotReceive('probe');
        $result = $this->runner($run, 'counter')->run($run->id);
        $this->assertSame('failed', $result->status);
        $this->assertSame(0, $result->scanned_count);
        $this->assertSame('failed', $this->summary()['status']);
    }

    public function test_structured_timeout_is_classified_without_failing_the_runner(): void
    {
        $run = $this->runRecord();
        $video = $this->video(1);
        $evidence = $this->evidence('xvideos', $video->embed_url);
        $evidence['timeout'] = true;
        $evidence['transport_error'] = 'HTTP transport failed.';
        $this->probe->shouldReceive('probe')->once()->andReturn($evidence);
        $result = $this->runner($run)->run($run->id);
        $this->assertSame('completed', $result->status);
        $this->assertSame(1, $result->unverifiable_count);
    }

    public function test_progress_flush_failure_retains_the_result_that_was_already_persisted(): void
    {
        $run = $this->runRecord();
        $video = $this->video(1);
        $this->probe->shouldReceive('probe')->once()->andReturn($this->evidence('xvideos', $video->embed_url));
        VideoHealthRun::updating(function (VideoHealthRun $model): void {
            if ($model->stage === 'scanning' && $model->scanned_count === 1) {
                throw new RuntimeException('SECRET progress persistence error');
            }
        });
        $result = $this->runner($run, progressRows: 1)->run($run->id);
        $this->assertSame('failed', $result->status);
        $this->assertSame(1, $result->scanned_count);
        $this->assertSame(1, $result->review_count);
        $this->assertCount(1, $this->rows('results.jsonl'));
        $this->assertSame(1, $this->summary()['scanned']);
        $this->assertStringNotContainsString('SECRET', $result->message);
    }

    public function test_invalid_classifier_output_fails_without_counting_an_unwritten_result(): void
    {
        $run = $this->runRecord();
        $video = $this->video(1);
        $this->probe->shouldReceive('probe')->once()->andReturn($this->evidence('xvideos', $video->embed_url));
        $classifier = Mockery::mock(EmbedHealthClassifier::class);
        $classifier->shouldReceive('classify')->once()->andReturn('UNKNOWN');
        $result = $this->runner($run, classifier: $classifier)->run($run->id);
        $this->assertSame('failed', $result->status);
        $this->assertSame(0, $result->scanned_count);
        $this->assertSame([], $this->rows('results.jsonl'));
    }

    #[DataProvider('completionPersistenceFailures')]
    public function test_database_completion_failure_is_explicit_and_does_not_unfinalize_summary(string $failure): void
    {
        $run = $this->runRecord();
        $this->probe->shouldNotReceive('probe');
        VideoHealthRun::updating(function (VideoHealthRun $model) use ($failure): ?bool {
            if ($model->status === 'completed') {
                if ($failure === 'exception') {
                    throw new RuntimeException('SECRET database failure');
                }

                return false;
            }

            return null;
        });
        try {
            $this->runner($run)->run($run->id);
            $this->fail('Expected explicit completion persistence failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Audit artifacts finalized, but database completion could not be persisted.', $exception->getMessage());
        }
        $this->assertSame('running', $run->fresh()->status);
        $this->assertSame('completed', $this->summary()['status']);
        $this->assertTrue($this->artifacts->verifyChecksumManifest());
        $this->expectException(RuntimeException::class);
        $this->artifacts->writeProvisionalSummary(['status' => 'failed']);
    }

    public static function completionPersistenceFailures(): array
    {
        return [['exception'], ['rejected']];
    }

    private function deactivationRun(array $attributes = []): VideoHealthRun
    {
        return $this->runRecord(array_replace(['mode' => 'audit-deactivate', 'confirmed_at' => now()], $attributes));
    }

    private function realGateRunner(VideoHealthRun $run, ?string $postGateFailure = null): VideoHealthAuditRunner
    {
        $gate = Mockery::mock(VideoHealthDeactivationGate::class);
        $gate->shouldReceive('execute')->once()->with($run->id)->andReturnUsing(function () use ($run, $postGateFailure): array {
            $current = $run->fresh();
            $this->assertSame('running', $current->status);
            $this->assertSame(VideoHealthDeactivationGate::ENTRY_STAGE, $current->stage);
            $this->assertSame($current->selected_count, $current->scanned_count);
            $this->assertSame($current->scanned_count, $current->pass_count + $current->broken_count + $current->review_count + $current->unverifiable_count);
            $this->assertSame(0, $current->deactivated_count);
            $this->assertSame($current->scanned_count, $this->artifacts->finalizeJsonl('results.jsonl')['row_count']);
            $this->assertFileDoesNotExist($this->artifacts->directory().'/summary.json');
            $this->artifacts->events[] = 'gate-start';
            $result = (new VideoHealthDeactivationGate($this->probe, artifactFactory: fn (int $id): VideoHealthArtifacts => $this->artifacts))->execute($run->id);
            $this->artifacts->events[] = 'gate-return';
            $this->artifacts->failure = $postGateFailure;

            return $result;
        });

        return $this->runner($run, gate: $gate);
    }

    public function test_audit_only_never_invokes_deactivation_gate(): void
    {
        $run = $this->runRecord();
        $gate = Mockery::mock(VideoHealthDeactivationGate::class);
        $gate->shouldNotReceive('execute');
        $this->probe->shouldNotReceive('probe');
        $result = $this->runner($run, gate: $gate)->run($run->id);
        $this->assertSame('completed', $result->status);
        $this->assertArrayNotHasKey('eligible_count', $result->result_summary);
        $this->assertFileDoesNotExist($this->artifacts->directory().'/reconfirmation.jsonl');
    }

    #[DataProvider('refusedDeactivationRuns')]
    public function test_ineligible_audit_deactivate_is_refused_without_rewriting_run(array $attributes): void
    {
        $run = $this->deactivationRun($attributes)->refresh();
        $before = $run->getAttributes();
        $this->probe->shouldNotReceive('probe');
        $gate = Mockery::mock(VideoHealthDeactivationGate::class);
        $gate->shouldNotReceive('execute');
        try {
            $this->runner($run, gate: $gate)->run($run->id);
            $this->fail('Expected deactivation refusal.');
        } catch (DomainException) {
            $this->assertSame($before, $run->refresh()->getAttributes());
            $this->assertSame(['.', '..'], scandir($this->artifacts->directory()));
        }
    }

    public static function refusedDeactivationRuns(): array
    {
        return array_map(fn (array $attributes): array => [$attributes], [
            ['confirmed_at' => null], ['trigger' => 'scheduled'], ['status' => 'deferred'],
            ['status' => 'running'], ['status' => 'completed'], ['status' => 'failed'], ['status' => 'aborted'],
        ]);
    }

    public function test_confirmed_manual_audit_deactivate_uses_shared_scan_and_real_gate_before_publication(): void
    {
        $run = $this->deactivationRun();
        $broken = $this->video(9, 'eporner');
        $broken->update(['embed_url' => 'https://www.eporner.com/embed/9/']);
        $pass = $this->video(2);
        $this->video(3, 'xvideos', false);
        $this->video(4, 'youtube');
        $before = DB::table('videos')->orderBy('id')->get()->toArray();
        $this->probe->shouldReceive('probe')->once()->with('xvideos', $pass->embed_url)
            ->andReturnUsing(function () use ($pass): array {
                $this->assertSame([2, 9], array_column($this->rows('catalog.jsonl'), 'video_id'));
                $this->assertSame(1, DB::transactionLevel());

                return $this->evidence('xvideos', $pass->embed_url, 200, '"https://media.example/player.mp4"');
            });
        $this->probe->shouldReceive('probe')->twice()->with('eporner', $broken->embed_url)
            ->andReturnUsing(function () use ($broken): array {
                $this->assertSame(1, DB::transactionLevel());

                return $this->evidence('eporner', $broken->embed_url, 404);
            });
        DB::listen(function ($query): void {
            if (str_starts_with($query->sql, 'update') && in_array('completed', $query->bindings, true)) {
                $this->assertTrue($this->artifacts->summaryFinalized);
            }
        });
        $runner = $this->realGateRunner($run);
        $result = $runner->run($run->id);
        $this->assertSame('completed', $result->status);
        $this->assertSame(2, $result->selected_count);
        $this->assertSame(2, $result->scanned_count);
        $this->assertSame(1, $result->broken_count);
        $this->assertSame(1, $result->deactivated_count);
        $this->assertFalse($broken->fresh()->is_active);
        $this->assertTrue($pass->fresh()->is_active);
        foreach ($before as $row) {
            if ($row->id === 9) {
                $row->is_active = 0;
            }
        }
        $this->assertEquals($before, DB::table('videos')->orderBy('id')->get()->toArray());
        $summary = $this->summary();
        foreach (['original_broken_count', 'reconfirmed_count', 'eligible_count', 'deactivated_count', 'backup_row_count'] as $key) {
            $this->assertSame(1, $summary[$key]);
        }
        $this->assertSame(0, $summary['skipped_identity_changed_count']);
        $this->assertSame(0, $summary['skipped_not_broken_count']);
        $this->assertSame($summary, $result->result_summary);
        $this->assertSame(1, $summary['deactivated']);
        $this->assertSame($this->artifacts->sha256('db-backup.jsonl'), $summary['backup_sha256']);
        $this->assertSame($this->artifacts->sha256('checksums.sha256'), $result->manifest_sha256);
        $this->assertTrue($this->artifacts->verifyChecksumManifest());
        $this->assertSame(['catalog.jsonl', 'results.jsonl', 'summary.json', 'reconfirmation.jsonl', 'db-backup.jsonl'], $this->artifacts->publishedNames[0]);
        $manifest = file_get_contents($this->artifacts->directory().'/checksums.sha256');
        $this->assertCount(5, explode("\n", trim($manifest)));
        $this->assertStringNotContainsString('.lock', $manifest);
        $this->assertStringNotContainsString('.finalized.json', $manifest);
        $events = $this->artifacts->events;
        $this->assertLessThan(array_search('provisional:completed', $events), array_search('gate-return', $events));
        $this->assertLessThan(array_search('publish', $events), array_search('provisional:completed', $events));
        $seal = array_search('seal', $events);
        $this->assertSame('verify', $events[$seal - 1]);
        foreach ($this->rows('results.jsonl') as $row) {
            $this->assertArrayHasKey('slug', $row);
            $this->assertArrayHasKey('scanned_at', $row);
            $this->assertSame(hash('sha256', json_encode([
                'video_id' => $row['video_id'], 'provider' => $row['provider'], 'embed_url' => $row['embed_url'],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), $row['fingerprint']);
        }
        Bus::assertNothingDispatched();
        $this->assertSame(1, VideoHealthRun::count());
    }

    #[DataProvider('zeroCandidateAudits')]
    public function test_zero_candidates_publish_reconfirmation_without_requiring_backup(bool $empty): void
    {
        $run = $this->deactivationRun();
        if ($empty) {
            $this->probe->shouldNotReceive('probe');
        } else {
            $video = $this->video(1);
            $this->probe->shouldReceive('probe')->twice()->with('xvideos', $video->embed_url)->andReturn(
                $this->evidence('xvideos', $video->embed_url, 404),
                $this->evidence('xvideos', $video->embed_url, 200, '"https://media.example/player.mp4"'),
            );
        }
        $before = DB::table('videos')->get()->toJson();
        $result = $this->realGateRunner($run)->run($run->id);
        $this->assertSame('completed', $result->status);
        $this->assertSame(0, $result->deactivated_count);
        $this->assertSame(0, $result->result_summary['eligible_count']);
        $this->assertNull($result->result_summary['backup_sha256']);
        $this->assertSame($before, DB::table('videos')->get()->toJson());
        $this->assertFileExists($this->artifacts->directory().'/reconfirmation.jsonl');
        $this->assertFileDoesNotExist($this->artifacts->directory().'/db-backup.jsonl');
        $this->assertStringContainsString('  reconfirmation.jsonl', file_get_contents($this->artifacts->directory().'/checksums.sha256'));
        $this->assertTrue($this->artifacts->verifyChecksumManifest());
    }

    public static function zeroCandidateAudits(): array
    {
        return [[true], [false]];
    }

    public function test_gate_exception_marks_failed_and_is_not_retried(): void
    {
        $run = $this->deactivationRun();
        $video = $this->video(1);
        $this->probe->shouldReceive('probe')->once()->andReturn($this->evidence('xvideos', $video->embed_url, 404));
        $gate = Mockery::mock(VideoHealthDeactivationGate::class);
        $gate->shouldReceive('execute')->once()->andThrow(new RuntimeException('SECRET gate failure'));
        $runner = $this->runner($run, gate: $gate);
        $result = $runner->run($run->id);
        $this->assertSame('failed', $result->status);
        $this->assertSame(1, $result->broken_count);
        $this->assertSame(0, $result->deactivated_count);
        $this->assertTrue($video->fresh()->is_active);
        $this->assertFalse($result->result_summary['gate_result_available']);
        $this->assertStringNotContainsString('SECRET', $result->message);
        $this->expectException(DomainException::class);
        $runner->run($run->id);
    }

    #[DataProvider('invalidGateResults')]
    public function test_invalid_gate_results_cannot_complete_or_exceed_database_counter_constraint(array $override): void
    {
        $run = $this->deactivationRun();
        $video = $this->video(1);
        $this->probe->shouldReceive('probe')->once()->andReturn($this->evidence('xvideos', $video->embed_url, 404));
        $returned = array_replace([
            'original_broken_count' => 1, 'reconfirmed_count' => 1, 'eligible_count' => 0, 'deactivated_count' => 0,
            'skipped_identity_changed_count' => 0, 'skipped_not_broken_count' => 1, 'backup_row_count' => 0, 'backup_sha256' => null,
        ], $override);
        $gate = Mockery::mock(VideoHealthDeactivationGate::class);
        $gate->shouldReceive('execute')->once()->andReturn($returned);
        $result = $this->runner($run, gate: $gate)->run($run->id);
        $this->assertSame('failed', $result->status);
        $this->assertLessThanOrEqual(1, $result->deactivated_count);
        $this->assertTrue($result->result_summary['reconciliation_required']);
        $this->assertStringContainsString('may already have committed', $result->message);
        $this->assertTrue($video->fresh()->is_active);
    }

    public static function invalidGateResults(): array
    {
        return [[['deactivated_count' => 2]], [['eligible_count' => 1]], [['original_broken_count' => 2]],
            [['reconfirmed_count' => '1']], [['backup_sha256' => 'SECRET invalid hash']]];
    }

    #[DataProvider('preGateFailures')]
    public function test_deactivation_pre_gate_failures_never_call_gate_or_change_videos(string $failure): void
    {
        $run = $this->deactivationRun();
        $first = $this->video(1);
        $second = $this->video(2);
        $before = DB::table('videos')->orderBy('id')->get()->toJson();
        $gate = Mockery::mock(VideoHealthDeactivationGate::class);
        $gate->shouldNotReceive('execute');
        if ($failure === 'catalog') {
            $this->probe->shouldNotReceive('probe');
        } else {
            foreach ([$first, $second] as $video) {
                $this->probe->shouldReceive('probe')->once()->with('xvideos', $video->embed_url)
                    ->andReturn($this->evidence('xvideos', $video->embed_url, 404));
            }
        }
        if ($failure === 'entry-stage-db') {
            VideoHealthRun::updating(function (VideoHealthRun $model): void {
                if ($model->stage === VideoHealthDeactivationGate::ENTRY_STAGE) {
                    throw new RuntimeException('Injected pre-gate stage failure.');
                }
            });
        }
        $result = $this->runner($run, $failure === 'entry-stage-db' ? null : $failure, gate: $gate)->run($run->id);
        $this->assertSame('failed', $result->status);
        $this->assertSame(0, $result->deactivated_count);
        $this->assertSame($before, DB::table('videos')->orderBy('id')->get()->toJson());
        $this->assertFileDoesNotExist($this->artifacts->directory().'/reconfirmation.jsonl');
        $this->assertFileDoesNotExist($this->artifacts->directory().'/db-backup.jsonl');
    }

    public static function preGateFailures(): array
    {
        return [['catalog'], ['results'], ['counter'], ['entry-stage-db']];
    }

    #[DataProvider('postGateFailures')]
    public function test_failures_after_real_deactivation_preserve_mutation_and_evidence_and_require_review(string $failure): void
    {
        $run = $this->deactivationRun();
        $video = $this->video(1);
        $this->probe->shouldReceive('probe')->twice()->andReturn($this->evidence('xvideos', $video->embed_url, 404));
        if (in_array($failure, ['progress-db', 'completion-db', 'completion-rejected', 'all-post-gate-db'], true)) {
            VideoHealthRun::updating(function (VideoHealthRun $model) use ($failure): ?bool {
                if ($model->deactivated_count !== 1) {
                    return null;
                }
                if ($failure === 'all-post-gate-db' || ($failure === 'progress-db' && $model->stage === 'finalizing')
                    || ($failure === 'completion-db' && $model->status === 'completed')) {
                    throw new RuntimeException('SECRET injected database failure');
                }
                if ($failure === 'completion-rejected' && $model->status === 'completed') {
                    return false;
                }

                return null;
            });
        }
        $artifactFailure = in_array($failure, ['summary-write', 'checksum', 'verification', 'summary-finalization'], true) ? $failure : null;
        $runner = $this->realGateRunner($run, $artifactFailure);
        try {
            $result = $runner->run($run->id);
            $this->assertNotSame('all-post-gate-db', $failure);
            $this->assertSame('failed', $result->status);
            $this->assertSame(1, $result->deactivated_count);
            $this->assertTrue($result->result_summary['reconciliation_required']);
            $this->assertStringContainsString('operator review', $result->message);
            $this->assertStringNotContainsString('no videos were changed', $result->message);
            $this->assertStringNotContainsString('SECRET', $result->message);
        } catch (RuntimeException $exception) {
            $this->assertSame('all-post-gate-db', $failure);
            $this->assertStringContainsString('operator review', $exception->getMessage());
            $this->assertSame('running', $run->fresh()->status);
        }
        $this->assertFalse($video->fresh()->is_active);
        $reopened = new VideoHealthArtifacts($run->id, $this->root);
        $backup = $reopened->finalizeBackup();
        $reconfirmation = $reopened->finalizeJsonl('reconfirmation.jsonl');
        $this->assertSame(1, $backup['row_count']);
        $this->assertSame(1, $reconfirmation['row_count']);
        $this->assertTrue($reopened->verify('db-backup.jsonl', $backup['sha256']));
        if (in_array($failure, ['completion-db', 'completion-rejected'], true)) {
            $this->assertSame('completed', $this->summary()['status']);
            $this->assertTrue($reopened->verifyChecksumManifest());
            try {
                $reopened->writeProvisionalSummary(['status' => 'failed']);
                $this->fail('A sealed summary was overwritten after DB completion failed.');
            } catch (RuntimeException) {
                $this->assertSame('completed', $this->summary()['status']);
            }
        }
        try {
            $runner->run($run->id);
            $this->fail('Failed/running deactivation was re-executed.');
        } catch (DomainException) {
            $this->assertSame($backup['sha256'], $reopened->sha256('db-backup.jsonl'));
            $this->assertSame($reconfirmation['sha256'], $reopened->sha256('reconfirmation.jsonl'));
        }
        Bus::assertNothingDispatched();
    }

    public static function postGateFailures(): array
    {
        return array_map(fn (string $failure): array => [$failure], [
            'summary-write', 'checksum', 'verification', 'summary-finalization', 'progress-db',
            'completion-db', 'completion-rejected', 'all-post-gate-db',
        ]);
    }
}

class AuditArtifactSpy extends VideoHealthArtifacts
{
    public ?string $failure = null;
    public array $events = [];
    public bool $summaryFinalized = false;
    public array $publishedNames = [];
    private int $catalogAttempts = 0;
    private int $resultAttempts = 0;

    public function appendJsonl(string $name, array|object $row): void
    {
        if ($name === 'catalog.jsonl' && ++$this->catalogAttempts === 2 && $this->failure === 'catalog') {
            throw new RuntimeException('Injected catalog failure.');
        }
        if ($name === 'results.jsonl' && ++$this->resultAttempts === 2 && $this->failure === 'results') {
            throw new RuntimeException('Injected results failure.');
        }
        parent::appendJsonl($name, $row);
    }

    public function finalizeJsonl(string $name): array
    {
        $result = parent::finalizeJsonl($name);
        if ($name === 'results.jsonl' && $this->failure === 'counter') {
            $result['row_count']++;
        }

        return $result;
    }

    public function writeProvisionalSummary(array|object $summary): void
    {
        $this->events[] = 'provisional:'.$summary['status'];
        if ($summary['status'] === 'completed' && $this->failure === 'summary-write') {
            throw new RuntimeException('Injected completed summary write failure.');
        }
        parent::writeProvisionalSummary($summary);
    }

    public function generateChecksumManifest(array $names): void
    {
        $this->events[] = 'publish';
        $this->publishedNames[] = $names;
        if ($this->failure === 'checksum') {
            throw new RuntimeException('Injected checksum publication failure.');
        }
        parent::generateChecksumManifest($names);
    }

    public function verifyChecksumManifest(): bool
    {
        $this->events[] = 'verify';
        if ($this->failure === 'verification') {
            throw new RuntimeException('Injected checksum verification failure.');
        }

        return parent::verifyChecksumManifest();
    }

    public function finalizeSummary(): string
    {
        $this->events[] = 'seal';
        if ($this->failure === 'summary-finalization') {
            throw new RuntimeException('Injected summary finalization failure.');
        }
        $hash = parent::finalizeSummary();
        $this->summaryFinalized = true;

        return $hash;
    }
}
