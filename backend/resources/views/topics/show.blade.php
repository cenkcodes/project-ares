@php
    $pageTitle =
        $topic->title . ' | Xurvexa';

    $canonicalUrl =
        route('topics.show', $topic->slug);

    $publishedContent =
        $topicContent !== null && ! empty($topicContent->published_at)
            ? $topicContent
            : null;

    $metaDescription =
        $publishedContent->meta_description ?? null;

    $pageDescription =
        is_string($metaDescription) && trim($metaDescription) !== ''
            ? trim($metaDescription)
            : $topic->title . ' in ' .
                $topic->primaryCategory->name . ': ' .
                number_format($topic->support_video_count) .
                ' supporting videos.';

    if (! app()->environment('production')) {
        $robotsContent = 'noindex,nofollow';
    } elseif (
        $topicIndexExposureEnabled === true &&
        ! $requestHasQueryParameters
    ) {
        $robotsContent = 'index,follow';
    } else {
        $robotsContent = 'noindex,follow';
    }

    $ogType = 'website';
    $ogImage = asset('images/og-default.jpg');
    $ogImageAlt = $topic->title;
    $showHeaderSearch = false;

    $intro = $publishedContent->intro ?? null;
    $body = $publishedContent->body ?? null;

    // Query builder JSON fields arrive as strings; validate before rendering.
    $faqData = $publishedContent->faq ?? null;

    if (is_string($faqData)) {
        $faqData = json_decode($faqData, true);
    }

    $faqItems = [];

    if (is_array($faqData) || is_object($faqData)) {
        foreach ($faqData as $faqItem) {
            if (! is_array($faqItem) && ! is_object($faqItem)) {
                continue;
            }

            $faqItem = (array) $faqItem;
            $question = $faqItem['question'] ?? null;
            $answer = $faqItem['answer'] ?? null;

            if (
                is_string($question) &&
                is_string($answer) &&
                trim($question) !== '' &&
                trim($answer) !== ''
            ) {
                $faqItems[] = [
                    'question' => trim($question),
                    'answer' => trim($answer),
                ];
            }
        }
    }
@endphp

@extends('layouts.public')

@section('pageStyles')

    .page {
        max-width: 1500px;
        margin: 0 auto;
        padding: 30px;
    }

    .topic-heading {
        margin-bottom: 28px;
    }

    .topic-heading h1 {
        margin: 0 0 14px;
        font-size: 28px;
        overflow-wrap: anywhere;
    }

    .topic-meta,
    .topic-links {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 10px;
    }

    .topic-meta {
        margin-top: 18px;
    }

    .topic-link {
        padding: 8px 14px;
        border-radius: 999px;
        background: #202020;
        color: #aaa;
        font-size: 13px;
        overflow-wrap: anywhere;
    }

    .topic-link:hover,
    .topic-link:focus-visible {
        background: #303030;
        color: #fff;
    }

    .video-count {
        color: #888;
        font-size: 13px;
    }

    .topic-text {
        white-space: pre-line;
        overflow-wrap: anywhere;
    }

    .topic-faq-item + .topic-faq-item {
        margin-top: 24px;
    }

    .topic-faq-item h3 {
        margin: 0 0 10px;
        font-size: 16px;
        overflow-wrap: anywhere;
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
            grid-template-columns: repeat(3, minmax(0, 1fr));
        }
    }

    @media (max-width: 750px) {
        .page {
            padding-left: 18px;
            padding-right: 18px;
        }

        .video-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }

    @media (max-width: 500px) {
        .pagination-nav {
            gap: 5px;
        }

        .pagination-link,
        .pagination-current,
        .pagination-disabled,
        .pagination-ellipsis {
            min-width: 34px;
            height: 34px;
            padding: 0 9px;
        }

        .pagination-edge {
            min-width: 72px;
        }
    }

@endsection

@section('content')

<main class="page">

    <header class="topic-heading">
        <div class="content-page-eyebrow">Xurvexa Topic</div>

        <h1>{{ $topic->title }}</h1>

        @if(is_string($intro) && trim($intro) !== '')
            <p class="content-page-intro topic-text">{{ trim($intro) }}</p>
        @endif

        <div class="topic-meta">
            <a
                class="topic-link"
                href="{{ route('videos.category', $topic->primaryCategory->slug) }}"
            >
                {{ $topic->primaryCategory->name }}
            </a>

            <span class="video-count">
                {{ number_format($videos->total()) }} available videos
                &middot;
                {{ number_format($topic->support_video_count) }} supporting videos
            </span>
        </div>
    </header>

    <div class="video-grid">
        @forelse($videos as $video)
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
                No videos are currently available for this topic.
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
                request()->except($videos->getPageName());

            $videos->withPath($canonicalUrl);

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
                aria-label="Topic video pagination"
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


    @if(is_string($body) && trim($body) !== '')
        <section class="content-panel" aria-labelledby="topic-editorial-title">
            <h2 id="topic-editorial-title">About {{ $topic->title }}</h2>
            <p class="topic-text">{{ trim($body) }}</p>
        </section>
    @endif

    @if($faqItems !== [])
        <section class="content-panel" aria-labelledby="topic-faq-title">
            <h2 id="topic-faq-title">Frequently Asked Questions</h2>

            @foreach($faqItems as $faqItem)
                <div class="topic-faq-item">
                    <h3>{{ $faqItem['question'] }}</h3>
                    <p class="topic-text">{{ $faqItem['answer'] }}</p>
                </div>
            @endforeach
        </section>
    @endif

    @if($relatedTopics->isNotEmpty())
        <section class="content-panel" aria-labelledby="related-topics-title">
            <h2 id="related-topics-title">Related Topics</h2>

            <div class="topic-links">
                @foreach($relatedTopics as $relatedTopic)
                    <a
                        class="topic-link"
                        href="{{ route('topics.show', $relatedTopic->slug) }}"
                    >
                        {{ $relatedTopic->title }}
                    </a>
                @endforeach
            </div>
        </section>
    @endif

</main>

@endsection
