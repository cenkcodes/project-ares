<?php

namespace App\Services\VideoHealth;

use App\Models\Video;
use App\Models\VideoHealthRun;
use Closure;
use DomainException;
use RuntimeException;
use Throwable;

class VideoHealthDeactivationGate
{
    public const ENTRY_STAGE = 'deactivation-ready';

    private const CLASSIFICATIONS = [
        EmbedHealthClassifier::PASS, EmbedHealthClassifier::BROKEN,
        EmbedHealthClassifier::REVIEW, EmbedHealthClassifier::UNVERIFIABLE,
    ];

    public function __construct(
        private ?EmbedHealthProbe $probe = null,
        private ?EmbedHealthClassifier $classifier = null,
        private ?Closure $artifactFactory = null,
    ) {}

    public function execute(int $runId): array
    {
        if ($runId < 1) {
            throw new DomainException('Run ID must be positive.');
        }
        $connection = (new Video)->getConnection();
        // An outer production transaction would retain locks during fresh HTTP.
        if ($connection->getDriverName() === 'pgsql' && $connection->transactionLevel() !== 0) {
            throw new DomainException('Deactivation requires no outer PostgreSQL transaction.');
        }
        $run = VideoHealthRun::query()->useWritePdo()->find($runId);
        $configuration = config('video-health');
        $this->requireEligibleRun($run, $configuration);
        if ($run->getConnection() !== $connection) {
            throw new DomainException('Run and Video must use the same database connection.');
        }
        $artifacts = $this->artifactFactory !== null
            ? ($this->artifactFactory)($runId)
            : new VideoHealthArtifacts($runId);
        $probe = $this->probe ?? new EmbedHealthProbe($configuration);
        $classifier = $this->classifier ?? new EmbedHealthClassifier($configuration);

        // Verify existing evidence before reaffirming its immutable JSONL seal.
        $this->requireVerification($artifacts->verifyChecksumManifest());
        $original = $artifacts->finalizeJsonl('results.jsonl');
        $this->requireVerification($artifacts->verify('results.jsonl', $original['sha256']));
        // Exclusive initialization also refuses stale evidence or a concurrent execution.
        $artifacts->initializeEmptyJsonl('reconfirmation.jsonl');
        $counts = [
            'original_broken_count' => 0, 'reconfirmed_count' => 0, 'eligible_count' => 0,
            'deactivated_count' => 0, 'skipped_identity_changed_count' => 0,
            'skipped_not_broken_count' => 0, 'backup_row_count' => 0, 'backup_sha256' => null,
        ];
        $seen = [];
        $originalRows = 0;
        foreach ($artifacts->streamJsonl('results.jsonl') as $row) {
            $originalRows++;
            if (!in_array($row['classification'] ?? null, self::CLASSIFICATIONS, true)) {
                throw new RuntimeException('Invalid original classification.');
            }
            if ($row['classification'] !== EmbedHealthClassifier::BROKEN) {
                continue;
            }
            $this->validateIdentity($row, $run, $configuration);
            if (isset($seen[$row['video_id']])) {
                throw new RuntimeException('Duplicate original BROKEN identity.');
            }
            $seen[$row['video_id']] = true;
            $counts['original_broken_count']++;
            $video = Video::query()->useWritePdo()->find($row['video_id']);
            $reason = $this->identityFailure($video, $row);
            $fresh = null;
            $evidence = [];
            if ($reason === null) {
                $evidence = $probe->probe($row['provider'], $row['embed_url']);
                $fresh = $classifier->classify($evidence);
                if (!in_array($fresh, self::CLASSIFICATIONS, true)) {
                    throw new RuntimeException('Invalid fresh classification.');
                }
                $counts['reconfirmed_count']++;
                $reason = $fresh === EmbedHealthClassifier::BROKEN ? 'reconfirmed_broken' : 'fresh_not_broken';
                if ($fresh !== EmbedHealthClassifier::BROKEN) {
                    $counts['skipped_not_broken_count']++;
                }
            } else {
                $counts['skipped_identity_changed_count']++;
            }
            $artifacts->appendJsonl('reconfirmation.jsonl', [
                'video_id' => $row['video_id'], 'provider' => $row['provider'],
                'embed_url' => $row['embed_url'], 'fingerprint' => $row['fingerprint'],
                'original_classification' => EmbedHealthClassifier::BROKEN,
                'current_identity_match' => $fresh !== null,
                'fresh_classification' => $fresh,
                'eligible_for_deactivation' => $fresh === EmbedHealthClassifier::BROKEN,
                'reason' => $reason, 'effective_url' => $evidence['effective_url'] ?? null,
                'http_status' => $evidence['http_status'] ?? null,
                'timeout' => $evidence['timeout'] ?? false,
                'transport_error' => $evidence['transport_error'] ?? null,
                'reconfirmed_at' => now()->toIso8601String(),
            ]);
        }
        unset($seen);
        if ($originalRows !== $original['row_count']) {
            throw new RuntimeException('Original result count mismatch.');
        }
        $this->requireVerification($artifacts->verify('results.jsonl', $original['sha256']));
        $frozen = $artifacts->finalizeJsonl('reconfirmation.jsonl');
        if ($frozen['row_count'] !== $counts['original_broken_count']) {
            throw new RuntimeException('Reconfirmation count mismatch.');
        }
        $this->requireVerification($artifacts->verify('reconfirmation.jsonl', $frozen['sha256']));

        // Only the finalized artifact supplies mutation identities.
        $candidates = [];
        $rows = 0;
        foreach ($artifacts->streamJsonl('reconfirmation.jsonl') as $row) {
            $rows++;
            if (!is_bool($row['eligible_for_deactivation'] ?? null)) {
                throw new RuntimeException('Invalid frozen eligibility.');
            }
            if (!$row['eligible_for_deactivation']) {
                continue;
            }
            $this->validateIdentity($row, $run, $configuration);
            if (($row['original_classification'] ?? null) !== EmbedHealthClassifier::BROKEN
                || ($row['current_identity_match'] ?? null) !== true
                || ($row['fresh_classification'] ?? null) !== EmbedHealthClassifier::BROKEN
                || !hash_equals($this->fingerprint($row), $row['fingerprint'])) {
                throw new RuntimeException('Inconsistent frozen candidate.');
            }
            if (isset($candidates[$row['video_id']])) {
                throw new RuntimeException('Duplicate frozen candidate.');
            }
            $candidates[$row['video_id']] = $row;
        }
        if ($rows !== $frozen['row_count']) {
            throw new RuntimeException('Frozen reconfirmation count mismatch.');
        }
        $this->requireVerification($artifacts->verify('reconfirmation.jsonl', $frozen['sha256']));
        ksort($candidates, SORT_NUMERIC);
        $counts['eligible_count'] = count($candidates);
        if ($candidates === []) {
            return $counts;
        }

        try {
            $backup = $connection->transaction(function () use ($connection, $runId, $configuration, $artifacts, $candidates): array {
                // Refuse a lifecycle change during fresh probing, without changing the run.
                $lockedRun = VideoHealthRun::query()->useWritePdo()->lockForUpdate()->find($runId);
                $this->requireEligibleRun($lockedRun, $configuration);
                $ids = array_keys($candidates);
                $videos = Video::query()->useWritePdo()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
                if ($videos->count() !== count($ids)) {
                    throw new RuntimeException('Locked candidate count mismatch.');
                }
                foreach ($videos as $video) {
                    if ($this->identityFailure($video, $candidates[$video->id]) !== null) {
                        throw new RuntimeException('Candidate identity drifted before mutation.');
                    }
                }
                $table = (new Video)->getTable();
                $totalBefore = $connection->table($table)->count();
                $artifacts->startBackup();
                $snapshots = [];
                foreach ($videos as $video) {
                    $snapshots[$video->id] = $video->getAttributes();
                    $artifacts->appendBackup(['video_id' => (int) $video->id, 'attributes' => $snapshots[$video->id]]);
                }
                $backup = $artifacts->finalizeBackup();
                if ($backup['row_count'] !== count($ids)) {
                    throw new RuntimeException('Backup count mismatch.');
                }
                $this->requireVerification($artifacts->verify('db-backup.jsonl', $backup['sha256']));
                // Query Builder deliberately avoids Eloquent timestamps, events and appends.
                $affected = $connection->table($table)->whereIn('id', $ids)->where('is_active', true)
                    ->update(['is_active' => false]);
                if ($affected !== count($ids)) {
                    throw new RuntimeException('Deactivation affected count mismatch.');
                }
                $after = $connection->table($table)->whereIn('id', $ids)->orderBy('id')->get();
                if ($after->count() !== count($ids) || $connection->table($table)->whereIn('id', $ids)->where('is_active', true)->count() !== 0
                    || $connection->table($table)->count() !== $totalBefore) {
                    throw new RuntimeException('Deactivation integrity check failed.');
                }
                foreach ($after as $row) {
                    $expected = $snapshots[$row->id];
                    $actual = (array) $row;
                    if ((bool) $actual['is_active']) {
                        throw new RuntimeException('Candidate remains active.');
                    }
                    unset($expected['is_active'], $actual['is_active']);
                    if ($actual !== $expected) {
                        throw new RuntimeException('A non-activation Video column changed.');
                    }
                }

                return $backup;
            }, 1);
        } catch (Throwable $exception) {
            // Filesystem finalization cannot roll back with SQL. Preserve all evidence.
            throw new RuntimeException('Deactivation transaction failed; preserve any finalized backup for review.', 0, $exception);
        }
        $counts['backup_row_count'] = $backup['row_count'];
        $counts['backup_sha256'] = $backup['sha256'];
        $counts['deactivated_count'] = $counts['eligible_count'];

        return $counts;
    }

    private function requireEligibleRun(?VideoHealthRun $run, array $configuration): void
    {
        if ($run === null || $run->mode !== VideoHealthRun::MODE_AUDIT_DEACTIVATE
            || $run->trigger !== VideoHealthRun::TRIGGER_MANUAL || $run->confirmed_at === null
            || $run->status !== VideoHealthRun::STATUS_RUNNING || $run->stage !== self::ENTRY_STAGE) {
            throw new DomainException('Only confirmed manual running audit-deactivate runs at deactivation-ready may execute.');
        }
        if ($run->classifier_version !== ($configuration['classifier_version'] ?? null)
            || !hash_equals($run->config_sha256, hash('sha256', json_encode($configuration, JSON_THROW_ON_ERROR)))) {
            throw new DomainException('Deactivation policy identity does not match current configuration.');
        }
    }

    private function validateIdentity(array $row, VideoHealthRun $run, array $configuration): void
    {
        if (!is_int($row['video_id'] ?? null) || $row['video_id'] < 1
            || !is_string($row['provider'] ?? null) || !isset($configuration['providers'][$row['provider']])
            || ($run->provider !== VideoHealthRun::PROVIDER_ALL && $run->provider !== $row['provider'])
            || !is_string($row['embed_url'] ?? null) || $row['embed_url'] === ''
            || !is_string($row['fingerprint'] ?? null) || !preg_match('/\A[a-f0-9]{64}\z/', $row['fingerprint'])) {
            throw new RuntimeException('Malformed candidate identity.');
        }
    }

    private function identityFailure(?Video $video, array $row): ?string
    {
        if ($video === null) {
            return 'video_missing';
        }
        if ($video->is_active !== true) {
            return 'already_inactive';
        }
        if ($video->video_source !== $row['provider']) {
            return 'provider_changed';
        }
        if ($video->embed_url !== $row['embed_url']) {
            return 'embed_url_changed';
        }
        if (!hash_equals($this->fingerprint([
            'video_id' => (int) $video->id, 'provider' => $video->video_source, 'embed_url' => $video->embed_url,
        ]), $row['fingerprint'])) {
            return 'fingerprint_changed';
        }

        return null;
    }

    private function fingerprint(array $row): string
    {
        return hash('sha256', json_encode([
            'video_id' => $row['video_id'], 'provider' => $row['provider'], 'embed_url' => $row['embed_url'],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function requireVerification(bool $verified): void
    {
        if (!$verified) {
            throw new RuntimeException('Artifact verification failed.');
        }
    }
}
