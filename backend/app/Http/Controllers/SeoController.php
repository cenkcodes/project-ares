<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\SeoTopic;
use App\Models\Video;
use Illuminate\Http\Response;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Builder;
use App\Models\VideoSeoContent;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SeoController extends Controller
{
    private const VIDEO_SITEMAP_PAGE_SIZE = 5000;

    public function sitemap(): Response
    {
        $topics = collect();

        if (config('seo.topic_index_exposure_enabled', false) === true) {
            $topics = SeoTopic::query()
                ->searchExposureEligible()
                ->orderBy('id')
                ->get(['slug', 'published_at']);
        }

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
            ->whereHas('seoContent', fn (Builder $query) => $this->requirePublishedSeo($query))
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
                    'topics' => $topics,

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

    public function videoSitemap(
        Request $request
    ): StreamedResponse {
        $partValue = $request->query('part');

        if ($partValue === null) {
            return $this->videoSitemapIndex();
        }

        $part = filter_var(
            $partValue,
            FILTER_VALIDATE_INT,
            [
                'options' => [
                    'min_range' => 1,
                ],
            ]
        );

        if ($part === false) {
            abort(404);
        }

        $eligibleCount =
            $this->eligibleVideoSitemapQuery()
                ->count();

        $totalParts =
            (int) ceil(
                $eligibleCount /
                self::VIDEO_SITEMAP_PAGE_SIZE
            );

        if (
            $totalParts === 0 ||
            $part > $totalParts
        ) {
            abort(404);
        }

        return response()->stream(
            function () use ($part): void {
                echo '<?xml version="1.0" encoding="UTF-8"?>';
                echo "\n";
                echo '<urlset';
                echo ' xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"';
                echo ' xmlns:video="http://www.google.com/schemas/sitemap-video/1.1"';
                echo '>';
                echo "\n";

                $videos =
                    $this->eligibleVideoSitemapQuery()
                        ->select([
                            'id',
                            'slug',
                            'title',
                            'thumbnail',
                            'embed_url',
                            'duration',
                            'created_at',
                            'updated_at',
                        ])
                        ->addSelect([
                            'seo_description' => $this->requirePublishedSeo(
                                VideoSeoContent::query()
                            )
                                ->select('description')
                                ->whereColumn('video_id', 'videos.id'),
                        ])
                        ->orderBy('id')
                        ->forPage(
                            $part,
                            self::VIDEO_SITEMAP_PAGE_SIZE
                        )
                        ->cursor();

                foreach ($videos as $video) {
                    $rawDescription = (string) $video->seo_description;

                    $collapsedDescription =
                        preg_replace(
                            '/\s+/u',
                            ' ',
                            strip_tags(
                                $rawDescription
                            )
                        );

                    $videoDescription =
                        Str::limit(
                            $collapsedDescription
                                ?? strip_tags(
                                    $rawDescription
                                ),
                            2048,
                            ''
                        );

                    echo "  <url>\n";
                    echo '    <loc>' .
                        $this->xml(
                            route(
                                'videos.show',
                                $video->slug
                            )
                        ) .
                        "</loc>\n";

                    if ($video->updated_at) {
                        echo '    <lastmod>' .
                            $this->xml(
                                $video->updated_at
                                    ->toAtomString()
                            ) .
                            "</lastmod>\n";
                    }

                    echo "    <video:video>\n";
                    echo '      <video:thumbnail_loc>' .
                        $this->xml(
                            (string) $video->thumbnail
                        ) .
                        "</video:thumbnail_loc>\n";
                    echo '      <video:title>' .
                        $this->xml(
                            (string) $video->title
                        ) .
                        "</video:title>\n";
                    echo '      <video:description>' .
                        $this->xml(
                            $videoDescription
                        ) .
                        "</video:description>\n";
                    echo '      <video:player_loc>' .
                        $this->xml(
                            (string) $video->embed_url
                        ) .
                        "</video:player_loc>\n";
                    echo '      <video:duration>' .
                        (int) $video->duration .
                        "</video:duration>\n";
                    echo '      <video:publication_date>' .
                        $this->xml(
                            $video->created_at
                                ->toAtomString()
                        ) .
                        "</video:publication_date>\n";
                    echo "      <video:family_friendly>no</video:family_friendly>\n";
                    echo "    </video:video>\n";
                    echo "  </url>\n";

                    if (ob_get_level() > 0) {
                        @ob_flush();
                    }

                    flush();
                }

                echo "</urlset>\n";
            },
            200,
            $this->xmlResponseHeaders()
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
    private function videoSitemapIndex(): StreamedResponse
    {
        $eligibleCount =
            $this->eligibleVideoSitemapQuery()
                ->count();

        $totalParts =
            (int) ceil(
                $eligibleCount /
                self::VIDEO_SITEMAP_PAGE_SIZE
            );

        return response()->stream(
            function () use ($totalParts): void {
                echo '<?xml version="1.0" encoding="UTF-8"?>';
                echo "\n";
                echo '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
                echo "\n";

                for (
                    $part = 1;
                    $part <= $totalParts;
                    $part++
                ) {
                    $url =
                        route('seo.video-sitemap') .
                        '?part=' .
                        $part;

                    echo "  <sitemap>\n";
                    echo '    <loc>' .
                        $this->xml($url) .
                        "</loc>\n";
                    echo "  </sitemap>\n";
                }

                echo "</sitemapindex>\n";
            },
            200,
            $this->xmlResponseHeaders()
        );
    }

    private function eligibleVideoSitemapQuery(): Builder
    {
        return Video::query()
            ->where('is_active', true)
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
            ->whereHas('seoContent', function (Builder $query): void {
                $this->requirePublishedSeo($query);
            });
    }

    /**
     * Same readiness conditions as watch-page index eligibility.
     * PostgreSQL text cannot contain NUL; trim the remaining PHP trim characters.
     */
    private function requirePublishedSeo(Builder $query): Builder
    {
        return $query
            ->where('quality_status', 'published')
            ->whereNotNull('published_at')
            ->whereNotNull('description')
            ->whereRaw("btrim(description, chr(32) || chr(9) || chr(10) || chr(11) || chr(13)) <> ''")
            ->whereNotNull('meta_description')
            ->whereRaw("btrim(meta_description, chr(32) || chr(9) || chr(10) || chr(11) || chr(13)) <> ''");
    }

    private function xmlResponseHeaders(): array
    {
        return [
            'Content-Type' =>
                'application/xml; charset=UTF-8',
            'Cache-Control' =>
                'public, max-age=300',
            'X-Accel-Buffering' =>
                'no',
        ];
    }

    private function xml(string $value): string
    {
        $withoutInvalidControls =
            preg_replace(
                '/[\x00-\x08\x0B\x0C\x0E-\x1F]/',
                '',
                $value
            ) ?? '';

        return htmlspecialchars(
            $withoutInvalidControls,
            ENT_XML1 |
                ENT_QUOTES |
                ENT_SUBSTITUTE,
            'UTF-8'
        );
    }
}
