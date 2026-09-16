@php
    $pageTitle = 'Terms of Use | Xurvexa';
    $pageDescription = 'Terms governing access to and use of Xurvexa.';
    $canonicalUrl = route('pages.terms');
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
        <div class="content-page-eyebrow">Legal</div>
        <h1 class="content-page-title">Terms of Use</h1>
        <p class="content-page-intro">Terms governing access to and use of Xurvexa.</p>
    </header>

    <section class="content-panel">
        <h2>1. Adult-only service</h2>
        <p>Xurvexa is intended only for adults. You must be at least 18 years old and must also meet any higher minimum age required by the law of the place from which you access the service.</p>
        <p>You must not access Xurvexa where adult content is unlawful. Age-gate acceptance does not override local law.</p>
    </section>
    <section class="content-panel">
        <h2>2. Nature of the service</h2>
        <p>Xurvexa is an adult-oriented discovery and viewing interface that may index, describe and display videos delivered through third-party embedded players. Xurvexa does not claim ownership of third-party videos merely because they are displayed on the service.</p>
        <p>Third-party material may be changed, restricted, removed or made unavailable by its originating provider at any time. Xurvexa may disable a listing or embed whenever legal, safety, rights or operational concerns arise.</p>
    </section>
    <section class="content-panel">
        <h2>3. Prohibited content and conduct</h2>
        <p>Xurvexa has zero tolerance for illegal or exploitative sexual content.</p>
        <ul>
            <li>Sexual content involving anyone under 18 or anyone presented as under 18 is prohibited.</li>
            <li>Child sexual abuse material, grooming, trafficking and sexual exploitation are prohibited.</li>
            <li>Non-consensual sexual material, rape, coercion and revenge pornography are prohibited.</li>
            <li>Bestiality, incest-themed material, extreme violence, torture, blood, scat and other unlawful or partner-prohibited material are prohibited.</li>
            <li>Malware, hacking, credential theft, deceptive redirects and security abuse are prohibited.</li>
            <li>Artificial impressions, clicks, bot traffic, incentivized ad interaction and other fraudulent traffic are prohibited.</li>
            <li>Use of Xurvexa to violate copyright, trademark, privacy, publicity or other rights is prohibited.</li>
        </ul>
    </section>
    <section class="content-panel">
        <h2>4. Accounts</h2>
        <p>Registered users are responsible for keeping their credentials secure and for activity performed through their accounts. Account information must be accurate and must not be used to impersonate another person.</p>
        <p>Xurvexa may restrict, suspend or close accounts used for unlawful activity, security abuse, rights violations or material breaches of these Terms.</p>
    </section>
    <section class="content-panel">
        <h2>5. Intellectual property and removal</h2>
        <p>Xurvexa respects intellectual-property and other legal rights. Rights holders and affected persons may submit a notice through the <a href="{{ route('pages.content-removal') }}">Content Removal</a> process.</p>
        <p>Because videos may originate from external providers, removing a Xurvexa listing does not necessarily remove the source material from the originating provider.</p>
    </section>
    <section class="content-panel">
        <h2>6. Advertising and third parties</h2>
        <p>Xurvexa may display advertising supplied by third-party advertising partners. Advertising providers may use cookies, local storage, device identifiers or web-beacon technologies where permitted by law and by the user's consent choices.</p>
        <p>External video players and advertising services are operated by independent third parties and may be subject to their own terms and privacy practices. See the <a href="{{ route('pages.privacy') }}">Privacy Policy</a> and <a href="{{ route('pages.cookie-policy') }}">Cookie Policy</a>.</p>
    </section>
    <section class="content-panel">
        <h2>7. Availability and liability</h2>
        <p>Xurvexa is provided on an "as available" basis. We do not guarantee uninterrupted availability or that every third-party embed will remain playable.</p>
        <p>To the maximum extent permitted by law, Xurvexa is not responsible for indirect, incidental, consequential or special losses arising from third-party content, third-party services, service interruptions or misuse. Nothing excludes liability that cannot lawfully be excluded.</p>
    </section>
    <section class="content-panel">
        <h2>8. Changes and contact</h2>
        <p>Xurvexa may update these Terms when the service, legal requirements or integrations change. Questions may be sent through the <a href="{{ route('pages.contact') }}">Contact</a> page.</p>
        <p>For adult-content record-keeping information, see the <a href="{{ route('pages.record-keeping') }}">18 U.S.C. §2257 Record-Keeping Statement</a>.</p>
    </section>

    <div class="content-note">Effective and last updated: 27 August 2026.</div>
</main>
@endsection
