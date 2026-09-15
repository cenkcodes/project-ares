<?php

namespace App\Console\Commands;

use App\Services\Seo\TopicIntelligenceBuilder;
use Illuminate\Console\Command;
use Throwable;

class RebuildTopicIntelligence extends Command
{
    protected $signature =
        'seo:rebuild-topic-intelligence '
        . '{--force : Required for a persistent Topic Intelligence rebuild} '
        . '{--dry-run : Execute the rebuild and roll the transaction back}';

    protected $description =
        'Rebuild quality-gated category + concept Topic Intelligence.';

    public function handle(
        TopicIntelligenceBuilder $builder
    ): int {
        $force = (bool) $this->option('force');
        $dryRun = (bool) $this->option('dry-run');

        if (! $dryRun && ! $force) {
            $this->error(
                'Persistent Topic Intelligence rebuild requires --force. '
                . 'Use --dry-run first.'
            );

            return self::FAILURE;
        }

        try {
            $this->info(
                $dryRun
                    ? 'Running Topic Intelligence preview...'
                    : 'Running Topic Intelligence full reconciliation...'
            );

            $result = $builder->rebuildAll(
                $dryRun
            );

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
                    'DRY RUN: all Topic Intelligence database changes were rolled back.'
                );
            } else {
                $this->info(
                    'Topic Intelligence reconciliation completed.'
                );
            }

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error(
                'Topic Intelligence failed: '
                . $exception->getMessage()
            );

            return self::FAILURE;
        }
    }
}
