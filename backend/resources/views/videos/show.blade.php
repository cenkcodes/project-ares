<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>{{ $video->title }} | Project Ares</title>

    <style>

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 0;

            background: #111;
            color: #fff;

            font-family:
                Arial,
                Helvetica,
                sans-serif;
        }

        .page {
            max-width: 1250px;

            margin: 0 auto;

            padding: 30px;
        }

        .back-link {
            display: inline-block;

            margin-bottom: 20px;

            color: #aaa;

            text-decoration: none;

            font-size: 14px;
        }

        .back-link:hover {
            color: #fff;
        }

        .video-wrapper {
            position: relative;

            width: 100%;

            aspect-ratio: 16 / 9;

            background: #000;

            overflow: hidden;

            border-radius: 10px;
        }

        .video-wrapper iframe {
            width: 100%;
            height: 100%;

            display: block;

            border: 0;
        }

        .video-info {
            padding: 22px 0 0;
        }

        .title {
            margin: 0;

            color: #fff;

            font-size: 28px;

            line-height: 1.3;
        }

        .meta {
            display: flex;

            flex-wrap: wrap;

            align-items: center;

            gap: 10px;

            margin-top: 12px;

            color: #999;

            font-size: 13px;
        }

        .meta-item {
            display: inline-flex;

            align-items: center;

            gap: 5px;
        }

        .separator {
            color: #555;
        }

        .badge {
            display: inline-block;

            padding: 4px 7px;

            background: #292929;

            border-radius: 4px;

            color: #fff;

            font-size: 11px;

            font-weight: 700;
        }

        .description {
            margin-top: 22px;

            padding-top: 20px;

            border-top: 1px solid #292929;

            color: #ccc;

            font-size: 15px;

            line-height: 1.7;
        }

        .source {
            margin-top: 16px;

            color: #777;

            font-size: 12px;
        }

        @media (max-width: 700px) {

            .page {
                padding: 18px;
            }

            .title {
                font-size: 22px;
            }

            .video-wrapper {
                border-radius: 6px;
            }

        }

    </style>

</head>

<body>

<div class="page">

    <a
        class="back-link"
        href="{{ route('videos.index') }}"
    >
        &larr; Back to videos
    </a>


    <div class="video-wrapper">

        <iframe
            src="{{ $video->embed_url }}"
            title="{{ $video->title }}"
            loading="lazy"
            allow="autoplay; fullscreen; picture-in-picture"
            allowfullscreen>
        </iframe>

    </div>


    <div class="video-info">

        <h1 class="title">
            {{ $video->title }}
        </h1>


        <div class="meta">

            <span class="meta-item">

                {{ number_format($video->views) }}

                views

            </span>


            @if($video->video_source)

                <span class="separator">
                    &middot;
                </span>

                <span class="meta-item">

                    {{ $video->video_source }}

                </span>

            @endif


            @if($video->duration)

                <span class="separator">
                    &middot;
                </span>

                <span class="meta-item">

                    {{ gmdate('H:i:s', $video->duration) }}

                </span>

            @endif


            @if($video->is_4k)

                <span class="badge">
                    4K
                </span>

            @elseif($video->is_hd)

                <span class="badge">
                    HD
                </span>

            @endif

        </div>


        @if($video->description)

            <div class="description">

                {{ $video->description }}

            </div>

        @endif


        @if($video->video_source)

            <div class="source">

                Source:
                {{ $video->video_source }}

            </div>

        @endif

    </div>

</div>

</body>

</html>
