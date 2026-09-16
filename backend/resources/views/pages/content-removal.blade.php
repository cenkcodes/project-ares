@php
    $pageTitle = 'Content Removal, Copyright & Illegal Content | Xurvexa';
    $pageDescription = 'Process for copyright, rights, DSA and prohibited-content reports on Xurvexa.';
    $canonicalUrl = route('pages.content-removal');
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
        <div class="content-page-eyebrow">
            Content & Rights
        </div>

        <h1 class="content-page-title">
            Content Removal
        </h1>

        <p class="content-page-intro">
            Process for copyright, rights, DSA and prohibited-content reports on Xurvexa.
        </p>
    </header>

    <section class="content-panel">

        <h2>
            How to submit a report
        </h2>

        <p>
            Use the
            <a href="{{ route('pages.contact') }}">
                Contact form
            </a>
            and choose the category that best describes the issue.
        </p>

        <ul>
            <li>
                Provide the exact Xurvexa page URL.
            </li>

            <li>
                Identify the specific content or material concerned.
            </li>

            <li>
                Explain the legal, rights or safety basis for the request.
            </li>

            <li>
                Provide a valid contact address so clarification can be requested.
            </li>

            <li>
                Do not attach illegal sexual material or suspected CSAM.
            </li>
        </ul>

    </section>

    <section
        class="content-panel"
        id="copyright-dmca"
    >

        <h2>
            Copyright / DMCA-style notices
        </h2>

        <p>
            Copyright and rights notices may be submitted through the
            <a
                href="{{ route('pages.contact', ['category' => 'copyright']) }}"
            >
                Copyright / Rights contact form
            </a>.
        </p>

        <p>
            A copyright notice should identify the copyrighted work,
            the exact Xurvexa URL complained of,
            the rights holder or authorized representative,
            and the basis for the claim.
            Include a good-faith statement that the disputed use is not authorized
            and a statement that the supplied information is accurate
            and that you are the rights holder or an authorized representative.
        </p>

        <p>
            Xurvexa may disable a listing or embed while a notice is reviewed.
            Removing a Xurvexa listing does not necessarily remove source material
            controlled by an external provider.
        </p>

    </section>

    <section
        class="content-panel"
        id="dsa-notices"
    >

        <h2>
            EU Digital Services Act notices
        </h2>

        <p>
            Notices alleging illegal content in the European Union may use the same
            Contact form.
            A notice should identify the precise location of the material,
            explain why it is alleged to be illegal,
            and include the notifier's contact details where required.
        </p>

        <p>
            Xurvexa will review sufficiently precise notices in good faith
            and may restrict access,
            preserve relevant records,
            notify an external provider,
            or take other action required by applicable law.
        </p>

    </section>

    <section
        class="content-panel"
        id="illegal-content"
    >

        <h2>
            Illegal, exploitative or prohibited content
        </h2>

        <p>
            Xurvexa prohibits child sexual abuse material,
            sexual content involving or presenting minors,
            trafficking,
            exploitation,
            non-consensual sexual material,
            bestiality,
            and other unlawful sexual content.
        </p>

        <p>
            If material may involve a minor or immediate exploitation,
            report the exact Xurvexa URL through the
            <a
                href="{{ route('pages.contact', ['category' => 'illegal_content']) }}"
            >
                Illegal / Prohibited Content
            </a>
            category.
            Do not download,
            save,
            copy,
            attach,
            or redistribute suspected CSAM.
            Where there is immediate danger,
            also contact the appropriate local authority.
        </p>

    </section>

    <section
        class="content-panel"
        id="privacy-consent-rights"
    >

        <h2>
            Privacy, image rights and consent
        </h2>

        <p>
            A person depicted in material,
            or an authorized representative,
            may request review where material allegedly violates privacy,
            publicity,
            image,
            consent,
            or similar rights.
            Provide the exact Xurvexa URL
            and enough information to identify the affected person
            and basis of the request
            without unnecessary sensitive information.
        </p>

    </section>

    <section
        class="content-panel"
        id="counter-notices"
    >

        <h2>
            Counter-notices and abuse of process
        </h2>

        <p>
            Where appropriate,
            Xurvexa may forward a notice to an affected party or provider
            and may consider a counter-notice or other evidence
            before restoring a listing.
            Xurvexa may keep a listing disabled
            where legal,
            safety,
            or provider-policy concerns remain.
        </p>

        <p>
            Do not knowingly submit false,
            misleading,
            or bad-faith legal notices.
            Xurvexa may disregard abusive,
            duplicative,
            or clearly unsupported requests.
        </p>

    </section>

    <div class="content-note">
        Effective and last updated: 28 August 2026.
        Safety and illegal-content concerns receive priority review.
    </div>

</main>
@endsection
