<?php

namespace App\Services\VideoHealth;

use App\Models\Video;
use App\Models\VideoHealthRun;
use Closure;
use DomainException;
use Generator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class VideoHealthAuditRunner
{
    private const PROVIDERS = ['eporner', 'xvideos', 'xnxx', 'redtube'];
    private const COUNTERS = [
        'selected_count' => 0, 'scanned_count' => 0, 'pass_count' => 0,
        'broken_count' => 0, 'review_count' => 0, 'unverifiable_count' => 0, 'deactivated_count' => 0,
    ];
    private const CLASSIFICATIONS = [
        EmbedHealthClassifier::PASS => 'pass_count',
        EmbedHealthClassifier::BROKEN => 'broken_count',
        EmbedHealthClassifier::REVIEW => 'review_count',
        EmbedHealthClassifier::UNVERIFIABLE => 'unverifiable_count',
    ];

    public function __construct(
        private ?EmbedHealthProbe $probe = null,
        private ?EmbedHealthClassifier $classifier = null,
        private ?Closure $artifactFactory = null,
        private int $chunkSize = 250,
        private int $progressRows = 50,
        private int $heartbeatSeconds = 10,
        private ?VideoHealthDeactivationGate $deactivationGate = null,
    ) {
        if ($chunkSize < 1 || $progressRows < 1 || $heartbeatSeconds < 1) {
            throw new DomainException('Audit intervals must be positive.');
        }
    }

    public function run(int $runId): VideoHealthRun
    {
        $configuration = config('video-health');
        $run = $this->claim($runId, $configuration);
        $counts = self::COUNTERS;
        $artifacts = null;
        $ownsArtifacts = false;
        $summaryFinalized = false;
        $gateEntered = false;
        $gateReturned = false;
        $deactivation = [];
        $manifestHash = null;
        $stage = 'catalog';
        $lastFlush = microtime(true);

        try {
            $artifacts = $this->artifactFactory !== null
                ? ($this->artifactFactory)($run->id)
                : new VideoHealthArtifacts($run->id);
            $this->initializeStreams($artifacts);
            $ownsArtifacts = true;
            $this->persist($run, ['artifact_directory' => $artifacts->directory()]);

            $query = Video::query()->useWritePdo()->where('is_active', true)
                ->whereIn('video_source', $run->provider === VideoHealthRun::PROVIDER_ALL ? self::PROVIDERS : [$run->provider]);
            // Bound the freeze so newly inserted higher IDs cannot extend it indefinitely.
            $maximumId = $query->max('id') ?? 0;
            foreach ($query->where('id', '<=', $maximumId)->select(['id', 'video_source', 'slug', 'embed_url'])
                ->lazyById($this->chunkSize) as $video) {
                $row = ['video_id' => (int) $video->id, 'provider' => $video->video_source, 'slug' => $video->slug, 'embed_url' => $video->embed_url];
                $row['fingerprint'] = $this->fingerprint($row);
                $artifacts->appendJsonl('catalog.jsonl', $row);
                $counts['selected_count']++;
                $this->progress($run, $counts, $stage, $lastFlush, $counts['selected_count']);
            }
            $catalog = $artifacts->finalizeJsonl('catalog.jsonl');
            if ($catalog['row_count'] !== $counts['selected_count']) {
                throw new RuntimeException('Catalog count mismatch.');
            }
            $artifacts->verify('catalog.jsonl', $catalog['sha256']);
            $stage = 'scanning';
            $this->flushProgress($run, $counts, $stage);
            $probe = $this->probe ?? new EmbedHealthProbe($configuration);
            $classifier = $this->classifier ?? new EmbedHealthClassifier($configuration);

            foreach ($this->catalogRows($artifacts) as $row) {
                $evidence = $probe->probe($row['provider'], $row['embed_url']);
                $classification = $classifier->classify($evidence);
                if (!isset(self::CLASSIFICATIONS[$classification])) {
                    throw new RuntimeException('Invalid classifier result.');
                }
                $result = $row + [
                    'classification' => $classification,
                    'effective_url' => $evidence['effective_url'] ?? $row['embed_url'],
                    'http_status' => $evidence['http_status'] ?? 0,
                    'redirect_urls' => $evidence['redirect_urls'] ?? [],
                    'redirect_approved' => $evidence['redirect_approved'] ?? false,
                    'response_complete' => $evidence['response_complete'] ?? false,
                    'truncated' => $evidence['truncated'] ?? false,
                    'timeout' => $evidence['timeout'] ?? false,
                    'transport_error' => $evidence['transport_error'] ?? null,
                    'scanned_at' => now()->toIso8601String(),
                ];
                $artifacts->appendJsonl('results.jsonl', $result);
                $counts['scanned_count']++;
                $counts[self::CLASSIFICATIONS[$classification]]++;
                $this->progress($run, $counts, $stage, $lastFlush, $counts['scanned_count']);
            }
            $artifacts->verify('catalog.jsonl', $catalog['sha256']);
            $results = $artifacts->finalizeJsonl('results.jsonl');
            $classified = $counts['pass_count'] + $counts['broken_count'] + $counts['review_count'] + $counts['unverifiable_count'];
            if ($results['row_count'] !== $counts['scanned_count'] || $classified !== $counts['scanned_count']
                || $counts['scanned_count'] !== $counts['selected_count'] || $counts['deactivated_count'] !== 0) {
                throw new RuntimeException('Audit counter mismatch.');
            }

            $artifactNames = ['catalog.jsonl', 'results.jsonl', 'summary.json'];
            if ($run->mode === VideoHealthRun::MODE_AUDIT_DEACTIVATE) {
                $stage = VideoHealthDeactivationGate::ENTRY_STAGE;
                $this->flushProgress($run, $counts, $stage);
                $gate = $this->deactivationGate ?? new VideoHealthDeactivationGate($probe, $classifier, fn (int $id): VideoHealthArtifacts => $artifacts);
                $gateEntered = true;
                $returned = $gate->execute($run->id);
                // Set this before validation or progress writes: SQL may already be committed.
                $gateReturned = true;
                $deactivation = $this->safeDeactivationResult($returned);
                if (isset($deactivation['deactivated_count']) && $deactivation['deactivated_count'] <= $counts['broken_count']) {
                    $counts['deactivated_count'] = $deactivation['deactivated_count'];
                }
                $this->validateDeactivationResult($returned, $counts['broken_count']);
                $artifactNames[] = 'reconfirmation.jsonl';
                if ($deactivation['eligible_count'] > 0) {
                    $artifactNames[] = 'db-backup.jsonl';
                }
            }
            $stage = 'finalizing';
            if ($gateReturned) {
                $this->persist($run, $counts + ['stage' => $stage, 'heartbeat_at' => now(), 'result_summary' => $deactivation]);
            } else {
                $this->flushProgress($run, $counts, $stage);
            }
            $finished = now();
            $summary = $this->summary($run, $counts, VideoHealthRun::STATUS_COMPLETED, $finished) + $deactivation;
            $artifacts->writeProvisionalSummary($summary);
            $artifacts->generateChecksumManifest($artifactNames);
            if (!$artifacts->verifyChecksumManifest()) {
                throw new RuntimeException('Final checksum verification failed.');
            }
            // Obtain everything needed for DB completion before sealing the summary.
            $manifestHash = $artifacts->sha256('checksums.sha256');
            $artifacts->finalizeSummary();
            $summaryFinalized = true;
            $this->persist($run, $counts + [
                'status' => VideoHealthRun::STATUS_COMPLETED, 'stage' => 'completed',
                'heartbeat_at' => $finished, 'finished_at' => $finished, 'exit_code' => 0,
                'message' => $gateReturned ? 'Audit-deactivate completed; '.$counts['deactivated_count'].' videos deactivated.' : 'Audit completed; no videos were changed.',
                'result_summary' => $summary, 'manifest_sha256' => $manifestHash,
            ]);
        } catch (Throwable $exception) {
            if ($summaryFinalized && !$gateReturned) {
                throw new RuntimeException('Audit artifacts finalized, but database completion could not be persisted.', 0, $exception);
            }
            $finished = now();
            $summary = $this->summary($run, $counts, VideoHealthRun::STATUS_FAILED, $finished) + $deactivation;
            if ($gateEntered) {
                $summary['gate_result_available'] = $gateReturned;
                $summary['reconciliation_required'] = true;
                $summary['deactivation_count_known'] = isset($deactivation['deactivated_count'])
                    && $deactivation['deactivated_count'] <= $counts['broken_count'];
            }
            if (!$summaryFinalized) {
                $manifestHash = null;
            }
            if ($artifacts !== null && $ownsArtifacts && !$summaryFinalized) {
                try {
                    $artifacts->writeProvisionalSummary($summary);
                    foreach (['catalog.jsonl' => 'selected_count', 'results.jsonl' => 'scanned_count'] as $name => $counter) {
                        if ($artifacts->finalizeJsonl($name)['row_count'] !== $counts[$counter]) {
                            throw new RuntimeException('Partial artifact count mismatch.');
                        }
                    }
                    $failedNames = ['catalog.jsonl', 'results.jsonl', 'summary.json'];
                    if ($gateEntered) {
                        // Preserve and include any existing gate evidence without path inspection.
                        foreach (['reconfirmation.jsonl', 'db-backup.jsonl'] as $name) {
                            try {
                                $artifacts->finalizeJsonl($name);
                                $failedNames[] = $name;
                            } catch (Throwable) {
                                // Missing/incomplete evidence cannot be required or rebaselined.
                            }
                        }
                    }
                    $artifacts->generateChecksumManifest($failedNames);
                    $artifacts->verifyChecksumManifest();
                    $manifestHash = $artifacts->sha256('checksums.sha256');
                } catch (Throwable) {
                    // Failed-summary recovery is best effort and never replaces the original failure.
                }
            }
            $message = $gateReturned
                ? 'Deactivation may already have committed; operator review is required. No automatic retry.'
                : ($gateEntered ? 'Deactivation gate failed; operator review of private evidence is required. No automatic retry.'
                    : 'Audit failed during '.$stage.'; no videos were changed.');
            try {
                $this->persist($run, $counts + [
                    'status' => VideoHealthRun::STATUS_FAILED, 'stage' => 'failed',
                    'heartbeat_at' => $finished, 'finished_at' => $finished, 'exit_code' => 1,
                    'message' => $message,
                    'result_summary' => $summary, 'manifest_sha256' => $manifestHash,
                ]);
            } catch (Throwable $persistenceFailure) {
                if ($gateEntered) {
                    throw new RuntimeException('Deactivation may have committed; operator review is required; failed run state could not be persisted.', 0, $persistenceFailure);
                }
                throw $persistenceFailure;
            }
        }

        return $run->refresh();
    }

    private function claim(int $runId, array $configuration): VideoHealthRun
    {
        if ($runId < 1) {
            throw new DomainException('Run ID must be positive.');
        }
        $connection = DB::connection();
        if ($connection->getDriverName() === 'pgsql' && $connection->transactionLevel() !== 0) {
            throw new DomainException('Audit execution requires no outer PostgreSQL transaction.');
        }

        return $connection->transaction(function () use ($runId, $configuration): VideoHealthRun {
            $run = VideoHealthRun::query()->useWritePdo()->lockForUpdate()->find($runId);
            if ($run === null) {
                throw new DomainException('Audit run was not found.');
            }
            if ($run->status !== VideoHealthRun::STATUS_QUEUED
                || !in_array($run->mode, [VideoHealthRun::MODE_AUDIT_ONLY, VideoHealthRun::MODE_AUDIT_DEACTIVATE], true)) {
                throw new DomainException('Only queued audit-only or confirmed manual audit-deactivate runs may execute.');
            }
            if ($run->mode === VideoHealthRun::MODE_AUDIT_DEACTIVATE
                && ($run->trigger !== VideoHealthRun::TRIGGER_MANUAL || $run->confirmed_at === null)) {
                throw new DomainException('Audit-deactivate requires manual execution and confirmation.');
            }
            if (!in_array($run->provider, [VideoHealthRun::PROVIDER_ALL, ...self::PROVIDERS], true)) {
                throw new DomainException('Unsupported audit provider.');
            }
            if ($run->classifier_version !== ($configuration['classifier_version'] ?? null)
                || !hash_equals($run->config_sha256, hash('sha256', json_encode($configuration, JSON_THROW_ON_ERROR)))) {
                throw new DomainException('Audit policy identity does not match current configuration.');
            }
            foreach (self::COUNTERS as $counter => $zero) {
                if ($run->{$counter} !== $zero) {
                    throw new DomainException('Queued audit already contains progress; automatic resume is not supported.');
                }
            }
            $changed = VideoHealthRun::query()->whereKey($runId)->where('status', VideoHealthRun::STATUS_QUEUED)->update([
                'status' => VideoHealthRun::STATUS_RUNNING, 'stage' => 'catalog',
                'started_at' => $run->started_at ?? now(), 'heartbeat_at' => now(),
            ]);
            if ($changed !== 1) {
                throw new DomainException('Audit run was already claimed.');
            }

            return $run->refresh();
        }, 1);
    }

    private function fingerprint(array $row): string
    {
        return hash('sha256', json_encode([
            'video_id' => $row['video_id'], 'provider' => $row['provider'], 'embed_url' => $row['embed_url'],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function safeDeactivationResult(array $result): array
    {
        $safe = [];
        foreach (['original_broken_count', 'reconfirmed_count', 'eligible_count', 'deactivated_count',
            'skipped_identity_changed_count', 'skipped_not_broken_count', 'backup_row_count'] as $key) {
            if (is_int($result[$key] ?? null) && $result[$key] >= 0) {
                $safe[$key] = $result[$key];
            }
        }
        if (array_key_exists('backup_sha256', $result)
            && ($result['backup_sha256'] === null || (is_string($result['backup_sha256']) && preg_match('/\A[a-f0-9]{64}\z/', $result['backup_sha256'])))) {
            $safe['backup_sha256'] = $result['backup_sha256'];
        }

        return $safe;
    }

    private function validateDeactivationResult(array $result, int $brokenCount): void
    {
        $safe = $this->safeDeactivationResult($result);
        if (count($safe) !== 8 || $safe['original_broken_count'] !== $brokenCount
            || $safe['reconfirmed_count'] + $safe['skipped_identity_changed_count'] !== $brokenCount
            || $safe['skipped_not_broken_count'] > $safe['reconfirmed_count']
            || $safe['eligible_count'] !== $safe['reconfirmed_count'] - $safe['skipped_not_broken_count']
            || $safe['deactivated_count'] !== $safe['eligible_count'] || $safe['deactivated_count'] > $brokenCount
            || $safe['backup_row_count'] !== $safe['eligible_count']
            || ($safe['eligible_count'] === 0 ? $safe['backup_sha256'] !== null : $safe['backup_sha256'] === null)) {
            throw new RuntimeException('Deactivation result invariants failed.');
        }
    }

    private function initializeStreams(VideoHealthArtifacts $artifacts): void
    {
        $entries = scandir($artifacts->directory());
        if ($entries === false || array_diff($entries, ['.', '..', '.lock']) !== []) {
            throw new RuntimeException('Audit evidence already exists; automatic resume is not supported.');
        }
        // The artifact API appends rows, but zero-video audits need genuine empty JSONL files.
        // Create only these two fixed names, exclusively, in its validated private directory.
        foreach (['catalog.jsonl', 'results.jsonl'] as $name) {
            $path = $artifacts->directory().DIRECTORY_SEPARATOR.$name;
            $file = @fopen($path, 'x+b');
            if ($file === false) {
                throw new RuntimeException('Cannot initialize audit artifact.');
            }
            try {
                if ((PHP_OS_FAMILY !== 'Windows' && !chmod($path, 0600))
                    || !fflush($file) || (function_exists('fsync') && !fsync($file))) {
                    throw new RuntimeException('Cannot secure or flush audit artifact.');
                }
            } finally {
                fclose($file);
            }
        }
    }

    private function catalogRows(VideoHealthArtifacts $artifacts): Generator
    {
        $path = $artifacts->directory().DIRECTORY_SEPARATOR.'catalog.jsonl';
        if (is_link($path)) {
            throw new RuntimeException('Unsafe catalog file.');
        }
        $file = @fopen($path, 'rb');
        if ($file === false) {
            throw new RuntimeException('Cannot read catalog.');
        }
        try {
            $opened = fstat($file);
            $current = lstat($path);
            if (is_link($path) || $opened === false || $current === false
                || ($current['mode'] & 0170000) !== 0100000 || $current['nlink'] !== 1
                || $opened['ino'] !== $current['ino'] || $opened['dev'] !== $current['dev']) {
                throw new RuntimeException('Catalog changed while opening.');
            }
            $previousId = 0;
            while (($line = fgets($file)) !== false) {
                $row = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                if (!str_ends_with($line, "\n") || !is_array($row) || !is_int($row['video_id'] ?? null)
                    || $row['video_id'] <= $previousId || !is_string($row['provider'] ?? null)
                    || !is_string($row['slug'] ?? null) || !is_string($row['embed_url'] ?? null)
                    || !is_string($row['fingerprint'] ?? null) || !hash_equals($this->fingerprint($row), $row['fingerprint'])) {
                    throw new RuntimeException('Invalid frozen catalog row.');
                }
                $previousId = $row['video_id'];
                yield $row;
            }
            if (!feof($file)) {
                throw new RuntimeException('Catalog read failed.');
            }
        } finally {
            fclose($file);
        }
    }

    private function progress(VideoHealthRun $run, array $counts, string $stage, float &$lastFlush, int $rows): void
    {
        if ($rows % $this->progressRows === 0 || microtime(true) - $lastFlush >= $this->heartbeatSeconds) {
            $this->flushProgress($run, $counts, $stage);
            $lastFlush = microtime(true);
        }
    }

    private function flushProgress(VideoHealthRun $run, array $counts, string $stage): void
    {
        $this->persist($run, $counts + ['stage' => $stage, 'heartbeat_at' => now()]);
    }

    private function persist(VideoHealthRun $run, array $attributes): void
    {
        if (!$run->update($attributes)) {
            throw new RuntimeException('Cannot persist audit run state.');
        }
    }

    private function summary(VideoHealthRun $run, array $counts, string $status, Carbon $finished): array
    {
        return [
            'run_id' => $run->id, 'provider' => $run->provider, 'mode' => $run->mode,
            'selected' => $counts['selected_count'], 'scanned' => $counts['scanned_count'],
            'pass' => $counts['pass_count'], 'broken' => $counts['broken_count'],
            'review' => $counts['review_count'], 'unverifiable' => $counts['unverifiable_count'], 'deactivated' => $counts['deactivated_count'],
            'started_at' => $run->started_at->toIso8601String(), 'finished_at' => $finished->toIso8601String(),
            'classifier_version' => $run->classifier_version, 'config_sha256' => $run->config_sha256, 'status' => $status,
        ];
    }
}
