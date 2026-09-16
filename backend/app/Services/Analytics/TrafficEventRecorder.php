<?php

namespace App\Services\Analytics;

use App\Models\Category;
use App\Models\TrafficEvent;
use App\Models\Video;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class TrafficEventRecorder
{
    public function recordPageView(
        Request $request,
        SymfonyResponse $response,
        string $visitorUuid,
        string $sessionUuid
    ): ?TrafficEvent {
        if (
            ! $this->shouldRecord(
                $request,
                $response
            )
        ) {
            return null;
        }

        $context =
            $this->resolvePageContext(
                $request
            );

        return TrafficEvent::create([
            'event_uuid' =>
                (string) Str::uuid(),

            'visitor_uuid' =>
                $visitorUuid,

            'session_uuid' =>
                $sessionUuid,

            'event_type' =>
                TrafficEvent::EVENT_PAGE_VIEW,

            'page_type' =>
                $context['page_type'],

            'route_name' =>
                $context['route_name'],

            'path' =>
                $context['path'],

            'video_id' =>
                $context['video_id'],

            'category_id' =>
                $context['category_id'],

            'device_type' =>
                $this->resolveDeviceType(
                    $request
                ),

            'occurred_at' =>
                now(),
        ]);
    }

    private function shouldRecord(
        Request $request,
        SymfonyResponse $response
    ): bool {
        if (
            ! $request->isMethod(
                'GET'
            )
        ) {
            return false;
        }

        if (
            $response->getStatusCode()
            < 200
            || $response->getStatusCode()
            >= 300
        ) {
            return false;
        }

        $contentType =
            (string) $response
                ->headers
                ->get(
                    'Content-Type',
                    ''
                );

        if (
            ! str_contains(
                strtolower(
                    $contentType
                ),
                'text/html'
            )
        ) {
            return false;
        }

        $path =
            '/' .
            ltrim(
                $request->path(),
                '/'
            );

        if (
            $request->path()
            === '/'
        ) {
            $path = '/';
        }

        foreach (
            [
                '/admin',
                '/age-check',
                '/age-restricted',
                '/privacy/consent',
                '/livewire',
            ]
            as $blockedPrefix
        ) {
            if (
                $path
                === $blockedPrefix
                || str_starts_with(
                    $path,
                    $blockedPrefix . '/'
                )
            ) {
                return false;
            }
        }

        return true;
    }

    private function resolvePageContext(
        Request $request
    ): array {
        $path =
            '/' .
            ltrim(
                $request->path(),
                '/'
            );

        if (
            $request->path()
            === '/'
        ) {
            $path = '/';
        }

        $routeName =
            $request->route()?->getName();

        $pageType =
            TrafficEvent::PAGE_OTHER;

        $videoId =
            null;

        $categoryId =
            null;

        if (
            $path === '/'
        ) {
            $pageType =
                TrafficEvent::PAGE_HOME;
        } elseif (
            $path === '/videos'
        ) {
            $pageType =
                $request
                    ->filled(
                        'q'
                    )
                    ? TrafficEvent::PAGE_SEARCH
                    : TrafficEvent::PAGE_CATALOG;
        } elseif (
            preg_match(
                '#^/videos/([^/]+)$#',
                $path,
                $matches
            )
            === 1
        ) {
            $pageType =
                TrafficEvent::PAGE_VIDEO_DETAIL;

            $video =
                Video::query()
                    ->select([
                        'id',
                        'category_id',
                    ])
                    ->where(
                        'slug',
                        rawurldecode(
                            $matches[1]
                        )
                    )
                    ->first();

            $videoId =
                $video?->id;

            $categoryId =
                $video?->category_id;
        } elseif (
            preg_match(
                '#^/categories/([^/]+)$#',
                $path,
                $matches
            )
            === 1
        ) {
            $pageType =
                TrafficEvent::PAGE_CATEGORY;

            $categoryId =
                Category::query()
                    ->where(
                        'slug',
                        rawurldecode(
                            $matches[1]
                        )
                    )
                    ->value(
                        'id'
                    );
        } elseif (
            in_array(
                $path,
                [
                    '/about',
                    '/contact',
                    '/privacy',
                    '/terms',
                    '/cookie-policy',
                    '/2257',
                    '/content-removal',
                ],
                true
            )
        ) {
            $pageType =
                TrafficEvent::PAGE_CONTENT;
        }

        return [
            'page_type' =>
                $pageType,

            'route_name' =>
                is_string(
                    $routeName
                )
                    ? $routeName
                    : null,

            /*
             * Query strings are intentionally not
             * stored. This prevents search text from
             * becoming analytics data.
             */
            'path' =>
                $path,

            'video_id' =>
                $videoId,

            'category_id' =>
                $categoryId,
        ];
    }

    private function resolveDeviceType(
        Request $request
    ): string {
        $userAgent =
            strtolower(
                (string)
                $request->userAgent()
            );

        if (
            $userAgent === ''
        ) {
            return TrafficEvent::DEVICE_UNKNOWN;
        }

        if (
            str_contains(
                $userAgent,
                'ipad'
            )
            || str_contains(
                $userAgent,
                'tablet'
            )
        ) {
            return TrafficEvent::DEVICE_TABLET;
        }

        if (
            str_contains(
                $userAgent,
                'iphone'
            )
            || str_contains(
                $userAgent,
                'mobile'
            )
            || (
                str_contains(
                    $userAgent,
                    'android'
                )
                && ! str_contains(
                    $userAgent,
                    'tablet'
                )
            )
        ) {
            return TrafficEvent::DEVICE_MOBILE;
        }

        return TrafficEvent::DEVICE_DESKTOP;
    }
}
