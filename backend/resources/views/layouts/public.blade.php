<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>{{ $pageTitle ?? 'Project Ares' }}</title>

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

    <meta
        property="og:site_name"
        content="Project Ares"
    >

    <meta
        property="og:type"
        content="{{ $ogType ?? 'website' }}"
    >

    <meta
        property="og:title"
        content="{{ $pageTitle ?? 'Project Ares' }}"
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
        content="{{ $ogImageAlt ?? 'Project Ares' }}"
    >

    <meta
        name="twitter:card"
        content="summary_large_image"
    >

    <meta
        name="twitter:title"
        content="{{ $pageTitle ?? 'Project Ares' }}"
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

</body>

</html>
