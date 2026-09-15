<?php

namespace App\Console\Commands;

use App\Services\Seo\ConceptIntelligenceBuilder;
use Illuminate\Console\Command;
use Throwable;

class ProcessConceptIntelligence extends Command
{
    protected $signature =
        'seo:process-concept-intelligence '
        . '{--full : Run a full Concept Intelligence reconciliation} '
        . '{--force : Required for a persistent full reconciliation} '
        . '{--dry-run : Execute the selected mode and roll the transaction back} '
        . '{--limit=1000 : Maximum dirty-term queue rows for incremental mode} '
        . '{--lease=900 : Dirty-term lease timeout in seconds}';

    protected $description =
        'Build and incrementally maintain exact-term Concept Intelligence.';

    public function handle(
        ConceptIntelligenceBuilder $builder
    ): int {
        $full = (bool) $this->option('full');
        $force = (bool) $this->option('force');
        $dryRun = (bool) $this->option('dry-run');

        if ($full && ! $dryRun && ! $force) {
            $this->error(
                'Persistent full reconciliation requires --force. '
                . 'Use --full --dry-run first.'
            );

            return self::FAILURE;
        }

        $limit = (int) $this->option('limit');
        $lease = (int) $this->option('lease');

        try {
            if ($full) {
                $this->info(
                    $dryRun
                        ? 'Running Concept Intelligence full preview...'
                        : 'Running Concept Intelligence full reconciliation...'
                );

                $result = $builder->rebuildAll(
                    $dryRun
                );
            } else {
                $this->info(
                    $dryRun
                        ? 'Running Concept Intelligence incremental preview...'
                        : 'Processing Concept Intelligence dirty-term batch...'
                );

                $result = $builder->processBatch(
                    $limit,
                    $lease,
                    $dryRun
                );
            }

            $rows = [];

            foreach ($result as $key => $value) {
                if (is_bool($value)) {
                    $value = $value ? 'YES' : 'NO';
                } elseif ($value === null) {
                    $value = 'NULL';
                } elseif (is_array($value)) {
                    $value = json_encode(
                        $value,
                        JSON_UNESCAPED_SLASHES
                    );
                }

                $rows[] = [
                    strtoupper((string) $key),
                    (string) $value,
                ];
            }

            $this->table(
                ['Metric', 'Value'],
                $rows
            );

            if ($dryRun) {
                $this->warn(
                    'DRY RUN: all Concept Intelligence database changes were rolled back.'
                );
            } else {
                $this->info(
                    'Concept Intelligence processing completed.'
                );
            }

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error(
                'Concept Intelligence failed: '
                . $exception->getMessage()
            );

            return self::FAILURE;
        }
    }
}
