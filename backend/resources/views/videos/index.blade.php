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

    $ogType =
        'website';

    $ogImage =
        $videos->first()?->thumbnail
        ?: asset('images/og-default.jpg');

    $ogImageAlt =
        ($activeCategory?->name ?? 'Project Ares') .
        ' video thumbnail';

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

    .pagination {
        margin-top: 40px;
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

        <div class="pagination">

            {{ $videos->links() }}

        </div>

    @endif

</main>

@endsection
