<?php

namespace App\Services;

use App\Models\Category;
use App\Models\CategoryAlias;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class VideoTaxonomyResolver
{
    public function normalizeTerm(string $value): string
    {
        $value = Str::ascii($value);
        $value = Str::lower($value);

        $value = preg_replace(
            '/[^a-z0-9]+/',
            '-',
            $value
        ) ?? '';

        $value = preg_replace(
            '/-+/',
            '-',
            $value
        ) ?? '';

        return trim($value, '-');
    }

    public function matchAliases(
        string $source,
        string $termType,
        iterable $terms
    ): Collection {
        $source = Str::lower(trim($source));
        $termType = Str::lower(trim($termType));

        if ($source === '') {
            $source = '*';
        }

        if ($termType === '') {
            $termType = 'tag';
        }

        $normalizedTerms = collect($terms)
            ->map(
                fn ($term): string =>
                    $this->normalizeTerm(
                        (string) $term
                    )
            )
            ->filter()
            ->unique()
            ->values();

        if ($normalizedTerms->isEmpty()) {
            return collect();
        }

        return CategoryAlias::query()
            ->with('category')
            ->where('is_active', true)
            ->where(
                'alias_type',
                $termType
            )
            ->whereIn(
                'normalized_alias',
                $normalizedTerms
            )
            ->where(
                function ($query) use ($source): void {
                    $query
                        ->where(
                            'source',
                            $source
                        )
                        ->orWhere(
                            'source',
                            '*'
                        );
                }
            )
            ->orderByRaw(
                'CASE WHEN source = ? THEN 0 ELSE 1 END',
                [$source]
            )
            ->orderBy('priority')
            ->orderBy('normalized_alias')
            ->get();
    }

    public function matchedCategories(
        string $source,
        string $termType,
        iterable $terms
    ): Collection {
        return $this
            ->matchAliases(
                $source,
                $termType,
                $terms
            )
            ->pluck('category')
            ->filter()
            ->unique('id')
            ->values();
    }

    public function resolveSingleCategory(
        string $source,
        string $termType,
        iterable $terms
    ): ?Category {
        $categories = $this->matchedCategories(
            $source,
            $termType,
            $terms
        );

        if ($categories->count() !== 1) {
            return null;
        }

        return $categories->first();
    }
}
