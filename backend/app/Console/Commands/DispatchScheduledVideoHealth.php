<?php

namespace App\Console\Commands;

use App\Models\VideoHealthRun;
use App\Services\VideoHealth\VideoHealthLaunchException;
use App\Services\VideoHealth\VideoHealthLauncher;
use App\Services\VideoHealth\VideoHealthRunCoordinator;
use App\Services\VideoWorkloadAdmission;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Console\Command;
use Throwable;

class DispatchScheduledVideoHealth extends Command
{
    protected $signature = 'video-health:dispatch-scheduled';

    protected $description = 'Dispatch the current weekly audit-only video health cohort';

    public function handle(VideoHealthRunCoordinator $coordinator, VideoHealthLauncher $launcher): int
    {
        try {
            $now = CarbonImmutable::now('UTC');
            $boundary = $now->startOfDay()->subDays($now->dayOfWeek)->setTime(1, 0);
            if ($boundary->greaterThan($now)) {
                $boundary = $boundary->subWeek();
            }
            $key = 'video-health:weekly:audit-only:all:'.$boundary->format('Y-m-d\TH:i\Z');
            $result = $coordinator->admitScheduled([
                'provider' => VideoHealthRun::PROVIDER_ALL,
                'mode' => VideoHealthRun::MODE_AUDIT_ONLY,
                'schedule_key' => $key,
            ]);
            $run = $this->cohortRun($result, $key);
            if ($run->status === VideoHealthRun::STATUS_DEFERRED) {
                $runId = $run->id;
                $result = $coordinator->promoteDeferredScheduled($runId);
                $run = $this->cohortRun($result, $key);
                if ($run->id !== $runId) {
                    throw new DomainException('Promotion returned a different run.');
                }
                if ($run->status === VideoHealthRun::STATUS_DEFERRED
                    && in_array($result['reason'], [VideoWorkloadAdmission::ACTIVE_IMPORT, VideoWorkloadAdmission::ACTIVE_HEALTH], true)) {
                    $this->info('Current weekly audit remains deferred; shared workload is busy.');

                    return self::SUCCESS;
                }
                if ($run->status !== VideoHealthRun::STATUS_QUEUED || $result['reason'] !== null) {
                    throw new DomainException('Promotion requires operator review.');
                }
            }
            if (in_array($run->status, [VideoHealthRun::STATUS_RUNNING, VideoHealthRun::STATUS_DEACTIVATING, VideoHealthRun::STATUS_COMPLETED], true)) {
                $this->info('Current weekly audit is already active or completed; no launch requested.');

                return self::SUCCESS;
            }
            if (in_array($run->status, [VideoHealthRun::STATUS_FAILED, VideoHealthRun::STATUS_ABORTED], true)) {
                $this->warn('Current weekly audit ended without completion; operator review is required. No relaunch requested.');

                return self::SUCCESS;
            }
            if ($run->status !== VideoHealthRun::STATUS_QUEUED || $result['reason'] !== null) {
                throw new DomainException('Unexpected cohort state.');
            }
            $launched = $launcher->launch($run->id);
            if (($launched['launched'] ?? null) !== true) {
                throw new DomainException('Launch was not acknowledged.');
            }
            $this->info('Current weekly audit launch requested for run '.$run->id.'.');

            return self::SUCCESS;
        } catch (VideoHealthLaunchException $exception) {
            $this->error($exception->confirmedNotLaunched()
                ? 'No audit child launched; this tick failed. A later tick may retry the queued run.'
                : 'Audit launch requires operator reconciliation; do not force a relaunch.');

            return self::FAILURE;
        } catch (DomainException) {
            $this->error('Weekly audit admission or launch was refused; operator review is required.');

            return self::FAILURE;
        } catch (Throwable) {
            $this->error('Weekly audit dispatch failed; operator review is required.');

            return self::FAILURE;
        }
    }

    private function cohortRun(array $result, string $key): VideoHealthRun
    {
        $run = $result['run'] ?? null;
        $config = config('video-health');
        if (!$run instanceof VideoHealthRun || !$run->exists || !is_int($run->id) || $run->id < 1
            || !array_key_exists('reason', $result)
            || !in_array($result['reason'], [null, VideoWorkloadAdmission::ACTIVE_IMPORT, VideoWorkloadAdmission::ACTIVE_HEALTH], true)
            || ($run->status !== VideoHealthRun::STATUS_DEFERRED && $result['reason'] !== null)
            || $run->schedule_key !== $key || $run->provider !== VideoHealthRun::PROVIDER_ALL
            || $run->mode !== VideoHealthRun::MODE_AUDIT_ONLY || $run->trigger !== VideoHealthRun::TRIGGER_SCHEDULED
            || $run->classifier_version !== $config['classifier_version']
            || $run->config_sha256 !== hash('sha256', json_encode($config, JSON_THROW_ON_ERROR))) {
            throw new DomainException('Invalid weekly cohort result.');
        }

        return $run;
    }
}
