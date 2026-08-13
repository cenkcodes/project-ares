@php
    $pageTitle =
        'Content Removal | Xurvexa';

    $pageDescription =
        'Information about content removal and rights-related requests on Xurvexa.';

    $canonicalUrl =
        route('pages.content-removal');

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
            Content & Rights
        </div>

        <h1 class="content-page-title">
            Content Removal
        </h1>

        <p class="content-page-intro">

            This page will provide the production
            process for reporting content and
            submitting rights-related or removal
            requests to Xurvexa.

        </p>

    </header>


    <section class="content-panel">

        <h2>
            Removal requests
        </h2>

        <p>

            Xurvexa uses external video embeds.
            A production reporting process will
            be provided for requests concerning
            content displayed through the
            platform.

        </p>

        <p>

            The final process will explain the
            information required to identify the
            relevant page or content and the
            basis for the request.

        </p>

    </section>


    <section class="content-panel">

        <h2>
            Prohibited content reports
        </h2>

        <p>

            Before launch, Xurvexa will publish
            a dedicated reporting channel and
            procedures for urgent reports,
            including content that may be
            unlawful or otherwise prohibited
            by the platform.

        </p>

    </section>


    <section class="content-panel">

        <h2>
            Rights and copyright
        </h2>

        <p>

            The production version of this page
            will include the appropriate process
            for copyright and other rights-based
            requests based on the jurisdiction
            and hosting structure used for
            Xurvexa.

        </p>

    </section>


    <div class="content-note">

        The reporting address and final legal
        procedure will be added before production
        launch. This page is currently development
        infrastructure only.

    </div>

</main>

@endsection
