<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        Access Restricted | Xurvexa
    </title>

    <meta
        name="robots"
        content="noindex,nofollow"
    >

    <meta
        name="theme-color"
        content="#0d0d0d"
    >

    <style>

        * {
            box-sizing: border-box;
        }

        html,
        body {
            min-height: 100%;
        }

        body {
            margin: 0;

            min-height: 100vh;

            display: flex;

            align-items: center;
            justify-content: center;

            padding: 24px;

            background:
                radial-gradient(
                    circle at top,
                    #1b1b1b 0,
                    #0d0d0d 45%,
                    #080808 100%
                );

            color: #fff;

            font-family:
                Arial,
                Helvetica,
                sans-serif;
        }

        .restricted-card {
            width: 100%;

            max-width: 500px;

            padding:
                42px 36px;

            border:
                1px solid #292929;

            border-radius: 16px;

            background:
                rgba(
                    18,
                    18,
                    18,
                    0.96
                );

            box-shadow:
                0 24px 70px
                rgba(
                    0,
                    0,
                    0,
                    0.45
                );

            text-align: center;
        }

        .brand {
            margin-bottom: 28px;

            font-size: 24px;
            font-weight: 800;

            letter-spacing: 1.4px;
        }

        .brand span {
            color: #888;
        }

        .restricted-badge {
            width: 78px;
            height: 78px;

            margin:
                0 auto 24px;

            display: flex;

            align-items: center;
            justify-content: center;

            border:
                2px solid #777;

            border-radius: 50%;

            color: #aaa;

            font-size: 25px;
            font-weight: 800;
        }

        h1 {
            margin:
                0 0 16px;

            font-size: 30px;

            line-height: 1.2;
        }

        .message {
            max-width: 390px;

            margin:
                0 auto;

            color: #aaa;

            font-size: 15px;

            line-height: 1.7;
        }

        .legal-links {
            margin-top: 30px;

            display: flex;

            flex-wrap: wrap;

            justify-content: center;

            gap:
                8px 16px;

            color: #777;

            font-size: 11px;
        }

        .legal-links a {
            color: inherit;

            text-decoration: none;
        }

        .legal-links a:hover {
            color: #bbb;
        }

        @media (max-width: 600px) {

            body {
                padding: 18px;
            }

            .restricted-card {
                padding:
                    34px 22px;
            }

            h1 {
                font-size: 26px;
            }

            .restricted-badge {
                width: 70px;
                height: 70px;

                font-size: 22px;
            }

        }

    </style>

</head>

<body>

    <main
        class="restricted-card"
        aria-labelledby="restricted-title"
    >

        <div class="brand">
            XURVEXA<span>.</span>
        </div>

        <div
            class="restricted-badge"
            aria-hidden="true"
        >
            18+
        </div>

        <h1 id="restricted-title">
            Access Restricted
        </h1>

        <p class="message">
            This website is intended only for adults
            who are 18 years of age or older.
            Access to adult content has not been granted.
        </p>

        <nav
            class="legal-links"
            aria-label="Legal information"
        >

            <a
                href="{{ route('pages.terms') }}"
            >
                Terms
            </a>

            <a
                href="{{ route('pages.privacy') }}"
            >
                Privacy
            </a>

            <a
                href="{{ route('pages.content-removal') }}"
            >
                Content Removal
            </a>

            <a
                href="{{ route('pages.contact') }}"
            >
                Contact
            </a>

        </nav>

    </main>

</body>

</html>
