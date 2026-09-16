@php
    $pageTitle = 'Contact | Xurvexa';
    $pageDescription = 'Contact Xurvexa for general, privacy, rights, safety or advertising matters.';
    $canonicalUrl = route('pages.contact');
    $robotsContent = app()->environment('production') ? 'noindex,follow' : 'noindex,nofollow';
    $ogType = 'website';
    $ogImage = asset('images/og-default.jpg');
    $ogImageAlt = 'Xurvexa';
    $showHeaderSearch = true;
@endphp

@extends('layouts.public')

@section('pageStyles')
.contact-form { display:grid; gap:18px; }
.contact-form-row { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:18px; }
.contact-form label { display:block; margin-bottom:7px; color:#ddd; font-size:13px; font-weight:700; }
.contact-form input,.contact-form select,.contact-form textarea { width:100%; border:1px solid #333; border-radius:7px; background:#0f0f0f; color:#fff; font:inherit; }
.contact-form input,.contact-form select { height:44px; padding:0 12px; }
.contact-form textarea { min-height:180px; padding:12px; resize:vertical; }
.contact-form input:focus,.contact-form select:focus,.contact-form textarea:focus { outline:none; border-color:#777; }
.contact-submit { width:fit-content; min-width:150px; min-height:44px; padding:0 20px; border:0; border-radius:7px; background:#fff; color:#111; font-weight:800; cursor:pointer; }
.contact-alert { margin-bottom:20px; padding:14px 16px; border-radius:8px; line-height:1.6; }
.contact-alert--success { border:1px solid #2f5f3a; background:#102617; color:#c8f3d1; }
.contact-alert--error { border:1px solid #6d3030; background:#2a1010; color:#ffd1d1; }
.contact-help { margin-top:7px; color:#777; font-size:12px; line-height:1.5; }
.contact-honeypot { position:absolute!important; left:-10000px!important; width:1px!important; height:1px!important; overflow:hidden!important; }
@media (max-width:700px) { .contact-form-row { grid-template-columns:1fr; } }
@endsection

@section('content')
<main class="content-page">
    <header class="content-page-header">
        <div class="content-page-eyebrow">Xurvexa</div>
        <h1 class="content-page-title">Contact</h1>
        <p class="content-page-intro">Use this form for general questions, rights notices, privacy requests, security matters, advertising issues and illegal-content reports.</p>
    </header>

    @if (session('status'))
        <div class="contact-alert contact-alert--success">{{ session('status') }}</div>
    @endif

    @if ($errors->any())
        <div class="contact-alert contact-alert--error">Please review the form and correct the highlighted information.</div>
    @endif

    <section class="content-panel">
        <h2>Send a message</h2>

        <form class="contact-form" method="POST" action="{{ route('pages.contact.submit') }}">
            @csrf

            <div class="contact-honeypot" aria-hidden="true">
                <label for="company_website">Company website</label>
                <input id="company_website" name="company_website" type="text" tabindex="-1" autocomplete="off">
            </div>

            <div>
                <label for="category">Category</label>
                <select id="category" name="category" required>
                    @foreach ($categories as $value => $label)
                        <option value="{{ $value }}" @selected(old('category', $selectedCategory) === $value)>{{ $label }}</option>
                    @endforeach
                </select>
                @error('category') <div class="contact-help">{{ $message }}</div> @enderror
            </div>

            <div class="contact-form-row">
                <div>
                    <label for="name">Name</label>
                    <input id="name" name="name" type="text" maxlength="120" value="{{ old('name') }}" required autocomplete="name">
                    @error('name') <div class="contact-help">{{ $message }}</div> @enderror
                </div>
                <div>
                    <label for="email">Email</label>
                    <input id="email" name="email" type="email" maxlength="254" value="{{ old('email') }}" required autocomplete="email">
                    @error('email') <div class="contact-help">{{ $message }}</div> @enderror
                </div>
            </div>

            <div>
                <label for="subject">Subject</label>
                <input id="subject" name="subject" type="text" maxlength="160" value="{{ old('subject') }}" required>
                @error('subject') <div class="contact-help">{{ $message }}</div> @enderror
            </div>

            <div>
                <label for="page_url">Xurvexa page URL (recommended for content reports)</label>
                <input id="page_url" name="page_url" type="url" maxlength="2048" value="{{ old('page_url') }}" placeholder="https://xurvexa.com/videos/...">
                @error('page_url') <div class="contact-help">{{ $message }}</div> @enderror
            </div>

            <div>
                <label for="message">Message</label>
                <textarea id="message" name="message" minlength="20" maxlength="10000" required>{{ old('message') }}</textarea>
                <div class="contact-help">For suspected CSAM or other illegal sexual material, provide the page URL and a description only. Do not attach, copy or redistribute the material.</div>
                @error('message') <div class="contact-help">{{ $message }}</div> @enderror
            </div>

            <button class="contact-submit" type="submit">Send</button>
        </form>
    </section>

    <section class="content-panel">
        <h2>Content and rights reports</h2>
        <p>Copyright, DSA, image-rights, consent and prohibited-content reports should follow the process described on the <a href="{{ route('pages.content-removal') }}">Content Removal</a> page.</p>
    </section>

    <div class="content-note">Messages sent through this form are delivered to the platform administrator through Xurvexa's production mail system.</div>
</main>
@endsection
