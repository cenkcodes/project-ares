@php
    $pageTitle = 'Cookie Policy | Xurvexa';
    $pageDescription = 'Information about cookies and similar technologies used by Xurvexa.';
    $canonicalUrl = route('pages.cookie-policy');
    $robotsContent = app()->environment('production') ? 'noindex,follow' : 'noindex,nofollow';
    $ogType = 'website';
    $ogImage = asset('images/og-default.jpg');
    $ogImageAlt = 'Xurvexa';
    $showHeaderSearch = true;
@endphp

@extends('layouts.public')

@section('content')
<main class="content-page">
    <header class="content-page-header">
        <div class="content-page-eyebrow">Privacy</div>
        <h1 class="content-page-title">Cookie Policy</h1>
        <p class="content-page-intro">Information about cookies and similar technologies used by Xurvexa.</p>
    </header>

    <section class="content-panel">
        <h2>1. Necessary technologies</h2>
        <p>Necessary technologies support security, CSRF protection, authentication, user sessions, age-gate state and privacy preferences. They are required to operate the service or remember choices requested by the user.</p>
    </section>
    <section class="content-panel">
        <h2>2. Analytics</h2>
        <p>Analytics technologies, when enabled, help Xurvexa understand service performance and aggregate usage. Analytics that require consent are not activated until the required consent has been given.</p>
    </section>
    <section class="content-panel">
        <h2>3. Advertising</h2>
        <p>Advertising partners may use cookies, browser or device identifiers, local storage, pixels or web beacons to deliver, measure, limit frequency or personalize advertising.</p>
        <p>Providers that may be activated from time to time include ExoClick, TrafficStars, JuicyAds and Clickadu. Where prior consent is required, Xurvexa will not intentionally activate optional advertising technologies until advertising consent has been granted.</p>
    </section>
    <section class="content-panel">
        <h2>4. External media</h2>
        <p>Video pages may contain players supplied by independent external providers. Loading an external player can create a direct connection to that provider and may allow the provider to use its own storage or tracking technologies. External providers control their own technologies and privacy practices.</p>
    </section>
    <section class="content-panel">
        <h2>5. Your choices</h2>
        <p>On first visit, Xurvexa provides controls to accept optional categories, reject optional categories or manage preferences. Necessary technologies remain enabled.</p>
        <p>You can change your choice at any time using Cookie Settings. Withdrawing consent does not affect processing that was lawful before withdrawal.</p>
    </section>
    <section class="content-panel">
        <h2>6. Browser controls and updates</h2>
        <p>Most browsers allow you to delete or block cookies. Blocking necessary storage can cause login, age-gate or preference features to stop working correctly.</p>
        <p>This Policy may be updated when providers, consent controls or technologies change.</p>
    </section>

    <div class="content-note">Effective and last updated: 27 August 2026.</div>
</main>
@endsection
