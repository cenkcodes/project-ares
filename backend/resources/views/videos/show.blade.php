<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>{{ $video->title }}</title>

    <style>
        body {
            margin: 0;
            padding: 40px;
            background: #111;
            color: #fff;
            font-family: Arial, sans-serif;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
        }

        h1 {
            margin-bottom: 20px;
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
            border: 0;
        }

        .description {
            margin-top: 20px;
            color: #ccc;
            line-height: 1.6;
        }

        .source {
            margin-top: 10px;
            color: #888;
        }
    </style>
</head>

<body>

<div class="container">

    <h1>{{ $video->title }}</h1>

    <div class="video-wrapper">

        <iframe
            src="{{ $video->embed_url }}"
            title="{{ $video->title }}"
            frameborder="0"
            allow="autoplay; fullscreen; picture-in-picture"
            allowfullscreen>
        </iframe>

    </div>

    @if($video->description)
        <div class="description">
            {{ $video->description }}
        </div>
    @endif

    <div class="source">
        Source: {{ $video->video_source }}
    </div>

</div>

</body>
</html>