<?php

namespace Tests\Feature;

use App\Models\Video;
use App\Models\VideoHealthRun;
use App\Services\VideoHealth\EmbedHealthClassifier;
use App\Services\VideoHealth\EmbedHealthProbe;
use App\Services\VideoHealth\VideoHealthArtifacts;
use App\Services\VideoHealth\VideoHealthDeactivationGate;
use Closure;
use DomainException;
use Generator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class VideoHealthDeactivationGateTest extends TestCase
{
    use RefreshDatabase {
        migrateFreshUsing as private defaultMigrateFreshUsing;
    }

    private string $root;
    private EmbedHealthProbe $probe;
    private DeactivationArtifactSpy $artifacts;

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
        $this->root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'video-health-gate-test-'.bin2hex(random_bytes(12));
        $this->probe = Mockery::mock(EmbedHealthProbe::class);
        $this->travelTo(now()->setDate(2026, 10, 14)->startOfDay());
        Bus::fake();
    }

    protected function tearDown(): void
    {
        if (isset($this->root) && is_dir($this->root)) {
            if (!str_starts_with($this->root, sys_get_temp_dir().DIRECTORY_SEPARATOR.'video-health-gate-test-')) {
                throw new RuntimeException('Unsafe test cleanup root.');
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
        $run = VideoHealthRun::create(array_replace([
            'provider' => 'all', 'mode' => 'audit-deactivate', 'trigger' => 'manual',
            'status' => 'running', 'stage' => VideoHealthDeactivationGate::ENTRY_STAGE, 'confirmed_at' => now(),
            'classifier_version' => config('video-health.classifier_version'),
            'config_sha256' => hash('sha256', json_encode(config('video-health'), JSON_THROW_ON_ERROR)),
        ], $attributes));
        $this->artifacts = new DeactivationArtifactSpy($run->id, $this->root);

        return $run->refresh();
    }

    private function video(int $id, bool $active = true): Video
    {
        $video = new Video([
            'title' => 'Exact title '.$id, 'slug' => 'gate-'.$id, 'description' => "Exact\nUnicode 猫",
            'video_source' => 'xvideos', 'embed_url' => 'https://www.xvideos.com/embedframe/'.$id.'?stored=exact',
            'is_active' => $active, 'views' => 7, 'is_hd' => true,
        ]);
        $video->id = $id;
        $video->save();

        return $video;
    }

    private function fingerprint(array $row): string
    {
        return hash('sha256', json_encode([
            'video_id' => $row['video_id'], 'provider' => $row['provider'], 'embed_url' => $row['embed_url'],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function original(Video $video, string $classification = EmbedHealthClassifier::BROKEN): array
    {
        $row = ['video_id' => (int) $video->id, 'provider' => $video->video_source, 'embed_url' => $video->embed_url];

        return $row + ['fingerprint' => $this->fingerprint($row), 'classification' => $classification];
    }

    private function freezeResults(array $rows): void
    {
        $this->artifacts->initializeEmptyJsonl('results.jsonl');
        foreach ($rows as $row) {
            $this->artifacts->appendJsonl('results.jsonl', $row);
        }
        $this->artifacts->finalizeJsonl('results.jsonl');
    }

    private function gate(?EmbedHealthClassifier $classifier = null): VideoHealthDeactivationGate
    {
        return new VideoHealthDeactivationGate($this->probe, $classifier, fn (int $id): VideoHealthArtifacts => $this->artifacts);
    }

    private function evidence(Video $video, array $overrides = []): array
    {
        return array_replace([
            'provider' => $video->video_source, 'effective_url' => $video->embed_url, 'http_status' => 404,
            'redirect_approved' => true, 'response_complete' => true, 'timeout' => false,
            'transport_error' => null, 'body' => 'PRIVATE_RESPONSE_HTML',
        ], $overrides);
    }

    private function reconfirm(Video $video, array $overrides = []): void
    {
        $this->probe->shouldReceive('probe')->once()->with($video->video_source, $video->embed_url)
            ->andReturnUsing(function () use ($video, $overrides): array {
                // Only RefreshDatabase's SQLite test wrapper is open during HTTP.
                $this->assertSame(1, DB::connection()->transactionLevel());

                return $this->evidence($video, $overrides);
            });
    }

    private function tableSnapshot(): array
    {
        return DB::table('videos')->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all();
    }

    private function rows(string $name): array
    {
        if ($name === 'db-backup.jsonl') {
            // Tests inspect exact backup bytes; production gate uses dedicated backup APIs only.
            return array_map(fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR), file($this->artifacts->directory().'/'.$name));
        }

        return iterator_to_array($this->artifacts->streamJsonl($name));
    }

    #[DataProvider('refusedRuns')]
    public function test_refused_runs_are_not_changed_or_probed(array $attributes): void
    {
        $run = $this->runRecord($attributes);
        $before = $run->getAttributes();
        $this->probe->shouldNotReceive('probe');
        try {
            $this->gate()->execute($run->id);
            $this->fail('Ineligible run was accepted.');
        } catch (DomainException) {
            $this->assertSame($before, $run->refresh()->getAttributes());
            $this->assertSame(['.', '..'], scandir($this->artifacts->directory()));
            Bus::assertNothingDispatched();
        }
    }

    public static function refusedRuns(): array
    {
        return [
            'audit-only' => [['mode' => 'audit-only']], 'unconfirmed' => [['confirmed_at' => null]],
            'scheduled' => [['trigger' => 'scheduled']], 'queued' => [['status' => 'queued']],
            'deferred' => [['status' => 'deferred']], 'completed' => [['status' => 'completed']],
            'failed' => [['status' => 'failed']], 'aborted' => [['status' => 'aborted']],
            'deactivating' => [['status' => 'deactivating']], 'launching' => [['status' => 'launching']],
            'scanning stage' => [['stage' => 'scanning']], 'policy version' => [['classifier_version' => 'old']],
            'policy hash' => [['config_sha256' => str_repeat('0', 64)]],
        ];
    }

    public function test_missing_and_non_positive_run_ids_are_refused(): void
    {
        $this->probe->shouldNotReceive('probe');
        foreach ([0, -1, 99999] as $id) {
            try {
                (new VideoHealthDeactivationGate($this->probe))->execute($id);
                $this->fail('Invalid run accepted.');
            } catch (DomainException) {
                $this->assertSame(0, VideoHealthRun::count());
            }
        }
    }

    public function test_exact_candidates_are_re_read_frozen_backed_up_sorted_then_only_activation_changes(): void
    {
        $run = $this->runRecord();
        $nine = $this->video(9);
        $two = $this->video(2);
        $pass = $this->video(3);
        $review = $this->video(4);
        $unverifiable = $this->video(5);
        $inactive = $this->video(6, false);
        $this->freezeResults([
            $this->original($nine), $this->original($pass, 'PASS'), $this->original($review, 'REVIEW'),
            $this->original($two), $this->original($unverifiable, 'UNVERIFIABLE'),
        ]);
        $before = $this->tableSnapshot();
        $runBefore = $run->getAttributes();
        $this->travel(1)->hours();
        $this->reconfirm($nine);
        $this->reconfirm($two);
        $classifier = Mockery::mock(EmbedHealthClassifier::class, [config('video-health')])->makePartial();
        $classifier->shouldReceive('classify')->once()->with($this->evidence($nine))->passthru();
        $classifier->shouldReceive('classify')->once()->with($this->evidence($two))->passthru();
        $updates = 0;
        $candidateSelects = [];
        DB::listen(function ($query) use (&$updates, &$candidateSelects): void {
            if (str_starts_with($query->sql, 'select') && str_contains($query->sql, '"videos"') && str_contains($query->sql, ' in (')) {
                $candidateSelects[] = [$query->sql, $query->bindings];
            }
            if (str_starts_with($query->sql, 'update "videos"')) {
                $updates++;
                $this->assertSame(2, DB::connection()->transactionLevel());
                $this->assertSame(['is_active'], array_values(array_filter(['is_active', 'updated_at'], fn ($key): bool => str_contains($query->sql, '"'.$key.'" ='))));
                $this->assertTrue($this->artifacts->reconfirmationFinalized);
                $this->assertTrue($this->artifacts->backupFinalized);
                $this->assertTrue($this->artifacts->backupVerified);
                $this->assertSame([2, 9], array_column($this->rows('db-backup.jsonl'), 'video_id'));
            }
        });
        $result = $this->gate($classifier)->execute($run->id);
        $this->assertSame(1, $updates);
        $this->assertSame([2, 9], $candidateSelects[0][1]);
        $this->assertStringContainsString('order by "id" asc', $candidateSelects[0][0]);
        $this->assertSame(['results.jsonl', 'reconfirmation.jsonl'], $this->artifacts->reads);
        $expected = $before;
        foreach ($expected as &$row) {
            if (in_array($row['id'], [2, 9], true)) {
                $row['is_active'] = 0;
            }
        }
        unset($row);
        $this->assertSame($expected, $this->tableSnapshot());
        $backups = $this->rows('db-backup.jsonl');
        foreach ($backups as $backup) {
            $this->assertSame(collect($before)->firstWhere('id', $backup['video_id']), $backup['attributes']);
        }
        $this->assertSame(2, $result['original_broken_count']);
        $this->assertSame(2, $result['reconfirmed_count']);
        $this->assertSame(2, $result['eligible_count']);
        $this->assertSame($result['eligible_count'], $result['deactivated_count']);
        $this->assertSame(2, $result['backup_row_count']);
        $this->assertTrue($this->artifacts->verify('db-backup.jsonl', $result['backup_sha256']));
        $this->assertSame(count($before), Video::count());
        $this->assertSame(3, Video::where('is_active', true)->count());
        $this->assertFalse($inactive->refresh()->is_active);
        $this->assertSame($runBefore, $run->refresh()->getAttributes());
        $this->assertSame(1, VideoHealthRun::count());
        Bus::assertNothingDispatched();
        $rows = $this->rows('reconfirmation.jsonl');
        $this->assertCount(2, $rows);
        $this->assertSame([9, 2], array_column($rows, 'video_id'));
        foreach ($rows as $row) {
            $this->assertTrue($row['current_identity_match']);
            $this->assertTrue($row['eligible_for_deactivation']);
            $this->assertSame('BROKEN', $row['fresh_classification']);
            $this->assertSame($this->fingerprint($row), $row['fingerprint']);
            $this->assertArrayNotHasKey('body', $row);
        }
        $this->assertStringNotContainsString('PRIVATE_RESPONSE_HTML', file_get_contents($this->artifacts->directory().'/reconfirmation.jsonl'));
        $this->assertTrue($this->artifacts->verifyChecksumManifest());
    }

    #[DataProvider('identitySkips')]
    public function test_identity_drift_is_recorded_without_network_or_mutation(string $drift, string $reason): void
    {
        $run = $this->runRecord();
        $video = $this->video(1);
        $row = $this->original($video);
        if ($drift === 'fingerprint') {
            $row['fingerprint'] = str_repeat('0', 64);
        } elseif ($drift === 'missing') {
            DB::table('videos')->where('id', 1)->delete();
        } else {
            $attributes = match ($drift) {
                'inactive' => ['is_active' => false], 'provider' => ['video_source' => 'xnxx'],
                'embed' => ['embed_url' => $video->embed_url.'&changed=1'],
            };
            DB::table('videos')->where('id', 1)->update($attributes);
        }
        $this->freezeResults([$row]);
        $before = $this->tableSnapshot();
        $this->probe->shouldNotReceive('probe');
        $result = $this->gate()->execute($run->id);
        $this->assertSame($before, $this->tableSnapshot());
        $this->assertSame(1, $result['skipped_identity_changed_count']);
        $this->assertSame(0, $result['reconfirmed_count']);
        $this->assertSame(0, $result['deactivated_count']);
        $this->assertFalse(file_exists($this->artifacts->directory().'/db-backup.jsonl'));
        $reconfirmed = $this->rows('reconfirmation.jsonl')[0];
        $this->assertSame($reason, $reconfirmed['reason']);
        $this->assertFalse($reconfirmed['current_identity_match']);
        $this->assertNull($reconfirmed['fresh_classification']);
        $this->assertFalse($reconfirmed['eligible_for_deactivation']);
    }

    public static function identitySkips(): array
    {
        return [['missing', 'video_missing'], ['inactive', 'already_inactive'], ['provider', 'provider_changed'],
            ['embed', 'embed_url_changed'], ['fingerprint', 'fingerprint_changed']];
    }

    #[DataProvider('freshOutcomes')]
    public function test_fresh_non_broken_and_uncertain_evidence_never_deactivates(array $evidence, string $classification): void
    {
        $run = $this->runRecord();
        $video = $this->video(1);
        $this->freezeResults([$this->original($video)]);
        $before = $this->tableSnapshot();
        $this->reconfirm($video, $evidence);
        $result = $this->gate()->execute($run->id);
        $this->assertSame($before, $this->tableSnapshot());
        $this->assertSame(0, $result['eligible_count']);
        $this->assertSame(0, $result['deactivated_count']);
        $this->assertSame(1, $result['reconfirmed_count']);
        $this->assertSame(1, $result['skipped_not_broken_count']);
        $this->assertSame($classification, $this->rows('reconfirmation.jsonl')[0]['fresh_classification']);
        $this->assertFalse(file_exists($this->artifacts->directory().'/db-backup.jsonl'));
    }

    public static function freshOutcomes(): array
    {
        return [
            'pass' => [['http_status' => 200, 'body' => '"https://media.example/player.mp4"'], 'PASS'],
            'review' => [['http_status' => 200, 'body' => 'ambiguous'], 'REVIEW'],
            'dead plus player' => [['http_status' => 404, 'has_valid_player' => true], 'REVIEW'],
            'timeout' => [['timeout' => true], 'UNVERIFIABLE'],
            'transport' => [['transport_error' => 'HTTP transport failed.'], 'UNVERIFIABLE'],
            '403' => [['http_status' => 403], 'UNVERIFIABLE'], '429' => [['http_status' => 429], 'UNVERIFIABLE'],
            '500' => [['http_status' => 500], 'UNVERIFIABLE'], 'truncated' => [['truncated' => true], 'UNVERIFIABLE'],
        ];
    }

    #[DataProvider('zeroBrokenCases')]
    public function test_zero_original_broken_creates_finalized_empty_reconfirmation(array $classifications): void
    {
        $run = $this->runRecord();
        $video = $this->video(1);
        $this->freezeResults(array_map(fn ($classification): array => $this->original($video, $classification), $classifications));
        $before = $this->tableSnapshot();
        $this->probe->shouldNotReceive('probe');
        $result = $this->gate()->execute($run->id);
        $this->assertSame(0, $result['original_broken_count']);
        $this->assertSame(0, $result['eligible_count']);
        $this->assertSame(0, $result['backup_row_count']);
        $this->assertNull($result['backup_sha256']);
        $this->assertSame($before, $this->tableSnapshot());
        $this->assertSame([], $this->rows('reconfirmation.jsonl'));
        $this->assertSame(['row_count' => 0, 'sha256' => hash('sha256', '')], $this->artifacts->finalizeJsonl('reconfirmation.jsonl'));
        $this->assertTrue((new VideoHealthArtifacts($run->id, $this->root))->verifyChecksumManifest());
        $this->assertFalse(file_exists($this->artifacts->directory().'/db-backup.jsonl'));
    }

    public static function zeroBrokenCases(): array
    {
        return [[[]], [['PASS', 'REVIEW', 'UNVERIFIABLE']]];
    }

    public function test_embed_drift_after_reconfirmation_aborts_all_candidates_before_backup(): void
    {
        $run = $this->runRecord();
        $one = $this->video(1);
        $two = $this->video(2);
        $this->freezeResults([$this->original($one), $this->original($two)]);
        $this->reconfirm($one);
        $this->reconfirm($two);
        $this->artifacts->afterReconfirmation = function (): void {
            $this->assertSame(1, DB::connection()->transactionLevel());
            DB::table('videos')->where('id', 2)->update(['embed_url' => 'https://www.xvideos.com/embedframe/changed']);
        };
        $this->assertGateFails($run, 'Candidate identity drifted');
        $this->assertSame(2, Video::where('is_active', true)->count());
        $this->assertSame('https://www.xvideos.com/embedframe/changed', $two->refresh()->embed_url);
        $this->assertFalse(file_exists($this->artifacts->directory().'/db-backup.jsonl'));
    }

    public function test_candidates_are_read_from_frozen_outcomes_instead_of_a_previous_list(): void
    {
        $run = $this->runRecord();
        $video = $this->video(1);
        $this->freezeResults([$this->original($video)]);
        $this->reconfirm($video);
        $this->artifacts->candidateMode = 'ineligible';
        $result = $this->gate()->execute($run->id);
        $this->assertSame(0, $result['eligible_count']);
        $this->assertTrue($video->refresh()->is_active);
        $this->assertSame(['results.jsonl', 'reconfirmation.jsonl'], $this->artifacts->reads);
        $this->assertFalse($this->artifacts->backupFinalized);
    }

    public function test_duplicate_frozen_candidate_is_rejected_before_mutation(): void
    {
        $run = $this->runRecord();
        $video = $this->video(1);
        $this->freezeResults([$this->original($video)]);
        $this->reconfirm($video);
        $this->artifacts->candidateMode = 'duplicate';
        $this->assertGateFails($run, 'Duplicate frozen candidate');
        $this->assertTrue($video->refresh()->is_active);
        $this->assertFalse($this->artifacts->backupFinalized);
    }

    #[DataProvider('artifactFailures')]
    public function test_artifact_failures_leave_zero_mutations(string $failure): void
    {
        $run = $this->runRecord();
        $one = $this->video(1);
        $two = $this->video(2);
        $this->freezeResults([$this->original($one), $this->original($two)]);
        $before = $this->tableSnapshot();
        $this->artifacts->failure = $failure;
        $this->reconfirm($one);
        $this->reconfirm($two);
        $this->assertGateFails($run);
        $this->assertSame($before, $this->tableSnapshot());
        $this->assertSame('running', $run->refresh()->status);
        if (in_array($failure, ['backup-count', 'backup-verify', 'backup-verify-false'], true)) {
            $reopened = new VideoHealthArtifacts($run->id, $this->root);
            $this->assertTrue($reopened->verifyChecksumManifest());
            $this->assertSame(2, $reopened->finalizeBackup()['row_count']);
            $this->assertCount(2, $this->rows('db-backup.jsonl'));
            try {
                $reopened->startBackup(true);
                $this->fail('Finalized backup was reset.');
            } catch (RuntimeException) {
                $this->assertTrue($one->refresh()->is_active);
            }
        }
        if ($failure === 'reconfirmation-write') {
            $this->assertCount(1, iterator_to_array((new VideoHealthArtifacts($run->id, $this->root))->streamJsonl('reconfirmation.jsonl')));
        }
    }

    public static function artifactFailures(): array
    {
        return array_map(fn ($failure): array => [$failure], [
            'reconfirmation-write', 'reconfirmation-finalize', 'backup-write', 'backup-finalize',
            'backup-count', 'backup-verify', 'backup-verify-false',
        ]);
    }

    #[DataProvider('unexpectedFailures')]
    public function test_unexpected_probe_or_classifier_failure_never_mutates(string $failure): void
    {
        $run = $this->runRecord();
        $video = $this->video(1);
        $this->freezeResults([$this->original($video)]);
        $before = $this->tableSnapshot();
        $classifier = null;
        if ($failure === 'probe') {
            $this->probe->shouldReceive('probe')->once()->andThrow(new RuntimeException('Probe failed.'));
        } else {
            $this->reconfirm($video);
            $classifier = Mockery::mock(EmbedHealthClassifier::class);
            if ($failure === 'classifier') {
                $classifier->shouldReceive('classify')->once()->andThrow(new RuntimeException('Classifier failed.'));
            } else {
                $classifier->shouldReceive('classify')->once()->andReturn('UNKNOWN');
            }
        }
        try {
            $this->gate($classifier)->execute($run->id);
            $this->fail('Unexpected failure ignored.');
        } catch (RuntimeException) {
            $this->assertSame($before, $this->tableSnapshot());
            $this->assertFalse(file_exists($this->artifacts->directory().'/db-backup.jsonl'));
        }
    }

    public static function unexpectedFailures(): array
    {
        return [['probe'], ['classifier'], ['unknown-classification']];
    }

    #[DataProvider('malformedOriginals')]
    public function test_malformed_broken_evidence_fails_closed(array $override): void
    {
        $run = $this->runRecord();
        $video = $this->video(1);
        $this->freezeResults([array_replace($this->original($video), $override)]);
        $this->probe->shouldNotReceive('probe');
        $this->assertGateFails($run);
        $this->assertTrue($video->refresh()->is_active);
    }

    public static function malformedOriginals(): array
    {
        return [[['video_id' => '1']], [['video_id' => 0]], [['provider' => null]], [['provider' => 'unknown']],
            [['embed_url' => null]], [['fingerprint' => null]], [['fingerprint' => 'bad']], [['classification' => 'UNKNOWN']]];
    }

    #[DataProvider('databaseFailures')]
    public function test_later_database_failure_rolls_back_and_preserves_finalized_backup(string $failure): void
    {
        $run = $this->runRecord();
        $one = $this->video(1);
        $two = $this->video(2);
        $this->freezeResults([$this->original($one), $this->original($two)]);
        $before = $this->tableSnapshot();
        $this->reconfirm($one);
        $this->reconfirm($two);
        // SQLite triggers inject real SQL failures/count or post-update integrity drift.
        $sql = match ($failure) {
            'affected-count' => 'CREATE TRIGGER gate_failure BEFORE UPDATE OF is_active ON videos WHEN OLD.id = 2 BEGIN SELECT RAISE(IGNORE); END',
            'inactive-proof' => 'CREATE TRIGGER gate_failure AFTER UPDATE OF is_active ON videos WHEN OLD.id = 2 BEGIN UPDATE videos SET is_active = 1 WHERE id = 2; END',
            'row-count' => 'CREATE TRIGGER gate_failure AFTER UPDATE OF is_active ON videos WHEN OLD.id = 2 BEGIN DELETE FROM videos WHERE id = 2; END',
            'other-column' => 'CREATE TRIGGER gate_failure AFTER UPDATE OF is_active ON videos WHEN OLD.id = 2 BEGIN UPDATE videos SET title = \'changed\' WHERE id = 2; END',
            'exception' => 'CREATE TRIGGER gate_failure BEFORE UPDATE OF is_active ON videos WHEN OLD.id = 2 BEGIN SELECT RAISE(ABORT, \'Injected failure\'); END',
        };
        DB::unprepared($sql);
        $this->assertGateFails($run, 'Deactivation transaction failed');
        $this->assertSame($before, $this->tableSnapshot());
        $reopened = new VideoHealthArtifacts($run->id, $this->root);
        $this->assertSame(2, $reopened->finalizeBackup()['row_count']);
        $this->assertTrue($reopened->verifyChecksumManifest());
        $this->assertCount(2, $this->rows('db-backup.jsonl'));
        $this->assertSame('running', $run->refresh()->status);
    }

    public static function databaseFailures(): array
    {
        return [['affected-count'], ['inactive-proof'], ['row-count'], ['other-column'], ['exception']];
    }

    private function assertGateFails(VideoHealthRun $run, ?string $message = null): void
    {
        try {
            $this->gate()->execute($run->id);
            $this->fail('Unsafe gate execution succeeded.');
        } catch (RuntimeException $exception) {
            if ($message !== null) {
                $this->assertStringContainsString($message, $exception->getMessage().' '.($exception->getPrevious()?->getMessage() ?? ''));
            } else {
                $this->assertNotSame('', $exception->getMessage());
            }
        }
    }

    public function test_existing_finalized_backup_is_not_overwritten(): void
    {
        $run = $this->runRecord();
        $video = $this->video(1);
        $this->freezeResults([$this->original($video)]);
        $this->artifacts->startBackup();
        $this->artifacts->appendBackup(['video_id' => 1, 'attributes' => $video->getAttributes()]);
        $final = $this->artifacts->finalizeBackup();
        $this->reconfirm($video);
        $this->assertGateFails($run);
        $this->assertSame($final['sha256'], $this->artifacts->sha256('db-backup.jsonl'));
        $this->assertTrue($video->refresh()->is_active);
    }

    public function test_source_has_no_artifact_path_bypass_or_lifecycle_writes(): void
    {
        $source = file_get_contents(app_path('Services/VideoHealth/VideoHealthDeactivationGate.php'));
        foreach (['fopen(', 'file_get_contents(', '->directory(', 'unlink(', 'dispatch(', 'switch (', '->save(', '->delete('] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $source);
        }
        $this->assertStringContainsString('->lockForUpdate()', $source);
        $this->assertFalse(config('video-health.rollout.automatic_deactivation_enabled'));
    }
}

class DeactivationArtifactSpy extends VideoHealthArtifacts
{
    public ?string $failure = null;
    public ?string $candidateMode = null;
    public ?Closure $afterReconfirmation = null;
    public bool $reconfirmationFinalized = false;
    public bool $backupFinalized = false;
    public bool $backupVerified = false;
    public array $reads = [];
    private int $reconfirmationWrites = 0;
    private int $backupWrites = 0;

    public function appendJsonl(string $name, array|object $row): void
    {
        if ($name === 'reconfirmation.jsonl' && ++$this->reconfirmationWrites === 2 && $this->failure === 'reconfirmation-write') {
            throw new RuntimeException('Injected partial reconfirmation failure.');
        }
        parent::appendJsonl($name, $row);
    }

    public function finalizeJsonl(string $name): array
    {
        if ($name === 'reconfirmation.jsonl' && $this->failure === 'reconfirmation-finalize') {
            throw new RuntimeException('Injected reconfirmation finalization failure.');
        }
        $final = parent::finalizeJsonl($name);
        if ($name === 'reconfirmation.jsonl') {
            $this->reconfirmationFinalized = true;
            if ($this->afterReconfirmation !== null) {
                ($this->afterReconfirmation)();
            }
        }

        return $final;
    }

    public function streamJsonl(string $name): Generator
    {
        $this->reads[] = $name;
        if ($name === 'reconfirmation.jsonl' && !$this->reconfirmationFinalized) {
            throw new RuntimeException('Reconfirmation read before finalization.');
        }
        foreach (parent::streamJsonl($name) as $row) {
            if ($name === 'reconfirmation.jsonl' && $this->candidateMode === 'ineligible') {
                $row['fresh_classification'] = 'REVIEW';
                $row['eligible_for_deactivation'] = false;
            }
            yield $row;
            if ($name === 'reconfirmation.jsonl' && $this->candidateMode === 'duplicate') {
                yield $row;
            }
        }
    }

    public function appendBackup(array|object $row): void
    {
        if (++$this->backupWrites === 2 && $this->failure === 'backup-write') {
            throw new RuntimeException('Injected backup write failure.');
        }
        parent::appendBackup($row);
    }

    public function finalizeBackup(): array
    {
        if ($this->failure === 'backup-finalize') {
            throw new RuntimeException('Injected backup finalization failure.');
        }
        $final = parent::finalizeBackup();
        $this->backupFinalized = true;
        if ($this->failure === 'backup-count') {
            $final['row_count']++;
        }

        return $final;
    }

    public function verify(string $name, string $expected): bool
    {
        if ($name === 'db-backup.jsonl') {
            if ($this->failure === 'backup-verify') {
                throw new RuntimeException('Injected backup verification failure.');
            }
            if ($this->failure === 'backup-verify-false') {
                return false;
            }
            $this->backupVerified = parent::verify($name, $expected);

            return $this->backupVerified;
        }

        return parent::verify($name, $expected);
    }
}
