<x-filament-panels::page>

    <style>
        .xrv-dashboard {
            display: grid;
            gap: 1.5rem;
        }

        .xrv-dashboard-header {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            align-items: center;
            justify-content: space-between;
        }

        .xrv-dashboard-title {
            display: grid;
            gap: 0.25rem;
        }

        .xrv-dashboard-title strong {
            font-size: 1rem;
        }

        .xrv-dashboard-muted {
            opacity: 0.7;
            font-size: 0.875rem;
        }

        .xrv-range-switcher {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
        }

        .xrv-range-button {
            border: 1px solid rgba(128, 128, 128, 0.28);
            border-radius: 0.6rem;
            padding: 0.55rem 0.8rem;
            background: transparent;
            color: inherit;
            cursor: pointer;
            font-size: 0.82rem;
            font-weight: 600;
        }

        .xrv-range-button:hover {
            border-color: rgba(245, 158, 11, 0.8);
        }

        .xrv-range-button.is-active {
            border-color: rgb(245, 158, 11);
            background: rgba(245, 158, 11, 0.12);
        }

        .xrv-section {
            display: grid;
            gap: 1rem;
        }

        .xrv-section-heading {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
            align-items: baseline;
            justify-content: space-between;
        }

        .xrv-section-heading h2 {
            margin: 0;
            font-size: 1rem;
            font-weight: 700;
        }

        .xrv-card-grid {
            display: grid;
            grid-template-columns:
                repeat(
                    auto-fit,
                    minmax(170px, 1fr)
                );
            gap: 0.8rem;
        }

        .xrv-card {
            border:
                1px solid
                rgba(128, 128, 128, 0.2);
            border-radius: 0.8rem;
            padding: 1rem;
            background:
                rgba(128, 128, 128, 0.05);
            min-width: 0;
        }

        .xrv-card-label {
            opacity: 0.72;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        .xrv-card-value {
            margin-top: 0.35rem;
            font-size: 1.55rem;
            line-height: 1.15;
            font-weight: 800;
            overflow-wrap: anywhere;
        }

        .xrv-card-help {
            margin-top: 0.4rem;
            opacity: 0.65;
            font-size: 0.75rem;
            line-height: 1.45;
        }

        .xrv-note {
            border:
                1px solid
                rgba(245, 158, 11, 0.28);
            border-radius: 0.8rem;
            padding: 0.9rem 1rem;
            background:
                rgba(245, 158, 11, 0.07);
            font-size: 0.84rem;
            line-height: 1.55;
        }

        .xrv-note strong {
            font-weight: 700;
        }

        .xrv-table-wrap {
            overflow-x: auto;
            border:
                1px solid
                rgba(128, 128, 128, 0.2);
            border-radius: 0.8rem;
        }

        .xrv-table {
            width: 100%;
            min-width: 760px;
            border-collapse: collapse;
            font-size: 0.82rem;
        }

        .xrv-table.xrv-table-compact {
            min-width: 0;
        }

        .xrv-table th,
        .xrv-table td {
            padding: 0.72rem 0.8rem;
            text-align: left;
            border-bottom:
                1px solid
                rgba(128, 128, 128, 0.14);
            vertical-align: top;
        }

        .xrv-table th {
            opacity: 0.72;
            font-size: 0.72rem;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            white-space: nowrap;
        }

        .xrv-table tbody tr:last-child td {
            border-bottom: 0;
        }

        .xrv-table-number {
            text-align: right !important;
            white-space: nowrap;
        }

        .xrv-event-type {
            display: inline-flex;
            align-items: center;
            border:
                1px solid
                rgba(128, 128, 128, 0.25);
            border-radius: 999px;
            padding: 0.2rem 0.48rem;
            font-size: 0.72rem;
            font-weight: 700;
            text-transform: uppercase;
        }

        .xrv-two-column {
            display: grid;
            grid-template-columns:
                repeat(
                    2,
                    minmax(0, 1fr)
                );
            gap: 1rem;
        }

        .xrv-empty {
            padding: 1rem;
            opacity: 0.7;
            font-size: 0.84rem;
        }

        .xrv-video-title {
            max-width: 360px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        @media (max-width: 900px) {
            .xrv-two-column {
                grid-template-columns: 1fr;
            }
        }
    </style>

    <div class="xrv-dashboard">

        <div class="xrv-dashboard-header">

            <div class="xrv-dashboard-title">

                <strong>
                    {{ $rangeLabel }}
                </strong>

                <span class="xrv-dashboard-muted">

                    @if($rangeStart)

                        From
                        {{ $rangeStart
                            ->timezone(
                                config('app.timezone')
                            )
                            ->format(
                                'Y-m-d H:i'
                            ) }}

                    @else

                        Full tracked history

                    @endif

                </span>

            </div>

            <div class="xrv-range-switcher">

                @foreach([
                    'today' => 'Today',
                    '7d' => '7 Days',
                    '30d' => '30 Days',
                    'all' => 'All Time',
                ] as $rangeKey => $rangeText)

                    <button
                        type="button"
                        wire:click="setRange('{{ $rangeKey }}')"
                        class="
                            xrv-range-button
                            {{ $range === $rangeKey
                                ? 'is-active'
                                : '' }}
                        "
                    >
                        {{ $rangeText }}
                    </button>

                @endforeach

                <button
                    type="button"
                    wire:click="$refresh"
                    class="xrv-range-button"
                >
                    Refresh
                </button>

            </div>

        </div>


        @if($metrics['decisions'] < 100)

            <div class="xrv-note">

                <strong>
                    Early-data warning:
                </strong>

                the selected period contains only
                {{ number_format(
                    $metrics['decisions']
                ) }}
                monetization decisions.
                Rates are useful for validation,
                but they are not yet statistically
                strong enough for aggressive
                monetization decisions.

            </div>

        @endif


        <section class="xrv-section">

            <div class="xrv-section-heading">

                <h2>
                    Monetization Funnel
                </h2>

                <span class="xrv-dashboard-muted">
                    Real Xurvexa ad-event data
                </span>

            </div>

            <div class="xrv-card-grid">

                <div class="xrv-card">
                    <div class="xrv-card-label">
                        Opportunities
                    </div>
                    <div class="xrv-card-value">
                        {{ number_format(
                            $metrics['decisions']
                        ) }}
                    </div>
                    <div class="xrv-card-help">
                        Recorded ad decisions.
                    </div>
                </div>

                <div class="xrv-card">
                    <div class="xrv-card-label">
                        Show Decisions
                    </div>
                    <div class="xrv-card-value">
                        {{ number_format(
                            $metrics[
                                'show_decisions'
                            ]
                        ) }}
                    </div>
                    <div class="xrv-card-help">
                        Eligible opportunities with
                        a selected placement.
                    </div>
                </div>

                <div class="xrv-card">
                    <div class="xrv-card-label">
                        Impressions
                    </div>
                    <div class="xrv-card-value">
                        {{ number_format(
                            $metrics['impressions']
                        ) }}
                    </div>
                    <div class="xrv-card-help">
                        Confirmed rendered ads.
                    </div>
                </div>

                <div class="xrv-card">
                    <div class="xrv-card-label">
                        Eligibility Rate
                    </div>
                    <div class="xrv-card-value">
                        {{ number_format(
                            $metrics['fill_rate'],
                            1
                        ) }}%
                    </div>
                    <div class="xrv-card-help">
                        Show decisions ÷ all
                        opportunities. This is an
                        eligibility rate, not an ad
                        network fill-rate.
                    </div>
                </div>

                <div class="xrv-card">
                    <div class="xrv-card-label">
                        Render Rate
                    </div>
                    <div class="xrv-card-value">
                        {{ number_format(
                            $metrics['render_rate'],
                            1
                        ) }}%
                    </div>
                    <div class="xrv-card-help">
                        Impressions ÷ show
                        decisions.
                    </div>
                </div>

                <div class="xrv-card">
                    <div class="xrv-card-label">
                        CTR
                    </div>
                    <div class="xrv-card-value">
                        {{ number_format(
                            $metrics['ctr'],
                            2
                        ) }}%
                    </div>
                    <div class="xrv-card-help">
                        Tracked clicks ÷
                        impressions.
                    </div>
                </div>

                <div class="xrv-card">
                    <div class="xrv-card-label">
                        Ad Sessions
                    </div>
                    <div class="xrv-card-value">
                        {{ number_format(
                            $metrics[
                                'tracked_sessions'
                            ]
                        ) }}
                    </div>
                    <div class="xrv-card-help">
                        Distinct monetization
                        session keys, not unique
                        website visitors.
                    </div>
                </div>

                <div class="xrv-card">
                    <div class="xrv-card-label">
                        Errors
                    </div>
                    <div class="xrv-card-value">
                        {{ number_format(
                            $metrics['errors']
                        ) }}
                    </div>
                    <div class="xrv-card-help">
                        Recorded ad-delivery errors.
                    </div>
                </div>

            </div>

        </section>


        <section class="xrv-section">

            <div class="xrv-section-heading">

                <h2>
                    Revenue
                </h2>

                <span class="xrv-dashboard-muted">
                    Network-reported revenue only
                </span>

            </div>

            @if(
                $metrics['revenue_rows'] === 0
            )

                <div class="xrv-note">

                    <strong>
                        Revenue feed not connected yet.
                    </strong>

                    Xurvexa already supports
                    <code>revenue_micros</code> and
                    <code>currency</code> on ad
                    impressions, but no current event
                    contains network revenue.
                    Revenue is therefore intentionally
                    not estimated.

                </div>

            @else

                <div class="xrv-card-grid">

                    @foreach(
                        $revenueByCurrency
                        as $revenue
                    )

                        <div class="xrv-card">

                            <div class="xrv-card-label">
                                {{ $revenue[
                                    'currency'
                                ] }}
                                Revenue
                            </div>

                            <div class="xrv-card-value">

                                {{ number_format(
                                    $revenue[
                                        'amount'
                                    ],
                                    6
                                ) }}

                                {{ $revenue[
                                    'currency'
                                ] }}

                            </div>

                            <div class="xrv-card-help">
                                Exact tracked network
                                revenue.
                            </div>

                        </div>

                    @endforeach

                </div>

            @endif

        </section>


        <section class="xrv-section">

            <div class="xrv-section-heading">

                <h2>
                    Traffic Baseline
                </h2>

                <span class="xrv-dashboard-muted">
                    Current reliable counters only
                </span>

            </div>

            <div class="xrv-card-grid">

                <div class="xrv-card">

                    <div class="xrv-card-label">
                        Video Detail Views
                    </div>

                    <div class="xrv-card-value">
                        {{ number_format(
                            $metrics[
                                'all_time_video_views'
                            ]
                        ) }}
                    </div>

                    <div class="xrv-card-help">
                        All-time video detail-page
                        opens from the existing
                        videos.views counter.
                    </div>

                </div>

                <div class="xrv-card">

                    <div class="xrv-card-label">
                        Videos With Views
                    </div>

                    <div class="xrv-card-value">
                        {{ number_format(
                            $metrics[
                                'videos_with_views'
                            ]
                        ) }}
                    </div>

                    <div class="xrv-card-help">
                        Videos whose current
                        all-time view counter is
                        above zero.
                    </div>

                </div>

            </div>

            <div class="xrv-note">

                <strong>
                    Traffic metric boundary:
                </strong>

                the current video counter increments
                when a video detail page is opened.
                It is not a verified video-play,
                unique-visitor, returning-visitor,
                bounce-rate or pages-per-session
                measurement. Those metrics will be
                added only after a dedicated
                privacy-safe traffic event layer is
                installed.

            </div>

        </section>


        <div class="xrv-two-column">

            <section class="xrv-section">

                <div class="xrv-section-heading">
                    <h2>
                        Networks
                    </h2>
                </div>

                <div class="xrv-table-wrap">

                    <table class="xrv-table xrv-table-compact">

                        <thead>
                            <tr>
                                <th>Network</th>
                                <th class="xrv-table-number">
                                    Decisions
                                </th>
                                <th class="xrv-table-number">
                                    Shows
                                </th>
                                <th class="xrv-table-number">
                                    Impressions
                                </th>
                                <th class="xrv-table-number">
                                    Clicks
                                </th>
                                <th class="xrv-table-number">
                                    Errors
                                </th>
                            </tr>
                        </thead>

                        <tbody>

                            @forelse(
                                $networkBreakdown
                                as $row
                            )

                                <tr>
                                    <td>
                                        {{ $row->network }}
                                    </td>
                                    <td class="xrv-table-number">
                                        {{ number_format(
                                            (int)
                                            $row->decisions
                                        ) }}
                                    </td>
                                    <td class="xrv-table-number">
                                        {{ number_format(
                                            (int)
                                            $row->shows
                                        ) }}
                                    </td>
                                    <td class="xrv-table-number">
                                        {{ number_format(
                                            (int)
                                            $row->impressions
                                        ) }}
                                    </td>
                                    <td class="xrv-table-number">
                                        {{ number_format(
                                            (int)
                                            $row->clicks
                                        ) }}
                                    </td>
                                    <td class="xrv-table-number">
                                        {{ number_format(
                                            (int)
                                            $row->errors
                                        ) }}
                                    </td>
                                </tr>

                            @empty

                                <tr>
                                    <td
                                        colspan="6"
                                        class="xrv-empty"
                                    >
                                        No network data.
                                    </td>
                                </tr>

                            @endforelse

                        </tbody>

                    </table>

                </div>

            </section>


            <section class="xrv-section">

                <div class="xrv-section-heading">
                    <h2>
                        Devices
                    </h2>
                </div>

                <div class="xrv-table-wrap">

                    <table class="xrv-table xrv-table-compact">

                        <thead>
                            <tr>
                                <th>Device</th>
                                <th class="xrv-table-number">
                                    Decisions
                                </th>
                                <th class="xrv-table-number">
                                    Impressions
                                </th>
                                <th class="xrv-table-number">
                                    Clicks
                                </th>
                                <th class="xrv-table-number">
                                    Errors
                                </th>
                            </tr>
                        </thead>

                        <tbody>

                            @forelse(
                                $deviceBreakdown
                                as $row
                            )

                                <tr>
                                    <td>
                                        {{ ucfirst(
                                            $row->device
                                        ) }}
                                    </td>
                                    <td class="xrv-table-number">
                                        {{ number_format(
                                            (int)
                                            $row->decisions
                                        ) }}
                                    </td>
                                    <td class="xrv-table-number">
                                        {{ number_format(
                                            (int)
                                            $row->impressions
                                        ) }}
                                    </td>
                                    <td class="xrv-table-number">
                                        {{ number_format(
                                            (int)
                                            $row->clicks
                                        ) }}
                                    </td>
                                    <td class="xrv-table-number">
                                        {{ number_format(
                                            (int)
                                            $row->errors
                                        ) }}
                                    </td>
                                </tr>

                            @empty

                                <tr>
                                    <td
                                        colspan="5"
                                        class="xrv-empty"
                                    >
                                        No device data.
                                    </td>
                                </tr>

                            @endforelse

                        </tbody>

                    </table>

                </div>

            </section>

        </div>


        @if(
            $metrics['skip_decisions'] > 0
        )

            <section class="xrv-section">

                <div class="xrv-section-heading">

                    <h2>
                        Skip Reasons
                    </h2>

                    <span class="xrv-dashboard-muted">
                        Why opportunities did not
                        receive an ad
                    </span>

                </div>

                <div class="xrv-table-wrap">

                    <table class="xrv-table xrv-table-compact">

                        <thead>
                            <tr>
                                <th>Reason</th>
                                <th class="xrv-table-number">
                                    Count
                                </th>
                            </tr>
                        </thead>

                        <tbody>

                            @foreach(
                                $skipReasons
                                as $row
                            )

                                <tr>
                                    <td>
                                        {{ $row->reason }}
                                    </td>
                                    <td class="xrv-table-number">
                                        {{ number_format(
                                            (int)
                                            $row->total
                                        ) }}
                                    </td>
                                </tr>

                            @endforeach

                        </tbody>

                    </table>

                </div>

            </section>

        @endif


        <div class="xrv-two-column">

            <section class="xrv-section">

                <div class="xrv-section-heading">

                    <h2>
                        Top Videos
                    </h2>

                    <span class="xrv-dashboard-muted">
                        All-time detail views
                    </span>

                </div>

                <div class="xrv-table-wrap">

                    <table class="xrv-table xrv-table-compact">

                        <thead>
                            <tr>
                                <th>Video</th>
                                <th>Category</th>
                                <th class="xrv-table-number">
                                    Views
                                </th>
                            </tr>
                        </thead>

                        <tbody>

                            @foreach(
                                $topVideos
                                as $video
                            )

                                <tr>

                                    <td
                                        class="xrv-video-title"
                                        title="{{ $video->title }}"
                                    >
                                        {{ $video->title }}
                                    </td>

                                    <td>
                                        {{ $video->category_name
                                            ?? 'Uncategorized' }}
                                    </td>

                                    <td class="xrv-table-number">
                                        {{ number_format(
                                            (int)
                                            $video->views
                                        ) }}
                                    </td>

                                </tr>

                            @endforeach

                        </tbody>

                    </table>

                </div>

            </section>


            <section class="xrv-section">

                <div class="xrv-section-heading">

                    <h2>
                        Category Views
                    </h2>

                    <span class="xrv-dashboard-muted">
                        All-time detail views
                    </span>

                </div>

                <div class="xrv-table-wrap">

                    <table class="xrv-table xrv-table-compact">

                        <thead>
                            <tr>
                                <th>Category</th>
                                <th class="xrv-table-number">
                                    Views
                                </th>
                            </tr>
                        </thead>

                        <tbody>

                            @foreach(
                                $categoryViews
                                as $row
                            )

                                <tr>
                                    <td>
                                        {{ $row->category }}
                                    </td>
                                    <td class="xrv-table-number">
                                        {{ number_format(
                                            (int)
                                            $row->views
                                        ) }}
                                    </td>
                                </tr>

                            @endforeach

                        </tbody>

                    </table>

                </div>

            </section>

        </div>


        <section class="xrv-section">

            <div class="xrv-section-heading">

                <h2>
                    Latest Ad Events
                </h2>

                <span class="xrv-dashboard-muted">
                    Most recent 20 events in the
                    selected period
                </span>

            </div>

            <div class="xrv-table-wrap">

                <table class="xrv-table">

                    <thead>
                        <tr>
                            <th>Time</th>
                            <th>Event</th>
                            <th>Outcome</th>
                            <th>Network</th>
                            <th>Device</th>
                            <th>Video</th>
                            <th>Reason</th>
                        </tr>
                    </thead>

                    <tbody>

                        @forelse(
                            $latestEvents
                            as $event
                        )

                            <tr>

                                <td>
                                    {{
                                        \Carbon\CarbonImmutable::parse(
                                            $event->occurred_at
                                        )
                                            ->timezone(
                                                config(
                                                    'app.timezone'
                                                )
                                            )
                                            ->format(
                                                'Y-m-d H:i:s'
                                            )
                                    }}
                                </td>

                                <td>
                                    <span class="xrv-event-type">
                                        {{ $event->event_type }}
                                    </span>
                                </td>

                                <td>
                                    {{ $event->decision_outcome
                                        ?? '—' }}
                                </td>

                                <td>
                                    {{ $event->ad_network
                                        ?? '—' }}
                                </td>

                                <td>
                                    {{ $event->device_type
                                        ?? 'unknown' }}
                                </td>

                                <td
                                    class="xrv-video-title"
                                    title="{{ $event->video_title }}"
                                >
                                    {{ $event->video_title
                                        ?? (
                                            $event->video_id
                                                ? 'Video #'
                                                    . $event->video_id
                                                : '—'
                                        ) }}
                                </td>

                                <td>
                                    {{ $event->decision_reason
                                        ?? '—' }}
                                </td>

                            </tr>

                        @empty

                            <tr>
                                <td
                                    colspan="7"
                                    class="xrv-empty"
                                >
                                    No ad events in this
                                    reporting period.
                                </td>
                            </tr>

                        @endforelse

                    </tbody>

                </table>

            </div>

        </section>

    </div>

</x-filament-panels::page>
