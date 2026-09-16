@php
    $pageTitle =
        $video->title .
        ' | Xurvexa';

    $canonicalUrl =
        route(
            'videos.show',
            $video->slug
        );

    $robotsContent = $robotsContent ?? 'noindex,nofollow';

    $ogType =
        'video.other';

    $ogImage =
        $video->thumbnail
        ?: asset('images/og-default.jpg');

    $ogImageAlt =
        $video->title;

    $showHeaderSearch = false;

    $videoStructuredData = null;

    if (
        $video->thumbnail &&
        $video->created_at
    ) {
        $durationSeconds =
            max(
                0,
                (int) $video->duration
            );

        $durationHours =
            intdiv(
                $durationSeconds,
                3600
            );

        $durationMinutes =
            intdiv(
                $durationSeconds % 3600,
                60
            );

        $durationRemainderSeconds =
            $durationSeconds % 60;

        $durationParts = [];

        if ($durationHours > 0) {
            $durationParts[] =
                $durationHours . 'H';
        }

        if ($durationMinutes > 0) {
            $durationParts[] =
                $durationMinutes . 'M';
        }

        if (
            $durationRemainderSeconds > 0 ||
            $durationParts === []
        ) {
            $durationParts[] =
                $durationRemainderSeconds . 'S';
        }

        $videoStructuredData = [
            '@context' =>
                'https://schema.org',

            '@type' =>
                'VideoObject',

            'name' =>
                $video->title,

            'description' =>
                $videoStructuredDescription,

            'thumbnailUrl' => [
                $video->thumbnail,
            ],

            /*
             * Xurvexa does not currently store the
             * source platform's original publication
             * date. created_at is therefore used as
             * the date this watch page/video entry was
             * first published on Xurvexa.
             */
            'uploadDate' =>
                $video->created_at
                    ->toIso8601String(),

        ];

        if ($durationSeconds > 0) {
            $videoStructuredData['duration'] = 'PT' . implode('', $durationParts);
        }

        if ($video->embed_url) {
            $videoStructuredData['embedUrl'] =
                $video->embed_url;
        }
    }
@endphp

@extends('layouts.public')



@section('pageStyles')

    .page {
        max-width: 1250px;

        margin: 0 auto;

        padding: 30px;
    }

    .back-link {
        display: inline-block;

        margin-bottom: 20px;

        color: #aaa;

        font-size: 14px;
    }

    .back-link:hover {
        color: #fff;
    }

    .video-wrapper {
        position: relative;

        width: 100%;

        aspect-ratio: 16 / 9;

        background: #000;

        overflow: hidden;

        border-radius: 10px;
    }

    .video-wrapper iframe {
        width: 100%;
        height: 100%;

        display: block;

        border: 0;
    }

    .video-ad-slot {
        display: none;

        width: 100%;

        margin:
            18px auto 0;

        min-height: 0;

        overflow: hidden;

        text-align: center;
    }

    .video-ad-slot[data-enabled="true"] {
        display: block;
    }

    .video-ad-slot[data-ad-state="disabled"],
    .video-ad-slot[data-ad-state="skipped"],
    .video-ad-slot[data-ad-state="unsupported"],
    .video-ad-slot[data-ad-state="expired"],
    .video-ad-slot[data-ad-state="discarded"],
    .video-ad-slot[data-ad-state="error"] {
        display: none;
    }

    .video-ad-slot[data-ad-state="ready"],
    .video-ad-slot[data-ad-state="requesting"] {
        visibility: hidden;
    }

    .video-ad-slot[data-ad-state="rendering"],
    .video-ad-slot[data-ad-state="rendered"] {
        display: grid;

        place-items:
            start center;

        visibility: visible;
    }

    .video-ad-slot[data-ad-state="rendering"] > *,
    .video-ad-slot[data-ad-state="rendered"] > * {
        margin-left:
            auto !important;

        margin-right:
            auto !important;
    }

    .video-info {
        padding:
            22px 0 0;
    }

    .title {
        margin: 0;

        color: #fff;

        font-size: 28px;

        line-height: 1.3;
    }

    .meta {
        display: flex;

        flex-wrap: wrap;

        align-items: center;

        gap: 10px;

        margin-top: 12px;

        color: #999;

        font-size: 13px;
    }

    .separator {
        color: #555;
    }

    .badge {
        display: inline-block;

        padding:
            4px 7px;

        background: #292929;

        border-radius: 4px;

        color: #fff;

        font-size: 11px;
        font-weight: 700;
    }

    .category-link {
        color: #bbb;
    }

    .category-link:hover {
        color: #fff;
    }

    .about-section {
        margin-top: 22px;

        padding: 22px;

        border:
            1px solid #292929;

        border-radius: 10px;

        background: #151515;
    }

    .about-title {
        margin: 0 0 12px;

        color: #fff;

        font-size: 18px;

        line-height: 1.3;
    }

    .description,
    .video-summary {
        margin: 0;

        color: #ccc;

        font-size: 15px;

        line-height: 1.7;
    }

    .description-original {
        margin-top: 16px;

        padding-top: 16px;

        border-top:
            1px solid #292929;
    }

    .topics {
        margin-top: 18px;
    }

    .topics-label {
        margin-bottom: 10px;

        color: #888;

        font-size: 12px;
        font-weight: 700;

        letter-spacing: 0.04em;

        text-transform: uppercase;
    }

    .topic-list {
        display: flex;

        flex-wrap: wrap;

        gap: 8px;
    }

    .topic-chip {
        display: inline-flex;

        align-items: center;

        padding:
            7px 10px;

        border:
            1px solid #303030;

        border-radius: 999px;

        background: #1c1c1c;

        color: #ccc;

        font-size: 13px;

        line-height: 1;

        text-decoration: none;
    }

    .topic-chip:hover {
        border-color: #4a4a4a;

        color: #fff;
    }

    .source {
        margin-top: 16px;

        color: #777;

        font-size: 12px;
    }

    .related-section {
        margin-top: 50px;

        padding-top: 30px;

        border-top:
            1px solid #222;
    }

    .related-header {
        display: flex;

        justify-content: space-between;
        align-items: center;

        gap: 20px;

        margin-bottom: 20px;
    }

    .related-title {
        margin: 0;

        font-size: 22px;
    }

    .related-grid {
        display: grid;

        grid-template-columns:
            repeat(4, minmax(0, 1fr));

        gap: 20px;
    }

    .empty-state {
        color: #777;

        font-size: 14px;
    }

    @media (max-width: 1000px) {

        .related-grid {
            grid-template-columns:
                repeat(3, minmax(0, 1fr));
        }

    }

    @media (max-width: 700px) {

        .page {
            padding-left: 18px;
            padding-right: 18px;
        }

        .title {
            font-size: 22px;
        }

        .video-wrapper {
            border-radius: 6px;
        }

        .related-grid {
            grid-template-columns:
                repeat(2, minmax(0, 1fr));
        }

    }

@endsection



@section('content')

@if($videoStructuredData)

    <script type="application/ld+json">
{!! json_encode(
    $videoStructuredData,
    JSON_UNESCAPED_SLASHES
    | JSON_UNESCAPED_UNICODE
    | JSON_HEX_TAG
    | JSON_HEX_AMP
    | JSON_HEX_APOS
    | JSON_HEX_QUOT
) !!}
    </script>

@endif



<div
    data-xurvexa-monetization
    data-interaction-url="{{ route('monetization.interaction') }}"
    data-decision-url="{{ route('monetization.decision') }}"
    data-event-url="{{ route('monetization.event') }}"
    data-csrf-token="{{ csrf_token() }}"
    data-video-id="{{ $video->id }}"
    data-placement-key="video_player"
    hidden
>
</div>



<main class="page">

    <a
        class="back-link"
        href="{{ route('videos.index') }}"
    >
        &larr; Back to videos
    </a>



    <div class="video-wrapper">

        <iframe
            src="{{ $video->embed_url }}"
            title="{{ $video->title }}"
            loading="lazy"
            allow="autoplay; fullscreen; picture-in-picture"
            @if($video->video_source === 'xnxx')
                sandbox="allow-scripts allow-same-origin allow-presentation"
            @endif
            allowfullscreen
        >
        </iframe>

    </div>



    <div
        class="video-ad-slot"
        data-xurvexa-ad-slot
        data-enabled="true"
        data-format="banner"
        data-placement-key="video_banner"
        data-ad-state="idle"
        aria-hidden="true"
    >
    </div>



    <div class="video-info">

        <h1 class="title">
            {{ $video->title }}
        </h1>

        <div class="meta">

            <span>

                {{ number_format(
                    $video->views
                ) }}
                views

            </span>

            @if($sourceLabel)

                <span class="separator">
                    &middot;
                </span>

                <span>
                    {{ $sourceLabel }}
                </span>

            @endif

            @if($durationLabel)

                <span class="separator">
                    &middot;
                </span>

                <span>
                    {{ $durationLabel }}
                </span>

            @endif

            @if($video->category)

                <span class="separator">
                    &middot;
                </span>

                <a
                    class="category-link"
                    href="{{ route(
                        'videos.category',
                        $video->category->slug
                    ) }}"
                >
                    {{ $video->category->name }}
                </a>

            @endif

            @if($video->is_4k)

                <span class="badge">
                    4K
                </span>

            @elseif($video->is_hd)

                <span class="badge">
                    HD
                </span>

            @endif

        </div>



        @if($publishedSeoDescription !== null || $topicCategories->isNotEmpty() || $sourceLabel)
        <section
            class="about-section"
            aria-labelledby="about-video-title"
        >

            <h2
                id="about-video-title"
                class="about-title"
            >
                About this video
            </h2>

            @if($publishedSeoDescription !== null)
                <p class="video-summary">
                    {{ $publishedSeoDescription }}
                </p>
            @endif

            @if($topicCategories->isNotEmpty())

                <div class="topics">

                    <div class="topics-label">
                        Topics
                    </div>

                    <nav
                        class="topic-list"
                        aria-label="Video topics"
                    >

                        @foreach(
                            $topicCategories
                            as $topicCategory
                        )

                            <a
                                class="topic-chip"
                                href="{{ route(
                                    'videos.category',
                                    $topicCategory->slug
                                ) }}"
                            >
                                {{ $topicCategory->name }}
                            </a>

                        @endforeach

                    </nav>

                </div>

            @endif

            @if($sourceLabel)

                <div class="source">
                    Source:
                    {{ $sourceLabel }}
                </div>

            @endif

        </section>
        @endif

    </div>



    <section class="related-section">

        <div class="related-header">

            <h2 class="related-title">
                Related Videos
            </h2>

            @if($video->category)

                <a
                    class="category-link"
                    href="{{ route(
                        'videos.category',
                        $video->category->slug
                    ) }}"
                >
                    View
                    {{ $video->category->name }}
                </a>

            @endif

        </div>

        <div class="related-grid">

            @forelse(
                $relatedVideos
                as $relatedVideo
            )

                @include(
                    'partials.video-card',
                    [
                        'video' => $relatedVideo,
                        'showSource' => false,
                        'compact' => true,
                    ]
                )

            @empty

                <div class="empty-state">
                    No related videos available.
                </div>

            @endforelse

        </div>

    </section>

</main>



@vite([
    'resources/js/video-monetization.js',
    'resources/js/video-adapter.js',
    'resources/js/video-banner-renderer.js',
])

@endsection
