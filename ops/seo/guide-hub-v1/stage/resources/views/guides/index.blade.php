@php
    $pageTitle =
        $guideIndex['title']
        ?? 'Guides | Xurvexa';

    $pageDescription =
        $guideIndex['meta_description']
        ?? 'Browse Xurvexa category guides.';

    $canonicalUrl =
        route('guides.index');

    $robotsContent =
        app()->environment('production')
            ? 'index,follow'
            : 'noindex,nofollow';

    $ogType = 'website';
    $ogImage = asset('images/og-default.jpg');
    $ogImageAlt = 'Xurvexa Guides';
    $showHeaderSearch = true;
@endphp

@extends('layouts.public')

@section('pageStyles')
    .guide-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 18px;
        margin-top: 26px;
    }

    .guide-card {
        padding: 24px;
        border: 1px solid #242424;
        border-radius: 12px;
        background: #151515;
    }

    .guide-card h2 {
        margin: 0 0 12px;
        font-size: 21px;
        line-height: 1.35;
    }

    .guide-card h2 a:hover {
        color: #ccc;
    }

    .guide-card p {
        margin: 0 0 18px;
        color: #aaa;
        font-size: 14px;
        line-height: 1.7;
    }

    .guide-card-categories {
        display: flex;
        flex-wrap: wrap;
        gap: 7px;
        margin-bottom: 18px;
    }

    .guide-chip {
        padding: 6px 9px;
        border: 1px solid #303030;
        border-radius: 999px;
        color: #aaa;
        font-size: 11px;
        font-weight: 700;
    }

    .guide-read-link {
        color: #ddd;
        font-size: 13px;
        font-weight: 700;
    }

    .guide-read-link:hover {
        color: #fff;
    }

    @media (max-width: 760px) {
        .guide-grid {
            grid-template-columns: 1fr;
        }
    }
@endsection

@section('content')
<main class="content-page">
    <header class="content-page-header">
        <div class="content-page-eyebrow">
            Editorial Guides
        </div>

        <h1 class="content-page-title">
            {{ $guideIndex['h1'] ?? 'Xurvexa Category Guides' }}
        </h1>

        <p class="content-page-intro">
            {{ $guideIndex['intro'] ?? '' }}
        </p>
    </header>

    <div class="guide-grid">
        @foreach($guides as $slug => $guide)
            <article class="guide-card">
                <h2>
                    <a href="{{ route('guides.show', ['slug' => $slug]) }}">
                        {{ $guide['h1'] }}
                    </a>
                </h2>

                <p>{{ $guide['intro'] }}</p>

                <div
                    class="guide-card-categories"
                    aria-label="Related categories"
                >
                    @foreach($guide['category_links'] as $categoryLink)
                        <span class="guide-chip">
                            {{ $categoryLink['label'] }}
                        </span>
                    @endforeach
                </div>

                <a
                    class="guide-read-link"
                    href="{{ route('guides.show', ['slug' => $slug]) }}"
                >
                    Read guide →
                </a>
            </article>
        @endforeach
    </div>
</main>
@endsection
