<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Video;
use Illuminate\Http\Response;

class SeoController extends Controller
{
    public function sitemap(): Response
    {
        $categories = Category::query()
            ->where('is_active', true)
            ->select([
                'slug',
                'updated_at',
            ])
            ->orderBy('id')
            ->get();

        $videos = Video::query()
            ->where('is_active', true)
            ->select([
                'slug',
                'updated_at',
            ])
            ->orderBy('id')
            ->get();

        $guides =
            collect(
                config('guide-seo.guides', [])
            )
                ->filter(
                    fn ($guide): bool =>
                        is_array($guide) &&
                        ($guide['is_active'] ?? false) === true
                )
                ->map(
                    fn ($guide, $slug): array => [
                        'slug' => (string) $slug,
                        'updated_at' =>
                            (string) ($guide['updated_at'] ?? ''),
                    ]
                )
                ->values();

        $latestGuideUpdate =
            $guides
                ->pluck('updated_at')
                ->filter()
                ->sortDesc()
                ->first();

        $latestCategoryUpdate =
            $categories
                ->pluck('updated_at')
                ->filter()
                ->sortDesc()
                ->first();

        $latestVideoUpdate =
            $videos
                ->pluck('updated_at')
                ->filter()
                ->sortDesc()
                ->first();

        $siteLastModified =
            collect([
                $latestCategoryUpdate,
                $latestVideoUpdate,
            ])
                ->filter()
                ->sortDesc()
                ->first();

        return response()
            ->view(
                'seo.sitemap',
                [
                    'categories' =>
                        $categories,

                    'videos' =>
                        $videos,

                    'guides' =>
                        $guides,

                    'latestGuideUpdate' =>
                        $latestGuideUpdate,

                    'siteLastModified' =>
                        $siteLastModified,
                ]
            )
            ->header(
                'Content-Type',
                'application/xml; charset=UTF-8'
            );
    }

    public function videoSitemap(): Response
    {
        $videos = Video::query()
            ->where('is_active', true)
            ->select([
                'slug',
                'title',
                'description',
                'thumbnail',
                'embed_url',
                'duration',
                'created_at',
                'updated_at',
            ])
            ->whereNotNull('thumbnail')
            ->where('thumbnail', '!=', '')
            ->whereNotNull('embed_url')
            ->where('embed_url', '!=', '')
            ->whereNotNull('duration')
            ->whereBetween(
                'duration',
                [
                    1,
                    28800,
                ]
            )
            ->whereNotNull('created_at')
            ->orderBy('id')
            ->get();

        return response()
            ->view(
                'seo.video-sitemap',
                [
                    'videos' =>
                        $videos,
                ]
            )
            ->header(
                'Content-Type',
                'application/xml; charset=UTF-8'
            );
    }

    public function robots(): Response
    {
        if (! app()->environment('production')) {
            $content = implode(PHP_EOL, [
                'User-agent: *',
                'Disallow: /',
                '',
            ]);

            return response(
                $content,
                200,
                [
                    'Content-Type' =>
                        'text/plain; charset=UTF-8',
                ]
            );
        }

        $content = implode(PHP_EOL, [
            'User-agent: *',
            'Allow: /',
            '',
            'Disallow: /admin',
            'Disallow: /dashboard',
            'Disallow: /profile',
            '',
            'Sitemap: ' . route('seo.sitemap'),
            'Sitemap: ' . route('seo.video-sitemap'),
            '',
        ]);

        return response(
            $content,
            200,
            [
                'Content-Type' =>
                    'text/plain; charset=UTF-8',
            ]
        );
    }
}
