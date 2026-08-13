<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>{{ $pageTitle ?? 'Xurvexa' }}</title>

    <meta
        name="description"
        content="{{ $pageDescription ?? '' }}"
    >

    <meta
        name="robots"
        content="{{ $robotsContent ?? 'noindex,nofollow' }}"
    >

    <link
        rel="canonical"
        href="{{ $canonicalUrl ?? url()->current() }}"
    >

    <link
        rel="icon"
        type="image/svg+xml"
        href="{{ asset('favicon.svg') }}"
    >

    <meta
        name="theme-color"
        content="#111111"
    >

    <meta
        property="og:site_name"
        content="Xurvexa"
    >

    <meta
        property="og:type"
        content="{{ $ogType ?? 'website' }}"
    >

    <meta
        property="og:title"
        content="{{ $pageTitle ?? 'Xurvexa' }}"
    >

    <meta
        property="og:description"
        content="{{ $pageDescription ?? '' }}"
    >

    <meta
        property="og:url"
        content="{{ $canonicalUrl ?? url()->current() }}"
    >

    <meta
        property="og:image"
        content="{{ $ogImage ?? asset('images/og-default.jpg') }}"
    >

    <meta
        property="og:image:alt"
        content="{{ $ogImageAlt ?? 'Xurvexa' }}"
    >

    <meta
        name="twitter:card"
        content="summary_large_image"
    >

    <meta
        name="twitter:title"
        content="{{ $pageTitle ?? 'Xurvexa' }}"
    >

    <meta
        name="twitter:description"
        content="{{ $pageDescription ?? '' }}"
    >

    <meta
        name="twitter:image"
        content="{{ $ogImage ?? asset('images/og-default.jpg') }}"
    >

    <style>

        * {
            box-sizing: border-box;
        }

        html {
            min-height: 100%;
        }

        body {
            min-height: 100%;

            margin: 0;

            background: #0d0d0d;
            color: #fff;

            font-family:
                Arial,
                Helvetica,
                sans-serif;
        }

        a {
            color: inherit;
            text-decoration: none;
        }

        .site-header {
            background:
                rgba(13, 13, 13, 0.96);

            border-bottom:
                1px solid #222;
        }

        .header-inner {
            max-width: 1500px;

            margin: 0 auto;

            padding: 16px 30px;

            display: flex;
            align-items: center;

            gap: 24px;
        }

        .logo {
            flex-shrink: 0;

            font-size: 22px;
            font-weight: 800;

            letter-spacing: 1px;
        }

        .logo span {
            color: #888;
        }

        .header-search {
            flex: 1;

            max-width: 620px;

            display: flex;
        }

        .header-search input {
            width: 100%;

            height: 40px;

            padding: 0 13px;

            border:
                1px solid #333;

            border-radius:
                6px 0 0 6px;

            background: #151515;
            color: #fff;

            outline: none;
        }

        .header-search input:focus {
            border-color: #666;
        }

        .header-search button {
            height: 40px;

            padding: 0 17px;

            border: 0;

            border-radius:
                0 6px 6px 0;

            background: #fff;
            color: #111;

            font-weight: 700;

            cursor: pointer;
        }

        .main-nav {
            margin-left: auto;

            display: flex;
            align-items: center;

            gap: 18px;

            color: #aaa;

            font-size: 14px;
        }

        .main-nav a:hover {
            color: #fff;
        }

        .video-card {
            min-width: 0;
        }

        .video-thumbnail {
            position: relative;

            width: 100%;

            aspect-ratio: 16 / 9;

            background: #222;

            border-radius: 9px;

            overflow: hidden;
        }

        .video-thumbnail img {
            width: 100%;
            height: 100%;

            display: block;

            object-fit: cover;

            transition:
                transform 0.25s ease,
                opacity 0.25s ease;
        }

        .video-card:hover .video-thumbnail img {
            transform:
                scale(1.04);

            opacity: 0.9;
        }

        .video-thumbnail-placeholder {
            width: 100%;
            height: 100%;

            display: flex;

            align-items: center;
            justify-content: center;

            background:
                linear-gradient(
                    135deg,
                    #222,
                    #333
                );

            color: #777;

            font-size: 13px;
        }

        .video-play-button {
            position: absolute;

            left: 50%;
            top: 50%;

            transform:
                translate(-50%, -50%);

            width: 52px;
            height: 52px;

            border-radius: 50%;

            display: flex;

            align-items: center;
            justify-content: center;

            background:
                rgba(0, 0, 0, 0.72);

            font-size: 20px;

            pointer-events: none;
        }

        .video-duration {
            position: absolute;

            right: 8px;
            bottom: 8px;

            padding:
                4px 7px;

            border-radius: 4px;

            background:
                rgba(0, 0, 0, 0.82);

            font-size: 11px;
            font-weight: 600;
        }

        .video-badges {
            position: absolute;

            left: 8px;
            bottom: 8px;

            display: flex;

            gap: 5px;
        }

        .video-badge {
            padding:
                4px 6px;

            border-radius: 4px;

            background:
                rgba(0, 0, 0, 0.82);

            font-size: 10px;
            font-weight: 700;
        }

        .video-card-title {
            display: -webkit-box;

            margin-top: 10px;

            color: #fff;

            font-size: 15px;
            font-weight: 600;

            line-height: 1.4;

            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;

            overflow: hidden;
        }

        .video-card-title:hover {
            color: #ccc;
        }

        .video-card-meta {
            margin-top: 7px;

            color: #777;

            font-size: 12px;
        }

        .video-card--compact .video-thumbnail {
            border-radius: 8px;
        }

        .video-card--compact .video-play-button {
            width: 48px;
            height: 48px;

            font-size: 18px;
        }

        .video-card--compact .video-duration {
            right: 7px;
            bottom: 7px;

            padding:
                4px 6px;
        }

        .video-card--compact .video-badges {
            left: 7px;
            bottom: 7px;
        }

        .video-card--compact .video-card-title {
            margin-top: 9px;

            font-size: 14px;
        }

        .video-card--compact .video-card-meta {
            margin-top: 6px;

            font-size: 11px;
        }

        /*
         * Shared informational pages
         */

        .content-page {
            width: 100%;

            max-width: 1000px;

            margin: 0 auto;

            padding:
                48px 30px 70px;
        }

        .content-page-header {
            margin-bottom: 32px;
        }

        .content-page-eyebrow {
            margin-bottom: 10px;

            color: #777;

            font-size: 12px;
            font-weight: 700;

            letter-spacing: 0.12em;

            text-transform: uppercase;
        }

        .content-page-title {
            margin:
                0 0 14px;

            font-size: 38px;

            line-height: 1.2;
        }

        .content-page-intro {
            max-width: 760px;

            margin: 0;

            color: #999;

            font-size: 16px;

            line-height: 1.7;
        }

        .content-panel {
            margin-top: 24px;

            padding: 28px;

            border:
                1px solid #242424;

            border-radius: 12px;

            background: #151515;
        }

        .content-panel h2 {
            margin:
                0 0 12px;

            font-size: 20px;
        }

        .content-panel p {
            margin:
                0 0 16px;

            color: #bbb;

            font-size: 15px;

            line-height: 1.75;
        }

        .content-panel p:last-child {
            margin-bottom: 0;
        }

        .content-panel ul {
            margin:
                10px 0 0;

            padding-left: 22px;

            color: #bbb;
        }

        .content-panel li {
            margin-bottom: 9px;

            line-height: 1.6;
        }

        .content-note {
            margin-top: 24px;

            padding: 18px 20px;

            border:
                1px solid #303030;

            border-radius: 9px;

            background: #111;

            color: #888;

            font-size: 13px;

            line-height: 1.7;
        }

        /*
         * Shared footer
         */

        .site-footer {
            margin-top: 50px;

            border-top:
                1px solid #222;

            background: #0a0a0a;
        }

        .site-footer-inner {
            max-width: 1500px;

            margin: 0 auto;

            padding:
                30px 30px 26px;
        }

        .site-footer-top {
            display: flex;

            align-items: flex-start;
            justify-content: space-between;

            gap: 30px;
        }

        .site-footer-brand {
            max-width: 440px;
        }

        .site-footer-logo {
            margin-bottom: 10px;

            font-size: 18px;
            font-weight: 800;

            letter-spacing: 1px;
        }

        .site-footer-description {
            color: #777;

            font-size: 12px;

            line-height: 1.65;
        }

        .site-footer-links {
            display: flex;
            flex-wrap: wrap;

            justify-content: flex-end;

            gap:
                12px 20px;

            color: #999;

            font-size: 13px;
        }

        .site-footer-links a:hover {
            color: #fff;
        }

        .site-footer-bottom {
            margin-top: 24px;

            padding-top: 20px;

            border-top:
                1px solid #1d1d1d;

            display: flex;

            justify-content: space-between;
            align-items: center;

            gap: 20px;

            color: #666;

            font-size: 11px;

            line-height: 1.6;
        }

        .adult-notice {
            color: #888;

            font-weight: 600;
        }

        @media (max-width: 750px) {

            .header-inner {
                padding:
                    14px 18px;

                flex-wrap: wrap;
            }

            .header-search {
                order: 3;

                flex-basis: 100%;

                max-width: none;
            }

            .content-page {
                padding:
                    34px 18px 50px;
            }

            .content-page-title {
                font-size: 30px;
            }

            .content-panel {
                padding: 22px;
            }

            .site-footer-inner {
                padding:
                    26px 18px 22px;
            }

            .site-footer-top,
            .site-footer-bottom {
                flex-direction: column;

                align-items: flex-start;
            }

            .site-footer-links {
                justify-content: flex-start;
            }

        }

        @media (max-width: 500px) {

            .main-nav {
                display: none;
            }

        }

        @yield('pageStyles')

    </style>

</head>

<body>

    @include(
        'partials.site-header',
        [
            'showSearch' =>
                $showHeaderSearch ?? false,
        ]
    )

    @yield('content')

    @include('partials.site-footer')

</body>

</html>
