@php
    $pageTitle = 'Privacy Policy | Xurvexa';
    $pageDescription = 'Privacy information for visitors and users of Xurvexa.';
    $canonicalUrl = route('pages.privacy');
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
        <h1 class="content-page-title">Privacy Policy</h1>
        <p class="content-page-intro">Privacy information for visitors and users of Xurvexa.</p>
    </header>

    <section class="content-panel">
        <h2>1. Information we process</h2>
        <p>Depending on how you use Xurvexa, we may process account information you provide, authentication and session information, age-gate and privacy-consent choices, technical request and security logs, pages requested, and information submitted through Contact or Content Removal.</p>
    </section>
    <section class="content-panel">
        <h2>2. Purposes and legal bases</h2>
        <p>Information may be processed to provide and secure the service, maintain sessions, prevent fraud and abuse, respond to support or legal requests, comply with law, improve reliability and, where enabled and permitted, measure or monetize the service.</p>
        <p>Where applicable law requires a legal basis, processing may rely on performance of a requested service, legitimate interests in operating and securing Xurvexa, compliance with legal obligations, or consent for activities that require consent.</p>
    </section>
    <section class="content-panel">
        <h2>3. Cookies, local storage and web beacons</h2>
        <p>Xurvexa uses essential technologies for security, sessions, age-gate state and privacy choices. Optional analytics or advertising technologies are controlled through privacy preferences where required.</p>
        <p>Third-party advertisers may place or read cookies, use local storage, device identifiers or web beacons as a result of ad serving when the relevant integration is enabled and the required consent has been obtained. See the <a href="{{ route('pages.cookie-policy') }}">Cookie Policy</a>.</p>
    </section>
    <section class="content-panel">
        <h2>4. Advertising partners</h2>
        <p>Adult-compatible advertising providers may include ExoClick, TrafficStars, JuicyAds and Clickadu when those integrations are approved and enabled. Only enabled integrations receive data associated with their service. Each provider may act as an independent controller, processor or joint controller depending on the activity.</p>
    </section>
    <section class="content-panel">
        <h2>5. External video providers</h2>
        <p>Xurvexa may display content using third-party embedded players. When an external player is loaded, the provider may receive technical information such as IP address, browser information and referring page and may apply its own technologies subject to its privacy practices.</p>
    </section>
    <section class="content-panel">
        <h2>6. Sharing and international processing</h2>
        <p>Information may be shared with service providers supporting hosting, security, email, infrastructure, advertising or legal compliance, and with authorities or rights holders when disclosure is required or permitted by law.</p>
        <p>Xurvexa and its providers may process information in more than one country. Where required, appropriate transfer mechanisms and safeguards are used.</p>
    </section>
    <section class="content-panel">
        <h2>7. Retention</h2>
        <p>Information is retained only for as long as reasonably necessary for its purpose, security, fraud prevention, dispute handling, legal compliance or enforcement. Consent records may be retained to demonstrate privacy choices and compliance.</p>
    </section>
    <section class="content-panel">
        <h2>8. Your choices and rights</h2>
        <p>Depending on jurisdiction, you may have rights to request access, correction, deletion, restriction, portability or objection, and to withdraw consent where processing is based on consent.</p>
        <p>Optional-cookie preferences can be changed using the Cookie Settings control. Other privacy requests can be submitted through the <a href="{{ route('pages.contact', ['category' => 'privacy']) }}">Contact</a> page.</p>
    </section>
    <section class="content-panel">
        <h2>9. Adults only and security</h2>
        <p>Xurvexa is not intended for children and does not knowingly offer adult-content access to persons under 18.</p>
        <p>Xurvexa uses technical and organizational safeguards designed to protect the service and information processed through it, but no online system can be guaranteed completely secure.</p>
    </section>
    <section class="content-panel">
        <h2>10. Changes and contact</h2>
        <p>This Policy may be updated when the service, legal requirements or providers change. Privacy questions and requests may be submitted through the <a href="{{ route('pages.contact', ['category' => 'privacy']) }}">Contact</a> page.</p>
    </section>

    <div class="content-note">Effective and last updated: 27 August 2026.</div>
</main>
@endsection
