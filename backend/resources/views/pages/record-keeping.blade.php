@php
    $pageTitle = '18 U.S.C. §2257 Record-Keeping Statement | Xurvexa';
    $pageDescription = 'Xurvexa record-keeping and adult-content compliance statement.';
    $canonicalUrl = route('pages.record-keeping');
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
        <div class="content-page-eyebrow">Adult Content Compliance</div>
        <h1 class="content-page-title">18 U.S.C. §2257 Record-Keeping Statement</h1>
        <p class="content-page-intro">Xurvexa record-keeping and adult-content compliance statement.</p>
    </header>

    <section class="content-panel">
        <h2>Adults only</h2>
        <p>Xurvexa permits only sexual material intended to depict persons who were at least 18 years old at the time the material was created. Material involving minors or persons presented as minors is strictly prohibited.</p>
    </section>
    <section class="content-panel">
        <h2>Third-party embedded material</h2>
        <p>Xurvexa does not currently produce, commission or host the third-party video files displayed through external embedded players. Listings may point to material supplied and controlled by independent external providers.</p>
        <p>For third-party works, records required under 18 U.S.C. §§2257 and 2257A, where those laws apply, are maintained by the applicable original producer or other records custodian associated with the originating material. Xurvexa does not represent that it is the custodian of those third-party records merely because an external player is embedded on Xurvexa.</p>
    </section>
    <section class="content-panel">
        <h2>Xurvexa-produced material</h2>
        <p>Xurvexa does not currently publish Xurvexa-produced explicit material. If that changes, Xurvexa will implement the applicable performer-verification and records-custodian process before publication and will update this statement.</p>
    </section>
    <section class="content-panel">
        <h2>Reporting a concern</h2>
        <p>If you believe a listing, thumbnail, title or embedded work may involve an underage person, an apparent minor, or may lack a lawful record-keeping basis, report the exact Xurvexa URL immediately through the <a href="{{ route('pages.contact', ['category' => 'illegal_content']) }}">Illegal / Prohibited Content</a> category.</p>
        <p>Do not download, copy, attach or redistribute suspected child sexual abuse material when making a report.</p>
    </section>

    <div class="content-note">Effective and last updated: 27 August 2026. This statement describes the current Xurvexa service model and does not claim a statutory exemption that has not been established.</div>
</main>
@endsection
