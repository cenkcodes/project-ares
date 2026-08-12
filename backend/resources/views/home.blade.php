<!DOCTYPE html>
<html lang="en">

<head>

    @php
        $pageTitle = 'Project Ares | Video Discovery';

        $pageDescription =
            'Browse the latest videos, explore categories and watch embedded content from external video sources on Project Ares.';

        $canonicalUrl = route('home');

        $ogImage =
            $featuredVideos->first()?->thumbnail
            ?: asset('images/og-default.jpg');

        $robotsContent = app()->environment('production')
            ? 'index,follow'
            : 'noindex,nofollow';
    @endphp

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>{{ $pageTitle }}</title>

    <meta
        name="description"
        content="{{ $pageDescription }}"
    >

    <meta
        name="robots"
        content="{{ $robotsContent }}"
    >

    <link
        rel="canonical"
        href="{{ $canonicalUrl }}"
    >

    <meta
        property="og:site_name"
        content="Project Ares"
    >

    <meta
        property="og:type"
        content="website"
    >

    <meta
        property="og:title"
        content="{{ $pageTitle }}"
    >

    <meta
        property="og:description"
        content="{{ $pageDescription }}"
    >

    <meta
        property="og:url"
        content="{{ $canonicalUrl }}"
    >

    <meta
        property="og:image"
        content="{{ $ogImage }}"
    >

    <meta
        property="og:image:alt"
        content="Project Ares video discovery"
    >

    <meta
        name="twitter:card"
        content="summary_large_image"
    >

    <meta
        name="twitter:title"
        content="{{ $pageTitle }}"
    >

    <meta
        name="twitter:description"
        content="{{ $pageDescription }}"
    >

    <meta
        name="twitter:image"
        content="{{ $ogImage }}"
    >

    <style>

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;

            background: #0d0d0d;
            color: #fff;

            font-family:
                Arial,
                Helvetica,
                sans-serif;
        }

        a {
            color: inherit;
            text-decoration: none;
        }

        .site-header {
            position: sticky;

            top: 0;

            z-index: 100;

            background:
                rgba(13, 13, 13, 0.96);

            border-bottom:
                1px solid #222;
        }

        .header-inner {
            max-width: 1500px;

            margin: 0 auto;

            padding: 14px 30px;

            display: flex;

            align-items: center;

            gap: 25px;
        }

        .logo {
            flex-shrink: 0;

            font-size: 22px;
            font-weight: 800;

            letter-spacing: 1px;
        }

        .logo span {
            color: #888;
        }

        .header-search {
            flex: 1;

            max-width: 620px;

            display: flex;
        }

        .header-search input {
            width: 100%;

            height: 40px;

            padding: 0 13px;

            border:
                1px solid #333;

            border-radius:
                6px 0 0 6px;

            background: #151515;
            color: #fff;

            outline: none;
        }

        .header-search button {
            height: 40px;

            padding: 0 17px;

            border: 0;

            border-radius:
                0 6px 6px 0;

            background: #fff;
            color: #111;

            font-weight: 700;

            cursor: pointer;
        }

        .main-nav {
            margin-left: auto;

            display: flex;

            align-items: center;

            gap: 18px;

            color: #aaa;

            font-size: 14px;
        }

        .main-nav a:hover {
            color: #fff;
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
            padding: 10px 18px;

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

        .video-card {
            min-width: 0;
        }

        .thumbnail {
            position: relative;

            width: 100%;

            aspect-ratio: 16 / 9;

            background: #222;

            border-radius: 9px;

            overflow: hidden;
        }

        .thumbnail img {
            width: 100%;
            height: 100%;

            display: block;
            object-fit: cover;

            transition:
                transform 0.25s ease,
                opacity 0.25s ease;
        }

        .video-card:hover .thumbnail img {
            transform: scale(1.04);
            opacity: 0.9;
        }

        .thumbnail-placeholder {
            width: 100%;
            height: 100%;

            display: flex;

            align-items: center;
            justify-content: center;

            background:
                linear-gradient(
                    135deg,
                    #222,
                    #333
                );

            color: #777;

            font-size: 13px;
        }

        .play-button {
            position: absolute;

            left: 50%;
            top: 50%;

            transform:
                translate(-50%, -50%);

            width: 52px;
            height: 52px;

            border-radius: 50%;

            display: flex;
            align-items: center;
            justify-content: center;

            background:
                rgba(0, 0, 0, 0.72);

            font-size: 20px;

            pointer-events: none;
        }

        .duration {
            position: absolute;

            right: 8px;
            bottom: 8px;

            padding: 4px 7px;

            background:
                rgba(0, 0, 0, 0.82);

            border-radius: 4px;

            font-size: 11px;
            font-weight: 600;
        }

        .badges {
            position: absolute;

            left: 8px;
            bottom: 8px;

            display: flex;
            gap: 5px;
        }

        .badge {
            padding: 4px 6px;

            background:
                rgba(0, 0, 0, 0.82);

            border-radius: 4px;

            font-size: 10px;
            font-weight: 700;
        }

        .video-title {
            display: -webkit-box;

            margin-top: 10px;

            color: #fff;

            font-size: 15px;
            font-weight: 600;

            line-height: 1.4;

            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;

            overflow: hidden;
        }

        .video-title:hover {
            color: #ccc;
        }

        .video-meta {
            margin-top: 7px;

            color: #777;

            font-size: 12px;
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

                flex-wrap: wrap;
            }

            .header-search {
                order: 3;

                flex-basis: 100%;

                max-width: none;
            }

            .page {
                padding: 18px;
            }

            .hero {
                padding: 26px 22px;
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

            .main-nav {
                display: none;
            }

            .category-grid {
                grid-template-columns: 1fr;
            }

        }

    </style>

</head>

<body>

<header class="site-header">

    <div class="header-inner">

        <a
            class="logo"
            href="{{ route('home') }}"
        >
            PROJECT <span>ARES</span>
        </a>

        <form
            class="header-search"
            method="GET"
            action="{{ route('videos.index') }}"
        >

            <input
                type="search"
                name="q"
                placeholder="Search videos..."
                aria-label="Search videos"
            >

            <button type="submit">
                Search
            </button>

        </form>

        <nav class="main-nav">

            <a href="{{ route('home') }}">
                Home
            </a>

            <a href="{{ route('videos.index') }}">
                Videos
            </a>

        </nav>

    </div>

</header>

<main class="page">

    <section class="hero">

        <h1>
            Project Ares
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
                href="{{ route('videos.index', [
                    'sort' => 'views'
                ]) }}"
            >
                View popular videos
            </a>

        </div>

        <div class="video-grid">

            @forelse($featuredVideos as $video)

                <article class="video-card">

                    <a href="{{ route('videos.show', $video->slug) }}">

                        <div class="thumbnail">

                            @if($video->thumbnail)

                                <img
                                    src="{{ $video->thumbnail }}"
                                    alt="{{ $video->title }}"
                                    loading="lazy"
                                >

                            @else

                                <div class="thumbnail-placeholder">
                                    No thumbnail
                                </div>

                            @endif

                            <div class="play-button">
                                &#9654;
                            </div>

                            @if($video->duration)

                                <div class="duration">

                                    {{ gmdate('H:i:s', $video->duration) }}

                                </div>

                            @endif

                            <div class="badges">

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

                        </div>

                    </a>

                    <a
                        class="video-title"
                        href="{{ route('videos.show', $video->slug) }}"
                    >
                        {{ $video->title }}
                    </a>

                    <div class="video-meta">

                        {{ number_format($video->views) }}
                        views

                        @if($video->category)

                            &middot;
                            {{ $video->category->name }}

                        @endif

                    </div>

                </article>

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

            @forelse($latestVideos as $video)

                <article class="video-card">

                    <a href="{{ route('videos.show', $video->slug) }}">

                        <div class="thumbnail">

                            @if($video->thumbnail)

                                <img
                                    src="{{ $video->thumbnail }}"
                                    alt="{{ $video->title }}"
                                    loading="lazy"
                                >

                            @else

                                <div class="thumbnail-placeholder">
                                    No thumbnail
                                </div>

                            @endif

                            <div class="play-button">
                                &#9654;
                            </div>

                            @if($video->duration)

                                <div class="duration">

                                    {{ gmdate('H:i:s', $video->duration) }}

                                </div>

                            @endif

                            <div class="badges">

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

                        </div>

                    </a>

                    <a
                        class="video-title"
                        href="{{ route('videos.show', $video->slug) }}"
                    >
                        {{ $video->title }}
                    </a>

                    <div class="video-meta">

                        {{ number_format($video->views) }}
                        views

                        @if($video->category)

                            &middot;
                            {{ $video->category->name }}

                        @endif

                    </div>

                </article>

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

            @forelse($categories as $category)

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

                        {{ number_format($category->videos_count) }}
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
        Project Ares
    </footer>

</main>

</body>

</html>
