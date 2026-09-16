<?php

namespace App\Console\Commands;

use App\Services\Seo\CategoryRelationBuilder;
use Illuminate\Console\Command;
use Throwable;

class RebuildCategoryRelations extends Command
{
    protected $signature =
        'seo:rebuild-category-relations
        {--force : Run without interactive confirmation}';

    protected $description =
        'Rebuild automatic SEO category relations from the active video taxonomy.';

    public function handle(
        CategoryRelationBuilder $builder
    ): int {
        $this->newLine();

        $this->info(
            'Xurvexa SEO Category Relation Builder'
        );

        $this->line(
            'Version: ' .
            CategoryRelationBuilder::RELATION_VERSION
        );

        $this->newLine();

        if (
            !$this->option('force') &&
            !$this->confirm(
                'Rebuild all category relations?',
                false
            )
        ) {
            $this->warn(
                'Operation cancelled. No changes were made.'
            );

            return self::SUCCESS;
        }

        $startedAt =
            microtime(true);

        try {
            $result =
                $builder->rebuild();
        } catch (Throwable $exception) {
            $this->error(
                'Category relation rebuild failed.'
            );

            $this->newLine();

            $this->error(
                $exception->getMessage()
            );

            return self::FAILURE;
        }

        $durationSeconds =
            round(
                microtime(true) -
                $startedAt,
                2
            );

        $this->newLine();

        $this->info(
            'Category relation rebuild completed.'
        );

        $this->table(
            [
                'Metric',
                'Value',
            ],
            [
                [
                    'Relation count',
                    number_format(
                        (int)
                        (
                            $result[
                                'relation_count'
                            ]
                            ?? 0
                        )
                    ),
                ],
                [
                    'Categories represented',
                    number_format(
                        (int)
                        (
                            $result[
                                'category_count'
                            ]
                            ?? 0
                        )
                    ),
                ],
                [
                    'Relation version',
                    (string)
                    (
                        $result[
                            'relation_version'
                        ]
                        ?? 'unknown'
                    ),
                ],
                [
                    'Duration',
                    $durationSeconds .
                    ' seconds',
                ],
            ]
        );

        return self::SUCCESS;
    }
}
