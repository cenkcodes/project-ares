<?php

namespace App\Services\VideoHealth;

use App\Models\VideoHealthRun;
use App\Services\VideoWorkloadAdmission;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class VideoHealthRunCoordinator
{
    public function __construct(private VideoWorkloadAdmission $admission)
    {
    }

    /** @return array{run: ?VideoHealthRun, reason: ?string} */
    public function admitManual(array $input): array
    {
        return $this->admit($input, VideoHealthRun::TRIGGER_MANUAL);
    }

    /** @return array{run: ?VideoHealthRun, reason: ?string} */
    public function admitScheduled(array $input): array
    {
        return $this->admit($input, VideoHealthRun::TRIGGER_SCHEDULED);
    }

    /** @return array{run: ?VideoHealthRun, reason: ?string} */
    public function promoteDeferredScheduled(int $runId): array
    {
        return $this->admission->underExclusiveAdmission(function () use ($runId): array {
            $run = VideoHealthRun::query()->useWritePdo()->lockForUpdate()->find($runId);
            $config = config('video-health');
            if ($run === null || $run->status !== VideoHealthRun::STATUS_DEFERRED
                || $run->trigger !== VideoHealthRun::TRIGGER_SCHEDULED
                || $run->mode !== VideoHealthRun::MODE_AUDIT_ONLY
                || $run->provider !== VideoHealthRun::PROVIDER_ALL
                || !is_string($run->schedule_key) || trim($run->schedule_key) === ''
                || $run->stage !== VideoHealthRun::STATUS_DEFERRED
                || $run->classifier_version !== $config['classifier_version']
                || $run->config_sha256 !== hash('sha256', json_encode($config, JSON_THROW_ON_ERROR))) {
                return ['run' => null, 'reason' => VideoWorkloadAdmission::INVALID_INPUT];
            }
            foreach (['started_at', 'finished_at', 'heartbeat_at', 'process_id', 'exit_code',
                'artifact_directory', 'log_path', 'manifest_sha256', 'result_summary', 'message',
                'confirmed_at', 'created_by'] as $field) {
                if ($run->getRawOriginal($field) !== null) {
                    return ['run' => null, 'reason' => VideoWorkloadAdmission::INVALID_INPUT];
                }
            }
            foreach (['selected_count', 'scanned_count', 'pass_count', 'broken_count',
                'review_count', 'unverifiable_count', 'deactivated_count'] as $field) {
                if ($run->{$field} !== 0) {
                    return ['run' => null, 'reason' => VideoWorkloadAdmission::INVALID_INPUT];
                }
            }
            $reason = $this->admission->activeReason();
            if ($reason !== null) {
                return ['run' => $run, 'reason' => $reason];
            }
            if (!$run->updateOrFail(['status' => VideoHealthRun::STATUS_QUEUED, 'stage' => VideoHealthRun::STATUS_QUEUED])) {
                throw new \RuntimeException('Deferred promotion could not be persisted.');
            }

            return ['run' => $run, 'reason' => null];
        });
    }

    private function admit(array $input, string $trigger): array
    {
        $config = config('video-health');
        $scheduled = $trigger === VideoHealthRun::TRIGGER_SCHEDULED;
        $providers = array_intersect([
            VideoHealthRun::PROVIDER_EPORNER,
            VideoHealthRun::PROVIDER_XVIDEOS,
            VideoHealthRun::PROVIDER_XNXX,
            VideoHealthRun::PROVIDER_REDTUBE,
        ], array_keys($config['providers']));
        $rules = [
            'provider' => ['required', Rule::in(array_merge([VideoHealthRun::PROVIDER_ALL], $providers))],
            'mode' => ['required', Rule::in([VideoHealthRun::MODE_AUDIT_ONLY, VideoHealthRun::MODE_AUDIT_DEACTIVATE])],
            'created_by' => ['nullable', 'integer', 'min:1'],
            'confirmed_at' => ['nullable', 'date'],
            'schedule_key' => $scheduled ? ['required', 'string', 'max:255', 'regex:/\S/'] : ['prohibited'],
        ];

        if (! $scheduled && ($input['mode'] ?? null) === VideoHealthRun::MODE_AUDIT_DEACTIVATE) {
            $rules['confirmed_at'][] = 'required';
        }

        $validator = Validator::make($input, $rules);
        if ($validator->fails()) {
            return ['run' => null, 'reason' => VideoWorkloadAdmission::INVALID_INPUT];
        }

        $data = $validator->validated();

        if ($data['provider'] === VideoHealthRun::PROVIDER_ALL
            && $data['mode'] === VideoHealthRun::MODE_AUDIT_DEACTIVATE) {
            return ['run' => null, 'reason' => VideoWorkloadAdmission::INVALID_INPUT];
        }

        return $this->admission->underExclusiveAdmission(function () use ($data, $config, $trigger, $scheduled): array {
            if ($scheduled) {
                $existing = VideoHealthRun::query()->useWritePdo()->where('schedule_key', $data['schedule_key'])->first();
                if ($existing !== null) {
                    return ['run' => $existing, 'reason' => null];
                }

                if ($data['mode'] === VideoHealthRun::MODE_AUDIT_DEACTIVATE
                    && ($config['rollout']['automatic_deactivation_enabled'] ?? false) !== true) {
                    return ['run' => null, 'reason' => VideoWorkloadAdmission::AUTOMATIC_DEACTIVATION_DISABLED];
                }
            }

            $reason = $this->admission->activeReason();
            if (! $scheduled && $reason !== null) {
                return ['run' => null, 'reason' => $reason];
            }

            $status = $reason === null ? VideoHealthRun::STATUS_QUEUED : VideoHealthRun::STATUS_DEFERRED;
            $run = VideoHealthRun::query()->create([
                'provider' => $data['provider'],
                'mode' => $data['mode'],
                'trigger' => $trigger,
                'schedule_key' => $scheduled ? $data['schedule_key'] : null,
                'created_by' => $scheduled ? null : ($data['created_by'] ?? null),
                'confirmed_at' => $scheduled ? null : ($data['confirmed_at'] ?? null),
                'status' => $status,
                'stage' => $status,
                'classifier_version' => $config['classifier_version'],
                'config_sha256' => hash('sha256', json_encode($config, JSON_THROW_ON_ERROR)),
            ]);

            return ['run' => $run, 'reason' => $reason];
        });
    }
}
