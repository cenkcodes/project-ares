<?php

namespace App\Console\Commands;

use App\Models\VideoHealthRun;
use App\Services\VideoHealth\VideoHealthAuditRunner;
use DomainException;
use Illuminate\Console\Command;
use Throwable;

class RunVideoHealthAudit extends Command
{
    protected $signature = 'video-health:run {run_id}';

    protected $description = 'Run an existing eligible queued video health run in the foreground';

    public function handle(VideoHealthAuditRunner $runner): int
    {
        $value = (string) $this->argument('run_id');
        $runId = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!preg_match('/\A[1-9][0-9]*\z/', $value) || $runId === false) {
            $this->error('Run ID must be a positive integer.');

            return self::FAILURE;
        }
        try {
            $run = $runner->run($runId);
        } catch (DomainException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable) {
            $this->error('Audit execution failed; inspect the run and private artifacts. Operator review is required if deactivation was requested.');

            return self::FAILURE;
        }
        if ($run->status !== VideoHealthRun::STATUS_COMPLETED) {
            $this->error($run->mode === VideoHealthRun::MODE_AUDIT_DEACTIVATE
                ? 'Audit-deactivate did not complete; operator review is required. Deactivation may have committed; inspect private artifacts.'
                : 'Audit did not complete; inspect the run and private artifacts.');

            return self::FAILURE;
        }
        $this->info($run->mode === VideoHealthRun::MODE_AUDIT_DEACTIVATE
            ? 'Audit '.$run->id.' completed: '.$run->scanned_count.' videos scanned; '.$run->deactivated_count.' videos deactivated.'
            : 'Audit '.$run->id.' completed: '.$run->scanned_count.' videos scanned; no videos changed.');

        return self::SUCCESS;
    }
}
