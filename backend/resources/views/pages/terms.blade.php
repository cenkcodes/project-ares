@php
    $pageTitle =
        'Terms | Xurvexa';

    $pageDescription =
        'Terms information for Xurvexa.';

    $canonicalUrl =
        route('pages.terms');

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
            Terms
        </h1>

        <p class="content-page-intro">

            This page reserves the location for
            Xurvexa's production terms of use.

        </p>

    </header>


    <section class="content-panel">

        <h2>
            Production terms
        </h2>

        <p>

            The final terms will define the rules
            governing access to and use of
            Xurvexa.

        </p>

        <p>

            They will address matters such as
            eligibility, acceptable use, external
            content, intellectual property,
            prohibited activity and other
            conditions applicable to the
            production service.

        </p>

    </section>


    <section class="content-panel">

        <h2>
            Adult access
        </h2>

        <p>

            Xurvexa is intended for adults only.
            Users must meet the minimum legal age
            required to access adult-oriented
            material in their jurisdiction.

        </p>

    </section>


    <div class="content-note">

        This is a development-stage placeholder
        and is not the final Xurvexa terms of use.
        The final terms will be completed before
        production launch.

    </div>

</main>

@endsection
