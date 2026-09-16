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

    $relatedCategorySlugs =
        $activeCategory
            ? config(
                'category-seo.related_categories.' .
                $activeCategory->slug,
                []
            )
            : [];

    $relatedCategories =
        collect($relatedCategorySlugs)
            ->map(
                fn ($slug) =>
                    $categories->firstWhere(
                        'slug',
                        $slug
                    )
            )
            ->filter()
            ->values();

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

        border:
            1px solid #252525;

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

        border:
            1px solid #333;

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

        padding:
            0 35px 0 12px;

        border:
            1px solid #333;

        border-radius: 6px;

        background: #0e0e0e;
        color: #fff;

        outline: none;
    }

    .search-button {
        height: 42px;

        padding:
            0 18px;

        border: 0;

        border-radius: 6px;

        background: #fff;
        color: #111;

        font-weight: 700;

        cursor: pointer;
    }

    .clear-button {
        height: 42px;

        padding:
            0 15px;

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
        padding:
            8px 14px;

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
            -12px 0 22px;

        color: #888;

        font-size: 14px;
        line-height: 1.6;
    }

    .related-categories {
        margin:
            0 0 28px;

        padding:
            16px 18px;

        border:
            1px solid #242424;

        border-radius: 9px;

        background: #121212;
    }

    .related-categories-title {
        margin:
            0 0 10px;

        color: #777;

        font-size: 12px;
        font-weight: 700;

        letter-spacing: 0.08em;
        text-transform: uppercase;
    }

    .related-categories-links {
        display: flex;
        flex-wrap: wrap;

        gap: 8px;
    }

    .related-category-link {
        padding:
            7px 11px;

        border:
            1px solid #2d2d2d;

        border-radius: 999px;

        background: #1b1b1b;
        color: #bbb;

        font-size: 12px;
        line-height: 1.2;
    }

    .related-category-link:hover {
        border-color: #555;

        background: #262626;
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

        gap: 24px;
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

        border-radius: 9px;

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

        .toolbar {
            align-items: stretch;
        }

        .sort-select,
        .search-button,
        .clear-button {
            width: 100%;
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
        $relatedGuides->isNotEmpty()
    )

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
