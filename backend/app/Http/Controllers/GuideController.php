<?php

namespace App\Http\Controllers;

use Illuminate\Support\Collection;
use Illuminate\View\View;

class GuideController extends Controller
{
    public function index(): View
    {
        return view(
            'guides.index',
            [
                'guideIndex' =>
                    config('guide-seo.index', []),

                'guides' =>
                    $this->activeGuides(),
            ]
        );
    }

    public function show(string $slug): View
    {
        $guides = $this->activeGuides();

        $guide = $guides->get($slug);

        abort_if(
            !is_array($guide),
            404
        );

        $relatedGuides =
            collect(
                $guide['related_guides'] ?? []
            )
                ->mapWithKeys(
                    function ($relatedSlug) use ($guides): array {
                        $related =
                            $guides->get(
                                (string) $relatedSlug
                            );

                        if (!is_array($related)) {
                            return [];
                        }

                        return [
                            (string) $relatedSlug =>
                                $related,
                        ];
                    }
                );

        return view(
            'guides.show',
            [
                'guide' =>
                    array_merge(
                        $guide,
                        [
                            'slug' => $slug,
                        ]
                    ),

                'relatedGuides' =>
                    $relatedGuides,
            ]
        );
    }

    private function activeGuides(): Collection
    {
        return collect(
            config('guide-seo.guides', [])
        )
            ->filter(
                fn ($guide): bool =>
                    is_array($guide) &&
                    ($guide['is_active'] ?? false) === true
            );
    }
}
