@php
    $pageTitle =
        'Privacy | Xurvexa';

    $pageDescription =
        'Privacy information for Xurvexa.';

    $canonicalUrl =
        route('pages.privacy');

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
            Policy
        </div>

        <h1 class="content-page-title">
            Privacy
        </h1>

        <p class="content-page-intro">

            This page reserves the location for
            Xurvexa's production privacy policy.

        </p>

    </header>


    <section class="content-panel">

        <h2>
            Privacy policy preparation
        </h2>

        <p>

            The final privacy policy will describe
            what information Xurvexa processes,
            why it is processed, how long it is
            retained and what choices or rights
            may apply to users.

        </p>

        <p>

            The production version will also
            account for analytics, cookies,
            hosting infrastructure and other
            services that are actually enabled
            before launch.

        </p>

    </section>


    <div class="content-note">

        This is a development-stage placeholder
        and is not the final Xurvexa privacy
        policy. The final text will be completed
        before production launch.

    </div>

</main>

@endsection
