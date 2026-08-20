@php
    $pageTitle =
        'Contact | Xurvexa';

    $pageDescription =
        'Contact information for Xurvexa.';

    $canonicalUrl =
        route('pages.contact');

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
            Contact
        </h1>

        <p class="content-page-intro">

            Contact channels for general questions,
            platform matters and content-related
            requests will be published here before
            production launch.

        </p>

    </header>


    <section class="content-panel">

        <h2>
            General contact
        </h2>

        <p>

            A dedicated Xurvexa contact address
            will be added before the public launch
            of the platform.

        </p>

    </section>


    <section class="content-panel">

        <h2>
            Content-related requests
        </h2>

        <p>

            Requests concerning specific content,
            rights or removal should use the
            dedicated process described on the
            Content Removal page once the
            production contact channel is active.

        </p>

    </section>


    <div class="content-note">

        Contact details shown on this page are
        intentionally incomplete during local
        development and will be finalized before
        production deployment.

    </div>

</main>

@endsection
