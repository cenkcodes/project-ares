<?php

namespace App\Filament\Pages;

use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Pages\Page;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use UnitEnum;

class TrafficRevenue extends Page
{
    protected static string | BackedEnum | null $navigationIcon =
        'heroicon-o-chart-bar';

    protected static ?string $navigationLabel =
        'Traffic & Revenue';

    protected static string | UnitEnum | null $navigationGroup =
        'Monetization';

    protected static ?int $navigationSort = 31;

    protected static ?string $title =
        'Traffic & Revenue';

    protected string $view =
        'filament.pages.traffic-revenue';

    public string $range = '7d';

    /**
     * Change the reporting window without leaving the page.
     */
    public function setRange(string $range): void
    {
        if (
            ! in_array(
                $range,
                [
                    'today',
                    '7d',
                    '30d',
                    'all',
                ],
                true
            )
        ) {
            return;
        }

        $this->range = $range;
    }

    /**
     * Build the dashboard from the existing monetization
     * events plus consent-based Traffic V2 page-view data.
     *
     * Traffic V2 measures page/detail navigation only.
     * It must not be presented as verified media playback.
     */
    protected function getViewData(): array
    {
        [
            $from,
            $rangeLabel,
        ] = $this->resolveRange();

        $baseEvents =
            $this->adEvents(
                $from
            );

        $trafficEvents =
            $this->trafficEvents(
                $from
            );

        $decisions =
            (clone $baseEvents)
                ->where(
                    'event_type',
                    'decision'
                )
                ->count();

        $showDecisions =
            (clone $baseEvents)
                ->where(
                    'event_type',
                    'decision'
                )
                ->where(
                    'decision_outcome',
                    'show'
                )
                ->count();

        $skipDecisions =
            (clone $baseEvents)
                ->where(
                    'event_type',
                    'decision'
                )
                ->where(
                    'decision_outcome',
                    'skip'
                )
                ->count();

        $impressions =
            (clone $baseEvents)
                ->where(
                    'event_type',
                    'impression'
                )
                ->count();

        $clicks =
            (clone $baseEvents)
                ->where(
                    'event_type',
                    'click'
                )
                ->count();

        $errors =
            (clone $baseEvents)
                ->where(
                    'event_type',
                    'error'
                )
                ->count();

        $trackedSessions =
            (clone $baseEvents)
                ->whereNotNull(
                    'session_key'
                )
                ->distinct()
                ->count(
                    'session_key'
                );

        $fillRate =
            $decisions > 0
                ? round(
                    (
                        $showDecisions /
                        $decisions
                    ) * 100,
                    1
                )
                : 0.0;

        $renderRate =
            $showDecisions > 0
                ? round(
                    (
                        $impressions /
                        $showDecisions
                    ) * 100,
                    1
                )
                : 0.0;

        $ctr =
            $impressions > 0
                ? round(
                    (
                        $clicks /
                        $impressions
                    ) * 100,
                    2
                )
                : 0.0;

        $revenueRows =
            (clone $baseEvents)
                ->whereNotNull(
                    'revenue_micros'
                )
                ->count();

        $revenueByCurrency =
            (clone $baseEvents)
                ->whereNotNull(
                    'revenue_micros'
                )
                ->whereNotNull(
                    'currency'
                )
                ->select(
                    'currency'
                )
                ->selectRaw(
                    'SUM(revenue_micros) AS revenue_micros'
                )
                ->groupBy(
                    'currency'
                )
                ->orderBy(
                    'currency'
                )
                ->get()
                ->map(
                    function ($row): array {
                        $micros =
                            (int) $row->revenue_micros;

                        return [
                            'currency' =>
                                strtoupper(
                                    (string) $row->currency
                                ),

                            'micros' =>
                                $micros,

                            'amount' =>
                                $micros /
                                1_000_000,
                        ];
                    }
                )
                ->all();

        $networkBreakdown =
            (clone $baseEvents)
                ->selectRaw(
                    "
                    COALESCE(
                        ad_network,
                        'unassigned'
                    ) AS network
                    "
                )
                ->selectRaw(
                    "
                    SUM(
                        CASE
                            WHEN event_type = 'decision'
                            THEN 1
                            ELSE 0
                        END
                    ) AS decisions
                    "
                )
                ->selectRaw(
                    "
                    SUM(
                        CASE
                            WHEN event_type = 'decision'
                                AND decision_outcome = 'show'
                            THEN 1
                            ELSE 0
                        END
                    ) AS shows
                    "
                )
                ->selectRaw(
                    "
                    SUM(
                        CASE
                            WHEN event_type = 'impression'
                            THEN 1
                            ELSE 0
                        END
                    ) AS impressions
                    "
                )
                ->selectRaw(
                    "
                    SUM(
                        CASE
                            WHEN event_type = 'click'
                            THEN 1
                            ELSE 0
                        END
                    ) AS clicks
                    "
                )
                ->selectRaw(
                    "
                    SUM(
                        CASE
                            WHEN event_type = 'error'
                            THEN 1
                            ELSE 0
                        END
                    ) AS errors
                    "
                )
                ->groupByRaw(
                    "
                    COALESCE(
                        ad_network,
                        'unassigned'
                    )
                    "
                )
                ->orderByDesc(
                    'impressions'
                )
                ->orderBy(
                    'network'
                )
                ->get();

        $deviceBreakdown =
            (clone $baseEvents)
                ->selectRaw(
                    "
                    COALESCE(
                        device_type,
                        'unknown'
                    ) AS device
                    "
                )
                ->selectRaw(
                    "
                    SUM(
                        CASE
                            WHEN event_type = 'decision'
                            THEN 1
                            ELSE 0
                        END
                    ) AS decisions
                    "
                )
                ->selectRaw(
                    "
                    SUM(
                        CASE
                            WHEN event_type = 'impression'
                            THEN 1
                            ELSE 0
                        END
                    ) AS impressions
                    "
                )
                ->selectRaw(
                    "
                    SUM(
                        CASE
                            WHEN event_type = 'click'
                            THEN 1
                            ELSE 0
                        END
                    ) AS clicks
                    "
                )
                ->selectRaw(
                    "
                    SUM(
                        CASE
                            WHEN event_type = 'error'
                            THEN 1
                            ELSE 0
                        END
                    ) AS errors
                    "
                )
                ->groupByRaw(
                    "
                    COALESCE(
                        device_type,
                        'unknown'
                    )
                    "
                )
                ->orderByDesc(
                    'impressions'
                )
                ->orderBy(
                    'device'
                )
                ->get();

        $skipReasons =
            (clone $baseEvents)
                ->where(
                    'event_type',
                    'decision'
                )
                ->where(
                    'decision_outcome',
                    'skip'
                )
                ->selectRaw(
                    "
                    COALESCE(
                        decision_reason,
                        'unspecified'
                    ) AS reason
                    "
                )
                ->selectRaw(
                    'COUNT(*) AS total'
                )
                ->groupByRaw(
                    "
                    COALESCE(
                        decision_reason,
                        'unspecified'
                    )
                    "
                )
                ->orderByDesc(
                    'total'
                )
                ->limit(10)
                ->get();

        $latestEvents =
            (clone $baseEvents)
                ->leftJoin(
                    'videos',
                    'videos.id',
                    '=',
                    'ad_events.video_id'
                )
                ->select([
                    'ad_events.id',
                    'ad_events.event_type',
                    'ad_events.decision_outcome',
                    'ad_events.decision_reason',
                    'ad_events.placement_key',
                    'ad_events.ad_network',
                    'ad_events.device_type',
                    'ad_events.video_id',
                    'ad_events.session_key',
                    'ad_events.revenue_micros',
                    'ad_events.currency',
                    'ad_events.occurred_at',
                    'videos.slug AS video_slug',
                    'videos.title AS video_title',
                ])
                ->orderByDesc(
                    'ad_events.occurred_at'
                )
                ->orderByDesc(
                    'ad_events.id'
                )
                ->limit(20)
                ->get();

        $allTimeVideoViews =
            (int) (
                DB::table(
                    'videos'
                )
                    ->sum(
                        'views'
                    )
                ?? 0
            );

        $videosWithViews =
            DB::table(
                'videos'
            )
                ->where(
                    'views',
                    '>',
                    0
                )
                ->count();

        $topVideos =
            DB::table(
                'videos'
            )
                ->leftJoin(
                    'categories',
                    'categories.id',
                    '=',
                    'videos.category_id'
                )
                ->select([
                    'videos.id',
                    'videos.title',
                    'videos.slug',
                    'videos.views',
                    'categories.name AS category_name',
                ])
                ->orderByDesc(
                    'videos.views'
                )
                ->orderByDesc(
                    'videos.id'
                )
                ->limit(10)
                ->get();

        $categoryViews =
            DB::table(
                'videos'
            )
                ->leftJoin(
                    'categories',
                    'categories.id',
                    '=',
                    'videos.category_id'
                )
                ->selectRaw(
                    "
                    COALESCE(
                        categories.name,
                        'Uncategorized'
                    ) AS category
                    "
                )
                ->selectRaw(
                    'SUM(videos.views) AS views'
                )
                ->groupByRaw(
                    "
                    COALESCE(
                        categories.name,
                        'Uncategorized'
                    )
                    "
                )
                ->orderByDesc(
                    'views'
                )
                ->orderBy(
                    'category'
                )
                ->get();


        /*
         * Consent-based Traffic V2 metrics.
         *
         * These values begin only after Traffic V2 was
         * installed and analytics consent was granted.
         * They do not reconstruct historical traffic.
         */
        $trafficPageViews =
            (clone $trafficEvents)
                ->count();

        $trafficUniqueVisitors =
            (clone $trafficEvents)
                ->distinct()
                ->count(
                    'visitor_uuid'
                );

        $trafficSessions =
            (clone $trafficEvents)
                ->distinct()
                ->count(
                    'session_uuid'
                );

        $trafficVideoDetailViews =
            (clone $trafficEvents)
                ->where(
                    'page_type',
                    'video_detail'
                )
                ->count();

        $trafficVideosPerVisitor =
            $trafficUniqueVisitors > 0
                ? round(
                    $trafficVideoDetailViews /
                    $trafficUniqueVisitors,
                    2
                )
                : 0.0;

        $videoSessionCounts =
            (clone $trafficEvents)
                ->where(
                    'page_type',
                    'video_detail'
                )
                ->whereNotNull(
                    'session_uuid'
                )
                ->select(
                    'session_uuid'
                )
                ->selectRaw(
                    'COUNT(*) AS video_views'
                )
                ->groupBy(
                    'session_uuid'
                )
                ->get();

        $trafficVideoSessions =
            $videoSessionCounts
                ->count();

        $trafficSecondVideoSessions =
            $videoSessionCounts
                ->filter(
                    fn ($row): bool =>
                        (int) $row->video_views
                        >= 2
                )
                ->count();

        $trafficSecondVideoRate =
            $trafficVideoSessions > 0
                ? round(
                    (
                        $trafficSecondVideoSessions /
                        $trafficVideoSessions
                    ) * 100,
                    1
                )
                : 0.0;

        if (
            $from !== null
            && $trafficUniqueVisitors > 0
        ) {
            $periodVisitors =
                (clone $trafficEvents)
                    ->select(
                        'visitor_uuid'
                    )
                    ->distinct();

            $trafficReturningVisitors =
                DB::table(
                    'traffic_events'
                )
                    ->whereIn(
                        'visitor_uuid',
                        $periodVisitors
                    )
                    ->where(
                        'occurred_at',
                        '<',
                        $from
                    )
                    ->distinct()
                    ->count(
                        'visitor_uuid'
                    );
        } else {
            $returningVisitorQuery =
                DB::table(
                    'traffic_events'
                )
                    ->select(
                        'visitor_uuid'
                    )
                    ->selectRaw(
                        '
                        COUNT(
                            DISTINCT session_uuid
                        ) AS session_count
                        '
                    )
                    ->groupBy(
                        'visitor_uuid'
                    );

            $trafficReturningVisitors =
                DB::query()
                    ->fromSub(
                        $returningVisitorQuery,
                        'returning_visitors'
                    )
                    ->where(
                        'session_count',
                        '>=',
                        2
                    )
                    ->count();
        }

        $trafficNewVisitors =
            max(
                0,
                $trafficUniqueVisitors -
                $trafficReturningVisitors
            );

        $trafficPageTypes =
            (clone $trafficEvents)
                ->select(
                    'page_type'
                )
                ->selectRaw(
                    'COUNT(*) AS page_views'
                )
                ->selectRaw(
                    '
                    COUNT(
                        DISTINCT visitor_uuid
                    ) AS visitors
                    '
                )
                ->groupBy(
                    'page_type'
                )
                ->orderByDesc(
                    'page_views'
                )
                ->orderBy(
                    'page_type'
                )
                ->get();

        $trafficDeviceBreakdown =
            (clone $trafficEvents)
                ->selectRaw(
                    "
                    COALESCE(
                        device_type,
                        'unknown'
                    ) AS device
                    "
                )
                ->selectRaw(
                    'COUNT(*) AS page_views'
                )
                ->selectRaw(
                    '
                    COUNT(
                        DISTINCT visitor_uuid
                    ) AS visitors
                    '
                )
                ->selectRaw(
                    "
                    SUM(
                        CASE
                            WHEN page_type = 'video_detail'
                            THEN 1
                            ELSE 0
                        END
                    ) AS video_detail_views
                    "
                )
                ->groupByRaw(
                    "
                    COALESCE(
                        device_type,
                        'unknown'
                    )
                    "
                )
                ->orderByDesc(
                    'page_views'
                )
                ->orderBy(
                    'device'
                )
                ->get();

        $trafficCategoryBreakdown =
            (clone $trafficEvents)
                ->leftJoin(
                    'categories',
                    'categories.id',
                    '=',
                    'traffic_events.category_id'
                )
                ->where(
                    'traffic_events.page_type',
                    'video_detail'
                )
                ->selectRaw(
                    "
                    COALESCE(
                        categories.name,
                        'Uncategorized'
                    ) AS category
                    "
                )
                ->selectRaw(
                    '
                    COUNT(*) AS video_detail_views
                    '
                )
                ->selectRaw(
                    '
                    COUNT(
                        DISTINCT
                        traffic_events.visitor_uuid
                    ) AS visitors
                    '
                )
                ->groupByRaw(
                    "
                    COALESCE(
                        categories.name,
                        'Uncategorized'
                    )
                    "
                )
                ->orderByDesc(
                    'video_detail_views'
                )
                ->orderBy(
                    'category'
                )
                ->get();

        $dailyTrafficTrend =
            (clone $trafficEvents)
                ->selectRaw(
                    'DATE(occurred_at) AS day'
                )
                ->selectRaw(
                    'COUNT(*) AS page_views'
                )
                ->selectRaw(
                    '
                    COUNT(
                        DISTINCT visitor_uuid
                    ) AS visitors
                    '
                )
                ->selectRaw(
                    '
                    COUNT(
                        DISTINCT session_uuid
                    ) AS sessions
                    '
                )
                ->selectRaw(
                    "
                    SUM(
                        CASE
                            WHEN page_type = 'video_detail'
                            THEN 1
                            ELSE 0
                        END
                    ) AS video_detail_views
                    "
                )
                ->groupByRaw(
                    'DATE(occurred_at)'
                )
                ->orderBy(
                    'day'
                )
                ->get();

        $trafficTopVideos =
            (clone $trafficEvents)
                ->join(
                    'videos',
                    'videos.id',
                    '=',
                    'traffic_events.video_id'
                )
                ->leftJoin(
                    'categories',
                    'categories.id',
                    '=',
                    'videos.category_id'
                )
                ->where(
                    'traffic_events.page_type',
                    'video_detail'
                )
                ->select([
                    'videos.id',
                    'videos.title',
                    'videos.slug',
                    'categories.name AS category_name',
                ])
                ->selectRaw(
                    '
                    COUNT(*) AS video_detail_views
                    '
                )
                ->selectRaw(
                    '
                    COUNT(
                        DISTINCT
                        traffic_events.visitor_uuid
                    ) AS visitors
                    '
                )
                ->groupBy(
                    'videos.id',
                    'videos.title',
                    'videos.slug',
                    'categories.name'
                )
                ->orderByDesc(
                    'video_detail_views'
                )
                ->orderByDesc(
                    'visitors'
                )
                ->limit(10)
                ->get();

        return [
            'rangeLabel' =>
                $rangeLabel,

            'rangeStart' =>
                $from,

            'metrics' => [
                'decisions' =>
                    $decisions,

                'show_decisions' =>
                    $showDecisions,

                'skip_decisions' =>
                    $skipDecisions,

                'impressions' =>
                    $impressions,

                'clicks' =>
                    $clicks,

                'errors' =>
                    $errors,

                'tracked_sessions' =>
                    $trackedSessions,

                'fill_rate' =>
                    $fillRate,

                'render_rate' =>
                    $renderRate,

                'ctr' =>
                    $ctr,

                'revenue_rows' =>
                    $revenueRows,

                'all_time_video_views' =>
                    $allTimeVideoViews,

                'videos_with_views' =>
                    $videosWithViews,

                'traffic_page_views' =>
                    $trafficPageViews,

                'traffic_unique_visitors' =>
                    $trafficUniqueVisitors,

                'traffic_new_visitors' =>
                    $trafficNewVisitors,

                'traffic_returning_visitors' =>
                    $trafficReturningVisitors,

                'traffic_sessions' =>
                    $trafficSessions,

                'traffic_video_detail_views' =>
                    $trafficVideoDetailViews,

                'traffic_videos_per_visitor' =>
                    $trafficVideosPerVisitor,

                'traffic_video_sessions' =>
                    $trafficVideoSessions,

                'traffic_second_video_sessions' =>
                    $trafficSecondVideoSessions,

                'traffic_second_video_rate' =>
                    $trafficSecondVideoRate,
            ],

            'trafficPageTypes' =>
                $trafficPageTypes,

            'trafficDeviceBreakdown' =>
                $trafficDeviceBreakdown,

            'trafficCategoryBreakdown' =>
                $trafficCategoryBreakdown,

            'dailyTrafficTrend' =>
                $dailyTrafficTrend,

            'trafficTopVideos' =>
                $trafficTopVideos,

            'revenueByCurrency' =>
                $revenueByCurrency,

            'networkBreakdown' =>
                $networkBreakdown,

            'deviceBreakdown' =>
                $deviceBreakdown,

            'skipReasons' =>
                $skipReasons,

            'latestEvents' =>
                $latestEvents,

            'topVideos' =>
                $topVideos,

            'categoryViews' =>
                $categoryViews,
        ];
    }

    /**
     * Base ad-event query for the selected reporting range.
     */
    private function adEvents(
        ?CarbonImmutable $from
    ): Builder {
        return DB::table(
            'ad_events'
        )
            ->when(
                $from !== null,
                function (
                    Builder $query
                ) use ($from): void {
                    $query->where(
                        'occurred_at',
                        '>=',
                        $from
                    );
                }
            );
    }

    /**
     * Base consent-based traffic-event query for the
     * selected reporting range.
     */
    private function trafficEvents(
        ?CarbonImmutable $from
    ): Builder {
        return DB::table(
            'traffic_events'
        )
            ->when(
                $from !== null,
                function (
                    Builder $query
                ) use ($from): void {
                    $query->where(
                        'occurred_at',
                        '>=',
                        $from
                    );
                }
            );
    }

    /**
     * Resolve the current reporting window.
     */
    private function resolveRange(): array
    {
        $now =
            CarbonImmutable::now();

        return match (
            $this->range
        ) {
            'today' => [
                $now->startOfDay(),
                'Today',
            ],

            '30d' => [
                $now
                    ->subDays(29)
                    ->startOfDay(),
                'Last 30 days',
            ],

            'all' => [
                null,
                'All time',
            ],

            default => [
                $now
                    ->subDays(6)
                    ->startOfDay(),
                'Last 7 days',
            ],
        };
    }
}
