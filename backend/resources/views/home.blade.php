@php
    $pageTitle =
        'Xurvexa | Video Discovery';

    $pageDescription =
        'Browse the latest videos, explore categories and watch embedded content from external video sources on Xurvexa.';

    $canonicalUrl =
        route('home');

    $ogType =
        'website';

    $ogImage =
        $featuredVideos->first()?->thumbnail
        ?: asset('images/og-default.jpg');

    $ogImageAlt =
        'Xurvexa video discovery';

    $robotsContent =
        app()->environment('production')
            ? 'index,follow'
            : 'noindex,nofollow';

    $showHeaderSearch = true;
@endphp

@extends('layouts.public')


@section('pageStyles')

    .site-header {
        position: sticky;

        top: 0;

        z-index: 100;
    }

    .header-inner {
        padding:
            14px 30px;
    }

    .page {
        max-width: 1500px;

        margin: 0 auto;

        padding: 30px;
    }

    .hero {
        margin-bottom: 40px;

        padding: 40px;

        border:
            1px solid #242424;

        border-radius: 14px;

        background:
            linear-gradient(
                135deg,
                #1c1c1c,
                #111
            );
    }

    .hero h1 {
        margin:
            0 0 12px;

        font-size: 38px;

        line-height: 1.15;
    }

    .hero p {
        max-width: 700px;

        margin: 0;

        color: #999;

        font-size: 15px;

        line-height: 1.7;
    }

    .hero-actions {
        margin-top: 24px;

        display: flex;
        flex-wrap: wrap;

        gap: 12px;
    }

    .button {
        padding:
            10px 18px;

        border-radius: 6px;

        background: #fff;
        color: #111;

        font-size: 13px;
        font-weight: 700;
    }

    .button.secondary {
        background: #222;
        color: #fff;
    }

    .section {
        margin-bottom: 48px;
    }

    .section-header {
        display: flex;

        align-items: center;
        justify-content: space-between;

        gap: 20px;

        margin-bottom: 20px;
    }

    .section-title {
        margin: 0;

        font-size: 24px;
    }

    .view-all {
        color: #999;

        font-size: 13px;
    }

    .view-all:hover {
        color: #fff;
    }

    .video-grid {
        display: grid;

        grid-template-columns:
            repeat(4, minmax(0, 1fr));

        gap: 24px;
    }

    .category-grid {
        display: grid;

        grid-template-columns:
            repeat(4, minmax(0, 1fr));

        gap: 14px;
    }

    .category-card {
        padding: 20px;

        background: #181818;

        border:
            1px solid #242424;

        border-radius: 9px;

        transition:
            background 0.2s ease,
            transform 0.2s ease;
    }

    .category-card:hover {
        background: #222;

        transform:
            translateY(-2px);
    }

    .category-name {
        font-size: 16px;
        font-weight: 700;
    }

    .category-count {
        margin-top: 6px;

        color: #777;

        font-size: 12px;
    }

    .empty-state {
        color: #777;

        font-size: 14px;
    }

    .footer {
        margin-top: 60px;

        padding:
            30px 0 10px;

        border-top:
            1px solid #222;

        color: #666;

        font-size: 12px;
    }

    @media (max-width: 1100px) {

        .video-grid,
        .category-grid {
            grid-template-columns:
                repeat(3, minmax(0, 1fr));
        }

    }

    @media (max-width: 750px) {

        .header-inner {
            padding:
                14px 18px;
        }

        .page {
            padding: 18px;
        }

        .hero {
            padding:
                26px 22px;
        }

        .hero h1 {
            font-size: 30px;
        }

        .video-grid,
        .category-grid {
            grid-template-columns:
                repeat(2, minmax(0, 1fr));
        }

    }

    @media (max-width: 500px) {

        .category-grid {
            grid-template-columns: 1fr;
        }

    }

@endsection


@section('content')

<main class="page">

    <section class="hero">

        <h1>
            Xurvexa
        </h1>

        <p>
            Browse the latest videos, explore categories
            and watch embedded content directly from
            external video sources.
        </p>

        <div class="hero-actions">

            <a
                class="button"
                href="{{ route('videos.index') }}"
            >
                Browse Videos
            </a>

            @if($categories->isNotEmpty())

                <a
                    class="button secondary"
                    href="{{ route(
                        'videos.category',
                        $categories->first()->slug
                    ) }}"
                >
                    Browse Categories
                </a>

            @endif

        </div>

    </section>


    <section class="section">

        <div class="section-header">

            <h2 class="section-title">
                Featured Videos
            </h2>

            <a
                class="view-all"
                href="{{ route(
                    'videos.index',
                    [
                        'sort' => 'views',
                    ]
                ) }}"
            >
                View popular videos
            </a>

        </div>

        <div class="video-grid">

            @forelse(
                $featuredVideos
                as $video
            )

                @include(
                    'partials.video-card',
                    [
                        'video' => $video,
                        'showSource' => false,
                        'compact' => false,
                    ]
                )

            @empty

                <div class="empty-state">
                    No featured videos available.
                </div>

            @endforelse

        </div>

    </section>


    <section class="section">

        <div class="section-header">

            <h2 class="section-title">
                Latest Videos
            </h2>

            <a
                class="view-all"
                href="{{ route('videos.index') }}"
            >
                View all videos
            </a>

        </div>

        <div class="video-grid">

            @forelse(
                $latestVideos
                as $video
            )

                @include(
                    'partials.video-card',
                    [
                        'video' => $video,
                        'showSource' => false,
                        'compact' => false,
                    ]
                )

            @empty

                <div class="empty-state">
                    No videos available.
                </div>

            @endforelse

        </div>

    </section>


    <section class="section">

        <div class="section-header">

            <h2 class="section-title">
                Categories
            </h2>

        </div>

        <div class="category-grid">

            @forelse(
                $categories
                as $category
            )

                <a
                    class="category-card"
                    href="{{ route(
                        'videos.category',
                        $category->slug
                    ) }}"
                >

                    <div class="category-name">

                        {{ $category->name }}

                    </div>

                    <div class="category-count">

                        {{ number_format(
                            $category->videos_count
                        ) }}
                        videos

                    </div>

                </a>

            @empty

                <div class="empty-state">
                    No categories available.
                </div>

            @endforelse

        </div>

    </section>


    <footer class="footer">
        Xurvexa
    </footer>

</main>

@endsection
