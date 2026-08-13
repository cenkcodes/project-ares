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

                    'siteLastModified' =>
                        $siteLastModified,
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
