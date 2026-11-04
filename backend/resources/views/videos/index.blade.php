@php
    $pageNumber =
        $videos->currentPage();

    $hasSeoFilters =
        $search !== '' ||
        $sort !== 'latest';

    if ($search !== '') {
        $pageTitle =
            'Search: ' .
            $search .
            ' | Xurvexa';
    } elseif ($activeCategory) {
        $pageTitle =
            $activeCategory->name .
            ' Videos | Xurvexa';
    } else {
        $pageTitle =
            'Videos | Xurvexa';
    }

    if ($activeCategory) {
        $pageDescription =
            $activeCategory->meta_description
                ?: $activeCategory->description
                ?: 'Browse ' .
                    $activeCategory->name .
                    ' videos on Xurvexa.';
    } else {
        $pageDescription =
            'Browse videos on Xurvexa. Search, sort and explore available video categories.';
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

    $ogType =
        'website';

    $ogImage =
        $videos->first()?->thumbnail
        ?: asset('images/og-default.jpg');

    $ogImageAlt =
        ($activeCategory?->name ?? 'Xurvexa') .
        ' video thumbnail';

    $guideDefinitions =
        collect(
            config('guide-seo.guides', [])
        );

    $relatedGuideSlugs =
        $activeCategory
            ? config(
                'guide-seo.category_guides.' .
                $activeCategory->slug,
                []
            )
            : [];

    $relatedGuides =
        collect($relatedGuideSlugs)
            ->map(
                function ($slug) use ($guideDefinitions) {
                    $guide =
                        $guideDefinitions->get($slug);

                    if (
                        !is_array($guide) ||
                        ($guide['is_active'] ?? false) !== true
                    ) {
                        return null;
                    }

                    return array_merge(
                        $guide,
                        [
                            'slug' => (string) $slug,
                        ]
                    );
                }
            )
            ->filter()
            ->values();

    $showHeaderSearch = false;
@endphp

@extends('layouts.public')


@section('pageStyles')

    .page {
        max-width: 1500px;

        margin: 0 auto;

        padding:
            32px 30px 42px;
    }

    .page-heading {
        position: relative;

        overflow: hidden;

        display: flex;

        justify-content: space-between;
        align-items: center;

        gap: 24px;

        margin-bottom: 20px;

        padding:
            28px 30px;

        border:
            1px solid #262626;

        border-radius: 18px;

        background:
            linear-gradient(
                135deg,
                #1a1a1a 0%,
                #111 58%,
                #171717 100%
            );

        box-shadow:
            0 18px 50px
            rgba(0, 0, 0, 0.22);
    }

    .page-heading::after {
        content: '';

        position: absolute;

        width: 260px;
        height: 260px;

        right: -90px;
        top: -150px;

        border-radius: 50%;

        background:
            radial-gradient(
                circle,
                rgba(255, 255, 255, 0.08),
                rgba(255, 255, 255, 0) 70%
            );

        pointer-events: none;
    }

    .page-heading h1 {
        position: relative;
        z-index: 1;

        margin: 0;

        color: #fff;

        font-size:
            clamp(30px, 3vw, 44px);
        font-weight: 800;
        line-height: 1.05;

        letter-spacing: -0.03em;
    }

    .video-count {
        position: relative;
        z-index: 1;

        flex: 0 0 auto;

        padding:
            9px 12px;

        border:
            1px solid #323232;

        border-radius: 999px;

        background:
            rgba(255, 255, 255, 0.045);

        color: #b5b5b5;

        font-size: 12px;
        font-weight: 700;
        line-height: 1;

        letter-spacing: 0.02em;
    }

    .toolbar {
        margin-bottom: 18px;

        padding: 12px;

        border:
            1px solid #272727;

        border-radius: 14px;

        background:
            linear-gradient(
                180deg,
                #171717,
                #121212
            );

        display: flex;
        align-items: center;

        gap: 10px;

        box-shadow:
            0 10px 28px
            rgba(0, 0, 0, 0.14);
    }

    .search-input {
        flex: 1;

        min-width: 0;

        height: 42px;

        padding: 0 13px;

        border:
            1px solid #333;

        border-radius: 8px;

        background: #0e0e0e;
        color: #fff;

        outline: none;

        transition:
            border-color 0.18s ease,
            box-shadow 0.18s ease;
    }

    .search-input:focus {
        border-color: #666;

        box-shadow:
            0 0 0 3px
            rgba(255, 255, 255, 0.055);
    }

    .sort-select {
        height: 42px;

        padding:
            0 35px 0 12px;

        border:
            1px solid #333;

        border-radius: 8px;

        background: #0e0e0e;
        color: #fff;

        outline: none;
    }

    .search-button {
        height: 42px;

        padding:
            0 18px;

        border: 0;

        border-radius: 8px;

        background: #fff;
        color: #111;

        font-weight: 800;

        cursor: pointer;

        transition:
            transform 0.18s ease,
            opacity 0.18s ease;
    }

    .clear-button {
        height: 42px;

        padding:
            0 15px;

        border:
            1px solid #333;

        border-radius: 8px;

        background: #242424;
        color: #ddd;

        display: inline-flex;
        align-items: center;
        justify-content: center;
    }

    .category-nav {
        display: flex;
        flex-wrap: wrap;

        gap: 8px;

        margin-bottom: 18px;

        padding: 12px;

        border:
            1px solid #222;

        border-radius: 14px;

        background: #111;
    }

    .category-link {
        flex: 0 0 auto;

        padding:
            8px 13px;

        border:
            1px solid #282828;

        border-radius: 999px;

        background: #191919;
        color: #aaa;

        font-size: 13px;
        line-height: 1.2;

        transition:
            border-color 0.18s ease,
            background 0.18s ease,
            color 0.18s ease,
            transform 0.18s ease;
    }

    .category-link:hover {
        border-color: #444;

        background: #292929;
        color: #fff;
    }

    .category-link.active {
        border-color: #fff;

        background: #fff;
        color: #111;

        font-weight: 800;
    }

    .category-description {
        margin:
            0 0 18px;

        padding:
            13px 16px;

        border-left:
            3px solid #555;

        border-radius:
            0 10px 10px 0;

        background: #111;

        color: #9a9a9a;

        font-size: 14px;
        line-height: 1.7;
    }

    .related-categories {
        margin:
            0 0 14px;

        padding:
            17px 18px;

        border:
            1px solid #262626;

        border-radius: 14px;

        background:
            linear-gradient(
                135deg,
                #151515,
                #111
            );

        box-shadow:
            0 10px 28px
            rgba(0, 0, 0, 0.12);
    }

    .category-topics {
        border-color: #303030;

        background:
            linear-gradient(
                135deg,
                #181818,
                #111 70%
            );
    }

    .category-guides {
        margin-bottom: 28px;
    }

    .related-categories-title {
        margin:
            0 0 11px;

        color: #9a9a9a;

        font-size: 11px;
        font-weight: 800;

        letter-spacing: 0.1em;
        text-transform: uppercase;
    }

    .related-categories-links {
        display: flex;
        flex-wrap: wrap;

        gap: 7px;
    }

    .related-category-link {
        padding:
            7px 11px;

        border:
            1px solid #303030;

        border-radius: 999px;

        background: #1a1a1a;
        color: #c0c0c0;

        font-size: 12px;
        line-height: 1.2;

        transition:
            border-color 0.18s ease,
            background 0.18s ease,
            color 0.18s ease,
            transform 0.18s ease;
    }

    .related-category-link:hover {
        border-color: #555;

        background: #292929;
        color: #fff;
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

        gap:
            22px 18px;
    }

    .video-grid .video-card {
        min-width: 0;

        padding: 10px;

        border:
            1px solid #202020;

        border-radius: 14px;

        background:
            linear-gradient(
                180deg,
                #151515,
                #101010
            );

        box-shadow:
            0 10px 28px
            rgba(0, 0, 0, 0.13);

        transition:
            border-color 0.2s ease,
            box-shadow 0.2s ease,
            transform 0.2s ease;
    }

    .video-grid .video-thumbnail {
        border-radius: 10px;
    }

    .video-grid .video-card-title {
        margin-top: 10px;

        line-height: 1.4;
    }

    .video-grid .video-card-meta {
        line-height: 1.45;
    }

    @media (hover: hover) and (pointer: fine) {

        .search-button:hover,
        .category-link:hover,
        .related-category-link:hover {
            transform:
                translateY(-1px);
        }

        .video-grid .video-card:hover {
            border-color: #343434;

            box-shadow:
                0 18px 38px
                rgba(0, 0, 0, 0.2);

            transform:
                translateY(-3px);
        }

    }

    .pagination {
        margin-top: 40px;

        display: flex;
        justify-content: center;
    }

    .pagination-nav {
        display: flex;
        flex-wrap: wrap;

        justify-content: center;
        align-items: center;

        gap: 6px;
    }

    .pagination-link,
    .pagination-current,
    .pagination-disabled,
    .pagination-ellipsis {
        min-width: 38px;
        height: 38px;

        padding: 0 11px;

        border:
            1px solid #2e2e2e;

        border-radius: 7px;

        display: inline-flex;
        align-items: center;
        justify-content: center;

        background: #151515;
        color: #bbb;

        font-size: 13px;
        line-height: 1;

        text-decoration: none;
    }

    .pagination-link:hover {
        border-color: #555;

        background: #242424;
        color: #fff;
    }

    .pagination-current {
        border-color: #fff;

        background: #fff;
        color: #111;

        font-weight: 700;
    }

    .pagination-disabled,
    .pagination-ellipsis {
        color: #555;

        cursor: default;
    }

    .pagination-disabled {
        opacity: 0.65;
    }

    .pagination-edge {
        min-width: 86px;
    }

    .empty-state {
        padding: 35px;

        border:
            1px solid #252525;

        border-radius: 12px;

        background: #151515;

        color: #888;

        grid-column:
            1 / -1;
    }

    @media (max-width: 1100px) {

        .video-grid {
            grid-template-columns:
                repeat(3, minmax(0, 1fr));
        }

    }

    @media (max-width: 750px) {

        .page {
            padding:
                18px 16px 30px;
        }

        .page-heading {
            padding:
                21px 20px;

            border-radius: 15px;
        }

        .page-heading h1 {
            font-size:
                clamp(27px, 8vw, 36px);
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

        .category-nav {
            flex-wrap: nowrap;

            overflow-x: auto;
            overscroll-behavior-x: contain;

            margin:
                0 -16px 18px;

            padding:
                8px 16px 7px;

            border: 0;
            border-radius: 0;

            background: transparent;

            -webkit-overflow-scrolling: touch;
            scrollbar-width: none;
        }

        .category-nav::-webkit-scrollbar {
            display: none;
        }

        .category-link {
            flex: 0 0 auto;
        }

        .related-categories {
            padding:
                15px 16px;
        }

        .video-grid {
            grid-template-columns:
                repeat(2, minmax(0, 1fr));

            gap:
                14px 10px;
        }

        .video-grid .video-card {
            padding: 7px;

            border-radius: 11px;
        }

        .video-grid .video-thumbnail {
            border-radius: 8px;
        }

        .video-grid .video-card-title {
            margin-top: 8px;

            font-size: 13px;
            line-height: 1.35;
        }

        .video-grid .video-card-meta {
            margin-top: 5px;

            font-size: 10.5px;
            line-height: 1.4;

            display: -webkit-box;

            -webkit-box-orient: vertical;
            -webkit-line-clamp: 2;

            overflow: hidden;
        }

        .video-grid .video-play-button {
            width: 44px;
            height: 44px;

            font-size: 16px;
        }

        .video-grid .video-duration,
        .video-grid .video-badge {
            padding:
                3px 5px;

            font-size: 10px;
        }

        .video-grid .video-duration {
            right: 6px;
            bottom: 6px;
        }

        .video-grid .video-badges {
            left: 6px;
            bottom: 6px;
        }

    }

    @media (max-width: 500px) {

        .page {
            padding:
                14px 12px 26px;
        }

        .page-heading {
            flex-direction: column;
            align-items: flex-start;

            gap: 12px;

            margin-bottom: 14px;

            padding:
                18px 16px;

            border-radius: 14px;
        }

        .page-heading h1 {
            font-size:
                clamp(26px, 9vw, 32px);
        }

        .video-count {
            padding:
                8px 10px;
        }

        .toolbar {
            align-items: stretch;

            gap: 8px;

            margin-bottom: 14px;

            padding: 10px;
        }

        .search-input {
            height: 40px;
        }

        .sort-select,
        .search-button {
            flex:
                1 1 calc(50% - 4px);

            width: auto;
            min-width: 0;

            height: 40px;
        }

        .clear-button {
            width: 100%;
            height: 40px;
        }

        .category-nav {
            margin:
                0 -12px 14px;

            padding-left: 12px;
            padding-right: 12px;
        }

        .category-link {
            padding:
                7px 11px;

            font-size: 12px;
        }

        .category-description {
            margin-bottom: 14px;

            padding:
                11px 13px;

            font-size: 13px;
            line-height: 1.6;
        }

        .related-categories {
            margin-bottom: 10px;

            padding:
                13px 14px;

            border-radius: 12px;
        }

        .category-guides {
            margin-bottom: 20px;
        }

        .related-categories-title {
            margin-bottom: 9px;

            font-size: 10.5px;
        }

        .related-categories-links {
            gap: 6px;
        }

        .related-category-link {
            padding:
                6px 9px;

            font-size: 11.5px;
        }

        .video-grid {
            gap:
                12px 8px;
        }

        .video-grid .video-card {
            padding: 6px;

            border-radius: 10px;
        }

        .video-grid .video-card-title {
            margin-top: 7px;

            font-size: 12.5px;
        }

        .video-grid .video-card-meta {
            font-size: 10px;
        }

        .video-grid .video-play-button {
            width: 38px;
            height: 38px;

            font-size: 14px;
        }

        .pagination-nav {
            gap: 5px;
        }

        .pagination-link,
        .pagination-current,
        .pagination-disabled,
        .pagination-ellipsis {
            min-width: 34px;
            height: 34px;

            padding:
                0 9px;
        }

        .pagination-edge {
            min-width: 72px;
        }

    }

    @media (max-width: 360px) {

        .page {
            padding-left: 10px;
            padding-right: 10px;
        }

        .category-nav {
            margin-left: -10px;
            margin-right: -10px;

            padding-left: 10px;
            padding-right: 10px;
        }

        .video-grid {
            gap:
                10px 6px;
        }

        .video-grid .video-card {
            padding: 5px;
        }

        .video-grid .video-play-button {
            width: 34px;
            height: 34px;

            font-size: 13px;
        }

        .video-grid .video-card-meta {
            -webkit-line-clamp: 1;
        }

    }

@endsection


@section('content')

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

            {{ number_format(
                $videos->total()
            ) }}
            videos

        </div>

    </div>


    <form
        class="toolbar"
        method="GET"
        action="{{ $activeCategory
            ? route(
                'videos.category',
                $activeCategory->slug
            )
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
                @selected(
                    $sort === 'latest'
                )
            >
                Latest
            </option>

            <option
                value="views"
                @selected(
                    $sort === 'views'
                )
            >
                Most Viewed
            </option>

            <option
                value="oldest"
                @selected(
                    $sort === 'oldest'
                )
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

        @if(
            $search !== '' ||
            $sort !== 'latest'
        )

            <a
                class="clear-button"
                href="{{ $activeCategory
                    ? route(
                        'videos.category',
                        $activeCategory->slug
                    )
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
                    'q' =>
                        $search,
                    'sort' =>
                        $sort !== 'latest'
                            ? $sort
                            : null,
                ])
            ) }}"
        >
            All Videos
        </a>

        @foreach(
            $categories
            as $category
        )

            <a
                class="category-link {{ $activeCategory?->id === $category->id ? 'active' : '' }}"
                href="{{ route(
                    'videos.category',
                    array_filter([
                        'slug' =>
                            $category->slug,
                        'q' =>
                            $search,
                        'sort' =>
                            $sort !== 'latest'
                                ? $sort
                                : null,
                    ])
                ) }}"
            >
                {{ $category->name }}
            </a>

        @endforeach

    </nav>


    @if(
        $activeCategory &&
        $activeCategory->description
    )

        <div class="category-description">

            {{ $activeCategory->description }}

        </div>

    @endif


    @if(
        $activeCategory &&
        $relatedCategories->isNotEmpty()
    )

        <section
            class="related-categories"
            aria-labelledby="related-categories-title"
        >

            <h2
                id="related-categories-title"
                class="related-categories-title"
            >
                Related Categories
            </h2>

            <div class="related-categories-links">

                @foreach(
                    $relatedCategories
                    as $relatedCategory
                )

                    <a
                        class="related-category-link"
                        href="{{ route(
                            'videos.category',
                            $relatedCategory->slug
                        ) }}"
                    >
                        {{ $relatedCategory->name }} Videos
                    </a>

                @endforeach

            </div>

        </section>

    @endif


    @if(
        $activeCategory &&
        $relatedTopics->isNotEmpty()
    )

        <section
            class="related-categories category-topics"
            aria-labelledby="category-topics-title"
        >

            <h2
                id="category-topics-title"
                class="related-categories-title"
            >
                Explore Topics
            </h2>

            <div class="related-categories-links">

                @foreach($relatedTopics as $relatedTopic)

                    <a
                        class="related-category-link category-topic-link"
                        href="{{ route(
                            'topics.show',
                            ['slug' => $relatedTopic->slug]
                        ) }}"
                    >
                        {{ $relatedTopic->title }}
                    </a>

                @endforeach

            </div>

        </section>

    @endif


    @if($activeCategory)

        <section
            class="related-categories category-guides"
            aria-labelledby="category-guides-title"
        >

            <h2
                id="category-guides-title"
                class="related-categories-title"
            >
                Xurvexa Guides
            </h2>

            <div class="related-categories-links">

                @foreach($relatedGuides as $relatedGuide)

                    <a
                        class="related-category-link category-guide-link"
                        href="{{ route(
                            'guides.show',
                            ['slug' => $relatedGuide['slug']]
                        ) }}"
                    >
                        {{ $relatedGuide['h1'] }}
                    </a>

                @endforeach

                <a
                    class="related-category-link category-guides-all-link"
                    href="{{ route('guides.index') }}"
                >
                    Explore All Guides
                </a>

            </div>

        </section>

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

        @forelse(
            $videos
            as $video
        )

            @include(
                'partials.video-card',
                [
                    'video' => $video,
                    'showSource' => true,
                    'compact' => false,
                ]
            )

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

        @php
            $currentPage =
                $videos->currentPage();

            $lastPage =
                $videos->lastPage();

            $paginationQuery =
                request()->except('page');

            $pageCandidates = [
                1,
                2,
                $currentPage - 2,
                $currentPage - 1,
                $currentPage,
                $currentPage + 1,
                $currentPage + 2,
                $lastPage - 1,
                $lastPage,
            ];

            $paginationPages =
                collect($pageCandidates)
                    ->filter(
                        fn ($page) =>
                            $page >= 1 &&
                            $page <= $lastPage
                    )
                    ->unique()
                    ->sort()
                    ->values();
        @endphp

        <div class="pagination">

            <nav
                class="pagination-nav"
                aria-label="Video pagination"
            >

                @if($videos->onFirstPage())

                    <span
                        class="pagination-disabled pagination-edge"
                        aria-disabled="true"
                    >
                        Previous
                    </span>

                @else

                    <a
                        class="pagination-link pagination-edge"
                        href="{{ $videos
                            ->appends($paginationQuery)
                            ->previousPageUrl() }}"
                        rel="prev"
                    >
                        Previous
                    </a>

                @endif

                @php
                    $previousRenderedPage = null;
                @endphp

                @foreach(
                    $paginationPages
                    as $paginationPage
                )

                    @if(
                        $previousRenderedPage !== null &&
                        $paginationPage >
                            $previousRenderedPage + 1
                    )

                        <span
                            class="pagination-ellipsis"
                            aria-hidden="true"
                        >
                            &hellip;
                        </span>

                    @endif

                    @if(
                        $paginationPage ===
                        $currentPage
                    )

                        <span
                            class="pagination-current"
                            aria-current="page"
                        >
                            {{ $paginationPage }}
                        </span>

                    @else

                        <a
                            class="pagination-link"
                            href="{{ $videos
                                ->appends(
                                    $paginationQuery
                                )
                                ->url(
                                    $paginationPage
                                ) }}"
                        >
                            {{ $paginationPage }}
                        </a>

                    @endif

                    @php
                        $previousRenderedPage =
                            $paginationPage;
                    @endphp

                @endforeach

                @if($videos->hasMorePages())

                    <a
                        class="pagination-link pagination-edge"
                        href="{{ $videos
                            ->appends($paginationQuery)
                            ->nextPageUrl() }}"
                        rel="next"
                    >
                        Next
                    </a>

                @else

                    <span
                        class="pagination-disabled pagination-edge"
                        aria-disabled="true"
                    >
                        Next
                    </span>

                @endif

            </nav>

        </div>

    @endif

</main>

@endsection
