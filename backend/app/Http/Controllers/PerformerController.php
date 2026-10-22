<?php

namespace App\Http\Controllers;

use App\Models\Video;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PerformerController extends Controller
{
    public function show(
        Request $request,
        string $slug
    ): View {
        $performers =
            config(
                'performer-seo.performers',
                []
            );

        $performer =
            $performers[$slug] ?? null;

        abort_if(
            ! is_array($performer) ||
            ($performer['is_active'] ?? false) !== true,
            404
        );

        $configuredVideoSlugs =
            collect(
                $performer['video_slugs'] ?? []
            )
                ->filter(
                    fn ($videoSlug): bool =>
                        is_string($videoSlug) &&
                        trim($videoSlug) !== ''
                )
                ->map(
                    fn ($videoSlug): string =>
                        trim($videoSlug)
                )
                ->unique()
                ->values();

        $videoOrder =
            $configuredVideoSlugs
                ->values()
                ->flip();

        $videos =
            Video::query()
                ->with('category')
                ->where('is_active', true)
                ->whereIn(
                    'slug',
                    $configuredVideoSlugs->all()
                )
                ->get()
                ->sortBy(
                    fn (Video $video): int =>
                        (int) (
                            $videoOrder->get(
                                $video->slug,
                                PHP_INT_MAX
                            )
                        )
                )
                ->values();

        $minimumActiveVideos =
            max(
                1,
                (int) config(
                    'performer-seo.minimum_active_videos_for_index',
                    5
                )
            );

        $globalExposureEnabled =
            config(
                'performer-seo.index_exposure_enabled',
                false
            ) === true;

        $performerIndexable =
            ($performer['is_indexable'] ?? false) === true;

        $performerPublished =
            is_string(
                $performer['published_at'] ?? null
            ) &&
            trim(
                (string) $performer['published_at']
            ) !== '';

        $requestHasQueryParameters =
            $request->query() !== [];

        $searchExposureEligible =
            $globalExposureEnabled &&
            $performerIndexable &&
            $performerPublished &&
            $videos->count() >= $minimumActiveVideos &&
            ! $requestHasQueryParameters;

        $robotsContent =
            ! app()->environment('production')
                ? 'noindex,nofollow'
                : (
                    $searchExposureEligible
                        ? 'index,follow'
                        : 'noindex,follow'
                );

        return view(
            'performers.show',
            [
                'performer' =>
                    array_merge(
                        $performer,
                        [
                            'slug' => $slug,
                        ]
                    ),

                'videos' =>
                    $videos,

                'minimumActiveVideos' =>
                    $minimumActiveVideos,

                'searchExposureEligible' =>
                    $searchExposureEligible,

                'robotsContent' =>
                    $robotsContent,
            ]
        );
    }
}