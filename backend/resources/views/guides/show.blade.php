@php
    $pageTitle = $guide['title'];
    $pageDescription = $guide['meta_description'];
    $canonicalUrl = route(
        'guides.show',
        ['slug' => $guide['slug']]
    );

    $robotsContent =
        app()->environment('production')
            ? 'index,follow'
            : 'noindex,nofollow';

    $ogType = 'article';
    $ogImage = asset('images/og-default.jpg');
    $ogImageAlt = $guide['h1'];
    $showHeaderSearch = true;
@endphp

@extends('layouts.public')

@section('pageStyles')
    .guide-breadcrumb {
        margin-bottom: 24px;
        color: #777;
        font-size: 12px;
    }

    .guide-breadcrumb a {
        color: #aaa;
    }

    .guide-breadcrumb a:hover {
        color: #fff;
    }

    .guide-meta {
        margin-top: 14px;
        color: #666;
        font-size: 12px;
    }

    .guide-category-grid {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 12px;
        margin-top: 16px;
    }

    .guide-category-card {
        display: block;
        padding: 16px;
        border: 1px solid #303030;
        border-radius: 10px;
        background: #111;
    }

    .guide-category-card strong {
        display: block;
        margin-bottom: 6px;
        color: #eee;
        font-size: 14px;
    }

    .guide-category-card span {
        color: #888;
        font-size: 12px;
        line-height: 1.55;
    }

    .guide-category-card:hover {
        border-color: #555;
        background: #181818;
    }

    .guide-related-list {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        margin-top: 14px;
    }

    .guide-related-link,
    .guide-all-link {
        display: inline-flex;
        padding: 8px 12px;
        border: 1px solid #303030;
        border-radius: 999px;
        background: #111;
        color: #bbb;
        font-size: 12px;
        font-weight: 700;
    }

    .guide-related-link:hover,
    .guide-all-link:hover {
        border-color: #555;
        color: #fff;
    }

    @media (max-width: 760px) {
        .guide-category-grid {
            grid-template-columns: 1fr;
        }
    }
@endsection

@section('content')
<main class="content-page guide-article">
    <nav class="guide-breadcrumb" aria-label="Breadcrumb">
        <a href="{{ route('guides.index') }}">Guides</a>
        <span aria-hidden="true"> / </span>
        <span>{{ $guide['h1'] }}</span>
    </nav>

    <header class="content-page-header">
        <div class="content-page-eyebrow">
            Category Guide
        </div>

        <h1 class="content-page-title">
            {{ $guide['h1'] }}
        </h1>

        <p class="content-page-intro">
            {{ $guide['intro'] }}
        </p>

        <div class="guide-meta">
            Updated {{ $guide['updated_at'] }}
        </div>
    </header>

    @foreach($guide['sections'] as $section)
        <section class="content-panel guide-section">
            <h2>{{ $section['title'] }}</h2>

            @foreach($section['paragraphs'] as $paragraph)
                <p>{{ $paragraph }}</p>
            @endforeach
        </section>
    @endforeach

    <section class="content-panel">
        <h2>Browse the related categories</h2>
        <p>
            These canonical category pages are the main browsing destinations
            discussed in this guide.
        </p>

        <div class="guide-category-grid">
            @foreach($guide['category_links'] as $categoryLink)
                <a
                    class="guide-category-card guide-category-link"
                    href="{{ route(
                        'videos.category',
                        ['slug' => $categoryLink['slug']]
                    ) }}"
                >
                    <strong>{{ $categoryLink['label'] }}</strong>
                    <span>{{ $categoryLink['context'] }}</span>
                </a>
            @endforeach
        </div>
    </section>

    @if($relatedGuides->isNotEmpty())
        <section class="content-panel">
            <h2>Related guides</h2>

            <div class="guide-related-list">
                @foreach($relatedGuides as $relatedSlug => $relatedGuide)
                    <a
                        class="guide-related-link"
                        href="{{ route(
                            'guides.show',
                            ['slug' => $relatedSlug]
                        ) }}"
                    >
                        {{ $relatedGuide['h1'] }}
                    </a>
                @endforeach
            </div>
        </section>
    @endif

    <div class="content-note">
        Xurvexa uses canonical category pages for primary browsing while
        preserving source terms separately. A source tag can overlap with
        more than one theme without changing a video's primary category.
    </div>

    <div style="margin-top: 24px;">
        <a class="guide-all-link" href="{{ route('guides.index') }}">
            Explore all Xurvexa Guides
        </a>
    </div>
</main>
@endsection
