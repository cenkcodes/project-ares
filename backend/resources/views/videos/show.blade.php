@php
    $pageTitle =
        $video->title .
        ' | Xurvexa';

    $rawDescription =
        $video->description
            ?: 'Watch ' .
                $video->title .
                ' on Xurvexa.';

    $pageDescription =
        \Illuminate\Support\Str::limit(
            preg_replace(
                '/\s+/',
                ' ',
                strip_tags($rawDescription)
            ),
            155,
            ''
        );

    $canonicalUrl =
        route(
            'videos.show',
            $video->slug
        );

    $robotsContent =
        app()->environment('production')
            ? 'index,follow'
            : 'noindex,nofollow';

    $ogType =
        'video.other';

    $ogImage =
        $video->thumbnail
        ?: asset('images/og-default.jpg');

    $ogImageAlt =
        $video->title;

    $showHeaderSearch = false;
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

        margin-top: 18px;

        min-height: 0;

        overflow: hidden;
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
        visibility: visible;
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

    .description {
        margin-top: 22px;

        padding-top: 20px;

        border-top:
            1px solid #292929;

        color: #ccc;

        font-size: 15px;

        line-height: 1.7;
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
            allowfullscreen
        >
        </iframe>

    </div>


    <div
        class="video-ad-slot"
        data-xurvexa-ad-slot
        data-enabled="false"
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

            @if($video->video_source)

                <span class="separator">
                    &middot;
                </span>

                <span>
                    {{ $video->video_source }}
                </span>

            @endif

            @if($video->duration)

                <span class="separator">
                    &middot;
                </span>

                <span>

                    {{ gmdate(
                        'H:i:s',
                        $video->duration
                    ) }}

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


        @if($video->description)

            <div class="description">

                {{ $video->description }}

            </div>

        @endif


        @if($video->video_source)

            <div class="source">

                Source:
                {{ $video->video_source }}

            </div>

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
