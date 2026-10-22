@php
    $pageTitle = $performer['title'];

    $pageDescription =
        $performer['meta_description'];

    $canonicalUrl =
        route(
            'performers.show',
            ['slug' => $performer['slug']]
        );

    $ogType = 'website';
    $ogImage = asset('images/og-default.jpg');
    $ogImageAlt = $performer['h1'];
    $showHeaderSearch = true;
@endphp

@extends('layouts.public')

@section('pageStyles')
    .performer-page {
        max-width: 1500px;
        margin: 0 auto;
        padding: 30px;
    }

    .performer-heading {
        margin-bottom: 28px;
    }

    .performer-heading h1 {
        margin: 0 0 14px;
        font-size: 28px;
        overflow-wrap: anywhere;
    }

    .performer-intro {
        max-width: 900px;
        margin: 0;
        color: #aaa;
        line-height: 1.65;
    }

    .performer-meta {
        margin-top: 18px;
        color: #777;
        font-size: 13px;
    }

    .performer-video-grid {
        display: grid;
        grid-template-columns:
            repeat(4, minmax(0, 1fr));
        gap: 24px;
    }

    .performer-empty-state {
        grid-column: 1 / -1;
        padding: 35px;
        border: 1px solid #252525;
        border-radius: 9px;
        background: #151515;
        color: #888;
    }

    @media (max-width: 1100px) {
        .performer-video-grid {
            grid-template-columns:
                repeat(3, minmax(0, 1fr));
        }
    }

    @media (max-width: 750px) {
        .performer-page {
            padding-left: 18px;
            padding-right: 18px;
        }

        .performer-video-grid {
            grid-template-columns:
                repeat(2, minmax(0, 1fr));
        }
    }
@endsection

@section('content')
<main class="performer-page">
    <header class="performer-heading">
        <div class="content-page-eyebrow">
            Xurvexa Performer
        </div>

        <h1>
            {{ $performer['h1'] }}
        </h1>

        <p class="performer-intro">
            {{ $performer['intro'] }}
        </p>

        <div class="performer-meta">
            {{ number_format($videos->count()) }}
            active videos
        </div>
    </header>

    <div class="performer-video-grid">
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
            <div class="performer-empty-state">
                No active videos are currently available
                for this performer.
            </div>
        @endforelse
    </div>
</main>
@endsection