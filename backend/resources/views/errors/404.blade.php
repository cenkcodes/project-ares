<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <meta
        name="robots"
        content="noindex,nofollow"
    >

    <title>Page Not Found</title>

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

            background: #0d0d0d;
            color: #fff;

            font-family:
                Arial,
                Helvetica,
                sans-serif;

            display: flex;
            align-items: center;
            justify-content: center;
        }

        a {
            color: inherit;
            text-decoration: none;
        }

        .error-page {
            width: 100%;

            max-width: 760px;

            padding: 40px 24px;

            text-align: center;
        }

        .error-code {
            margin: 0;

            color: #444;

            font-size: 110px;
            font-weight: 800;

            line-height: 1;
        }

        .error-title {
            margin:
                22px 0 0;

            font-size: 32px;
            font-weight: 700;
        }

        .error-text {
            max-width: 520px;

            margin:
                16px auto 0;

            color: #888;

            font-size: 15px;
            line-height: 1.7;
        }

        .actions {
            margin-top: 30px;

            display: flex;
            flex-wrap: wrap;

            justify-content: center;

            gap: 12px;
        }

        .button {
            min-width: 140px;

            padding:
                11px 18px;

            border-radius: 7px;

            background: #fff;
            color: #111;

            font-size: 13px;
            font-weight: 700;

            transition:
                opacity 0.2s ease,
                transform 0.2s ease;
        }

        .button:hover {
            opacity: 0.88;

            transform:
                translateY(-1px);
        }

        .button.secondary {
            background: #222;
            color: #fff;

            border:
                1px solid #333;
        }

        @media (max-width: 600px) {

            .error-code {
                font-size: 82px;
            }

            .error-title {
                font-size: 26px;
            }

            .actions {
                flex-direction: column;
            }

            .button {
                width: 100%;
            }

        }

    </style>

</head>

<body>

<main class="error-page">

    <div class="error-code">
        404
    </div>

    <h1 class="error-title">
        Page not found
    </h1>

    <p class="error-text">
        The page you are looking for may have been removed,
        renamed, or is no longer available.
    </p>

    <div class="actions">

        <a
            class="button"
            href="{{ route('home') }}"
        >
            Go Home
        </a>

        <a
            class="button secondary"
            href="{{ route('videos.index') }}"
        >
            Browse Videos
        </a>

    </div>

</main>

</body>

</html>
