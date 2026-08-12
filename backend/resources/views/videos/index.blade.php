<!DOCTYPE html>
<html lang="en">

<head>

    @php
        $pageNumber = $videos->currentPage();

        $hasSeoFilters =
            $search !== '' ||
            $sort !== 'latest';

        if ($search !== '') {
            $pageTitle =
                'Search: ' .
                $search .
                ' | Project Ares';
        } elseif ($activeCategory) {
            $pageTitle =
                $activeCategory->name .
                ' Videos | Project Ares';
        } else {
            $pageTitle =
                'Videos | Project Ares';
        }

        if ($activeCategory) {
            $pageDescription =
                $activeCategory->description
                    ?: 'Browse ' .
                        $activeCategory->name .
                        ' videos on Project Ares.';
        } else {
            $pageDescription =
                'Browse videos on Project Ares. Search, sort and explore available video categories.';
        }

        $canonicalParameters = [];

        if (
            ! $hasSeoFilters &&
            $pageNumber > 1
        ) {
            $canonicalParameters['page'] =
                $pageNumber;
        }

        if ($activeCategory) {
            $canonicalUrl = route(
                'videos.category',
                array_merge(
                    [
                        'slug' =>
                            $activeCategory->slug,
                    ],
                    $canonicalParameters
                )
            );
        } else {
            $canonicalUrl = route(
                'videos.index',
                $canonicalParameters
            );
        }

        if (! app()->environment('production')) {
            $robotsContent =
                'noindex,nofollow';
        } elseif ($hasSeoFilters) {
            $robotsContent =
                'noindex,follow';
        } else {
            $robotsContent =
                'index,follow';
        }

        $ogImage =
            $videos->first()?->thumbnail
            ?: asset('images/og-default.jpg');
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
        content="{{ $activeCategory?->name ?? 'Project Ares' }} video thumbnail"
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
            background: #111;
            border-bottom: 1px solid #222;
        }

        .header-inner {
            max-width: 1500px;
            margin: 0 auto;
            padding: 16px 30px;

            display: flex;
            align-items: center;
            justify-content: space-between;

            gap: 24px;
        }

        .logo {
            font-size: 22px;
            font-weight: 800;
        }

        .logo span {
            color: #888;
        }

        .main-nav {
            display: flex;
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

        .page-heading {
            display: flex;
            justify-content: space-between;
            align-items: center;

            gap: 20px;

            margin-bottom: 20px;
        }

        .page-heading h1 {
            margin: 0;
            font-size: 28px;
        }

        .video-count {
            color: #777;
            font-size: 13px;
        }

        .toolbar {
            margin-bottom: 22px;

            padding: 14px;

            border: 1px solid #252525;
            border-radius: 9px;

            background: #151515;

            display: flex;
            align-items: center;

            gap: 10px;
        }

        .search-input {
            flex: 1;

            min-width: 0;

            height: 42px;

            padding: 0 13px;

            border: 1px solid #333;
            border-radius: 6px;

            background: #0e0e0e;
            color: #fff;

            outline: none;
        }

        .search-input:focus {
            border-color: #666;
        }

        .sort-select {
            height: 42px;

            padding: 0 35px 0 12px;

            border: 1px solid #333;
            border-radius: 6px;

            background: #0e0e0e;
            color: #fff;

            outline: none;
        }

        .search-button {
            height: 42px;

            padding: 0 18px;

            border: 0;
            border-radius: 6px;

            background: #fff;
            color: #111;

            font-weight: 700;
            cursor: pointer;
        }

        .clear-button {
            height: 42px;

            padding: 0 15px;

            border-radius: 6px;

            background: #282828;
            color: #ddd;

            display: inline-flex;
            align-items: center;
        }

        .category-nav {
            display: flex;
            flex-wrap: wrap;

            gap: 10px;

            margin-bottom: 28px;
        }

        .category-link {
            padding: 8px 14px;

            border-radius: 999px;

            background: #202020;
            color: #aaa;

            font-size: 13px;
        }

        .category-link:hover {
            background: #303030;
            color: #fff;
        }

        .category-link.active {
            background: #fff;
            color: #111;

            font-weight: 700;
        }

        .category-description {
            margin:
                -12px 0 28px;

            color: #888;

            font-size: 14px;
            line-height: 1.6;
        }

        .result-info {
            margin-bottom: 18px;

            color: #888;
            font-size: 13px;
        }

        .result-info strong {
            color: #ddd;
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
            opacity: 0.88;
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
            font-size: 14px;
        }

        .play-button {
            position: absolute;

            left: 50%;
            top: 50%;

            transform:
                translate(-50%, -50%);

            width: 56px;
            height: 56px;

            border-radius: 50%;

            background:
                rgba(0, 0, 0, 0.72);

            display: flex;
            align-items: center;
            justify-content: center;

            font-size: 22px;

            pointer-events: none;
        }

        .duration {
            position: absolute;

            right: 8px;
            bottom: 8px;

            padding: 4px 7px;

            border-radius: 4px;

            background:
                rgba(0, 0, 0, 0.82);

            font-size: 12px;
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

            border-radius: 4px;

            background:
                rgba(0, 0, 0, 0.82);

            font-size: 11px;
            font-weight: 700;
        }

        .title {
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

        .title:hover {
            color: #ccc;
        }

        .meta {
            margin-top: 7px;

            color: #777;
            font-size: 12px;
        }

        .pagination {
            margin-top: 40px;
        }

        .empty-state {
            padding: 35px;

            border: 1px solid #252525;
            border-radius: 9px;

            background: #151515;

            color: #888;

            grid-column: 1 / -1;
        }

        @media (max-width: 1100px) {

            .video-grid {
                grid-template-columns:
                    repeat(3, minmax(0, 1fr));
            }

        }

        @media (max-width: 750px) {

            .header-inner,
            .page {
                padding-left: 18px;
                padding-right: 18px;
            }

            .video-grid {
                grid-template-columns:
                    repeat(2, minmax(0, 1fr));
            }

            .toolbar {
                flex-wrap: wrap;
            }

            .search-input {
                flex-basis: 100%;
            }

            .sort-select {
                flex: 1;
            }

        }

        @media (max-width: 500px) {

            .main-nav {
                display: none;
            }

            .toolbar {
                align-items: stretch;
            }

            .sort-select,
            .search-button,
            .clear-button {
                width: 100%;
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

    <div class="page-heading">

        <h1>

            @if($activeCategory)

                {{ $activeCategory->name }}

            @else

                Videos

            @endif

        </h1>

        <div class="video-count">

            {{ number_format($videos->total()) }}
            videos

        </div>

    </div>

    <form
        class="toolbar"
        method="GET"
        action="{{ $activeCategory
            ? route('videos.category', $activeCategory->slug)
            : route('videos.index') }}"
    >

        <input
            class="search-input"
            type="search"
            name="q"
            value="{{ $search }}"
            placeholder="Search videos..."
            aria-label="Search videos"
        >

        <select
            class="sort-select"
            name="sort"
            aria-label="Sort videos"
        >

            <option
                value="latest"
                @selected($sort === 'latest')
            >
                Latest
            </option>

            <option
                value="views"
                @selected($sort === 'views')
            >
                Most Viewed
            </option>

            <option
                value="oldest"
                @selected($sort === 'oldest')
            >
                Oldest
            </option>

        </select>

        <button
            class="search-button"
            type="submit"
        >
            Search
        </button>

        @if($search !== '' || $sort !== 'latest')

            <a
                class="clear-button"
                href="{{ $activeCategory
                    ? route('videos.category', $activeCategory->slug)
                    : route('videos.index') }}"
            >
                Clear
            </a>

        @endif

    </form>

    <nav class="category-nav">

        <a
            class="category-link {{ $activeCategory === null ? 'active' : '' }}"
            href="{{ route(
                'videos.index',
                array_filter([
                    'q' => $search,
                    'sort' => $sort !== 'latest'
                        ? $sort
                        : null,
                ])
            ) }}"
        >
            All Videos
        </a>

        @foreach($categories as $category)

            <a
                class="category-link {{ $activeCategory?->id === $category->id ? 'active' : '' }}"
                href="{{ route(
                    'videos.category',
                    array_filter([
                        'slug' => $category->slug,
                        'q' => $search,
                        'sort' => $sort !== 'latest'
                            ? $sort
                            : null,
                    ])
                ) }}"
            >
                {{ $category->name }}
            </a>

        @endforeach

    </nav>

    @if($activeCategory && $activeCategory->description)

        <div class="category-description">

            {{ $activeCategory->description }}

        </div>

    @endif

    @if($search !== '')

        <div class="result-info">

            Search results for:

            <strong>
                "{{ $search }}"
            </strong>

        </div>

    @endif

    <div class="video-grid">

        @forelse($videos as $video)

            <article class="video-card">

                <a
                    href="{{ route('videos.show', $video->slug) }}"
                >

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
                    class="title"
                    href="{{ route('videos.show', $video->slug) }}"
                >
                    {{ $video->title }}
                </a>

                <div class="meta">

                    {{ number_format($video->views) }}
                    views

                    @if($video->video_source)

                        &middot;
                        {{ $video->video_source }}

                    @endif

                    @if($video->category)

                        &middot;
                        {{ $video->category->name }}

                    @endif

                </div>

            </article>

        @empty

            <div class="empty-state">

                No videos found.

                @if($search !== '')

                    Try a different search term.

                @endif

            </div>

        @endforelse

    </div>

    @if($videos->hasPages())

        <div class="pagination">

            {{ $videos->links() }}

        </div>

    @endif

</main>

</body>

</html>
