<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        Age Verification | Xurvexa
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

        .age-gate {
            width: 100%;

            max-width: 520px;

            padding:
                42px 38px;

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

        .age-badge {
            width: 78px;
            height: 78px;

            margin:
                0 auto 24px;

            display: flex;

            align-items: center;
            justify-content: center;

            border:
                2px solid #fff;

            border-radius: 50%;

            font-size: 26px;
            font-weight: 800;
        }

        h1 {
            margin:
                0 0 16px;

            font-size: 30px;

            line-height: 1.2;
        }

        .intro {
            margin:
                0 auto 26px;

            max-width: 410px;

            color: #aaa;

            font-size: 15px;

            line-height: 1.7;
        }

        .notice {
            margin-bottom: 28px;

            padding: 15px 17px;

            border:
                1px solid #2d2d2d;

            border-radius: 9px;

            background: #101010;

            color: #888;

            font-size: 12px;

            line-height: 1.6;
        }

        .actions {
            display: grid;

            gap: 12px;
        }

        form {
            margin: 0;
        }

        button {
            width: 100%;

            min-height: 50px;

            padding:
                13px 20px;

            border-radius: 8px;

            font-size: 14px;
            font-weight: 700;

            cursor: pointer;

            transition:
                background 0.2s ease,
                border-color 0.2s ease,
                color 0.2s ease;
        }

        .enter-button {
            border:
                1px solid #fff;

            background: #fff;
            color: #111;
        }

        .enter-button:hover {
            background: #ddd;

            border-color: #ddd;
        }

        .leave-button {
            border:
                1px solid #333;

            background: transparent;
            color: #aaa;
        }

        .leave-button:hover {
            border-color: #555;

            color: #fff;
        }

        .legal-links {
            margin-top: 28px;

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

            .age-gate {
                padding:
                    34px 22px;
            }

            h1 {
                font-size: 26px;
            }

            .age-badge {
                width: 70px;
                height: 70px;

                font-size: 23px;
            }

        }

    </style>

</head>

<body>

    <main
        class="age-gate"
        aria-labelledby="age-gate-title"
    >

        <div class="brand">
            XURVEXA<span>.</span>
        </div>

        <div
            class="age-badge"
            aria-hidden="true"
        >
            18+
        </div>

        <h1 id="age-gate-title">
            Age Verification
        </h1>

        <p class="intro">
            Xurvexa contains adult content intended
            only for persons who are 18 years of age
            or older.
        </p>

        <div class="notice">
            By entering, you confirm that you are
            at least 18 years old and legally permitted
            to view adult content in your jurisdiction.
        </div>

        <div class="actions">

            <form
                method="POST"
                action="{{ route('age-gate.accept') }}"
            >

                @csrf

                <button
                    class="enter-button"
                    type="submit"
                >
                    I am 18 or older — Enter
                </button>

            </form>

            <form
                method="POST"
                action="{{ route('age-gate.deny') }}"
            >

                @csrf

                <button
                    class="leave-button"
                    type="submit"
                >
                    I am under 18 — Leave
                </button>

            </form>

        </div>

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
