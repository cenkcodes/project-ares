<?php

namespace App\Services;

use App\Models\VideoHealthRun;
use App\Models\VideoImportJob;
use Illuminate\Support\Facades\DB;
use LogicException;

class VideoWorkloadAdmission
{
    public const ACTIVE_IMPORT = 'active_import';
    public const ACTIVE_HEALTH = 'active_health';
    public const INVALID_INPUT = 'invalid_input';
    public const AUTOMATIC_DEACTIVATION_DISABLED = 'automatic_deactivation_disabled';

    // Project Ares video workloads; distinct from the SEO batch lock.
    private const ADVISORY_LOCK_KEY = 7483102602;

    public function underExclusiveAdmission(callable $callback): mixed
    {
        $connection = DB::connection();

        if (! in_array($connection->getDriverName(), ['pgsql', 'sqlite'], true)) {
            throw new LogicException('Unsupported video admission database driver.');
        }

        // A nested PostgreSQL transaction would retain this lock until its outer commit.
        if ($connection->getDriverName() === 'pgsql' && $connection->transactionLevel() !== 0) {
            throw new LogicException('Video admission requires its own short PostgreSQL transaction.');
        }

        return $connection->transaction(function () use ($connection, $callback): mixed {
            if ($connection->getDriverName() === 'pgsql') {
                $connection->select('SELECT pg_advisory_xact_lock(?)', [self::ADVISORY_LOCK_KEY], false);
            }

            return $callback();
        }, 1);
    }

    /** Call only inside underExclusiveAdmission, before creating the workload. */
    public function activeReason(): ?string
    {
        if (VideoImportJob::query()->useWritePdo()->active()->exists()) {
            return self::ACTIVE_IMPORT;
        }

        if (VideoHealthRun::query()->useWritePdo()->active()->exists()) {
            return self::ACTIVE_HEALTH;
        }

        return null;
    }
}
