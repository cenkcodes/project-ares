@php
    $pageTitle =
        'About | Xurvexa';

    $pageDescription =
        'Learn more about Xurvexa and its video discovery experience.';

    $canonicalUrl =
        route('pages.about');

    $robotsContent =
        app()->environment('production')
            ? 'noindex,follow'
            : 'noindex,nofollow';

    $ogType =
        'website';

    $ogImage =
        asset('images/og-default.jpg');

    $ogImageAlt =
        'Xurvexa';

    $showHeaderSearch = true;
@endphp

@extends('layouts.public')


@section('content')

<main class="content-page">

    <header class="content-page-header">

        <div class="content-page-eyebrow">
            Xurvexa
        </div>

        <h1 class="content-page-title">
            About
        </h1>

        <p class="content-page-intro">

            Xurvexa is designed as a streamlined
            video discovery experience for browsing
            categories and finding embedded video
            content from external sources.

        </p>

    </header>


    <section class="content-panel">

        <h2>
            What Xurvexa does
        </h2>

        <p>

            Xurvexa organizes video listings,
            categories and related content so users
            can discover videos through a simple
            browsing experience.

        </p>

        <p>

            Video playback is provided through
            external embed sources rather than
            video files uploaded directly to
            Xurvexa.

        </p>

    </section>


    <section class="content-panel">

        <h2>
            Platform status
        </h2>

        <p>

            Xurvexa is a live video discovery
            platform that organizes and presents
            externally embedded adult video content
            from supported third-party providers.

        </p>

        <p>

            We continuously improve content
            discovery, categorization, platform
            performance, compliance features and
            monetization while keeping video
            playback provided by external embed
            sources.

        </p>

    </section>

</main>

@endsection
