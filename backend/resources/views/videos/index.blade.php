<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        {{ $activeCategory ? $activeCategory->name . ' Videos' : 'Videos' }}
        | Project Ares
    </title>

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
            max-width: 1500px;
            margin: 0 auto;
            padding: 30px;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;

            margin-bottom: 20px;
        }

        .header h1 {
            margin: 0;

            font-size: 28px;
            font-weight: 700;
        }

        .video-count {
            color: #888;
            font-size: 13px;
        }

        .category-nav {
            display: flex;
            flex-wrap: wrap;

            gap: 10px;

            margin-bottom: 30px;
        }

        .category-link {
            display: inline-flex;

            align-items: center;

            padding: 8px 14px;

            border-radius: 999px;

            background: #222;

            color: #bbb;

            text-decoration: none;

            font-size: 13px;

            transition:
                background 0.2s ease,
                color 0.2s ease;
        }

        .category-link:hover {
            background: #333;
            color: #fff;
        }

        .category-link.active {
            background: #fff;
            color: #111;
            font-weight: 700;
        }

        .category-description {
            margin-top: -15px;
            margin-bottom: 28px;

            color: #999;

            font-size: 14px;
            line-height: 1.6;
        }

        .video-grid {
            display: grid;

            grid-template-columns:
                repeat(4, minmax(0, 1fr));

            gap: 24px;
        }

        .video-card {
            min-width: 0;
        }

        .video-link {
            display: block;

            color: inherit;
            text-decoration: none;
        }

        .thumbnail {
            position: relative;

            width: 100%;

            aspect-ratio: 16 / 9;

            background: #222;

            border-radius: 9px;

            overflow: hidden;
        }

        .thumbnail img {
            width: 100%;
            height: 100%;

            display: block;

            object-fit: cover;

            transition:
                transform 0.25s ease,
                opacity 0.25s ease;
        }

        .video-card:hover .thumbnail img {
            transform: scale(1.04);
            opacity: 0.88;
        }

        .thumbnail-placeholder {
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

            font-size: 14px;
        }

        .play-button {
            position: absolute;

            left: 50%;
            top: 50%;

            transform:
                translate(-50%, -50%);

            width: 56px;
            height: 56px;

            display: flex;

            align-items: center;
            justify-content: center;

            border-radius: 50%;

            background:
                rgba(0, 0, 0, 0.72);

            color: #fff;

            font-size: 22px;

            transition:
                transform 0.2s ease,
                background 0.2s ease;

            pointer-events: none;
        }

        .video-card:hover .play-button {
            transform:
                translate(-50%, -50%)
                scale(1.08);

            background:
                rgba(0, 0, 0, 0.85);
        }

        .duration {
            position: absolute;

            right: 8px;
            bottom: 8px;

            padding: 4px 7px;

            background:
                rgba(0, 0, 0, 0.82);

            border-radius: 4px;

            color: #fff;

            font-size: 12px;

            font-weight: 600;
        }

        .badges {
            position: absolute;

            left: 8px;
            bottom: 8px;

            display: flex;
            gap: 5px;
        }

        .badge {
            padding: 4px 6px;

            background:
                rgba(0, 0, 0, 0.82);

            border-radius: 4px;

            color: #fff;

            font-size: 11px;
            font-weight: 700;
        }

        .title {
            display: -webkit-box;

            margin-top: 10px;

            color: #fff;

            text-decoration: none;

            font-size: 15px;
            font-weight: 600;

            line-height: 1.4;

            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;

            overflow: hidden;
        }

        .title:hover {
            color: #ccc;
        }

        .meta {
            margin-top: 7px;

            color: #888;

            font-size: 12px;
        }

        .category-name {
            color: #aaa;
        }

        .pagination {
            margin-top: 40px;
        }

        .pagination nav {
            display: flex;
            justify-content: center;
        }

        .empty-state {
            color: #888;
            font-size: 14px;
        }

        @media (max-width: 1100px) {

            .video-grid {
                grid-template-columns:
                    repeat(3, minmax(0, 1fr));
            }

        }

        @media (max-width: 750px) {

            .video-grid {
                grid-template-columns:
                    repeat(2, minmax(0, 1fr));
            }

            .page {
                padding: 18px;
            }

            .header {
                align-items: flex-start;
                gap: 12px;
            }

            .header h1 {
                font-size: 24px;
            }

        }

        @media (max-width: 500px) {

            .video-grid {
                grid-template-columns: 1fr;
            }

        }

    </style>

</head>

<body>

<div class="page">

    <div class="header">

        <h1>

            @if($activeCategory)

                {{ $activeCategory->name }}

            @else

                Videos

            @endif

        </h1>


        <div class="video-count">

            {{ $videos->total() }}
            videos

        </div>

    </div>


    <nav class="category-nav">

        <a
            class="category-link {{ $activeCategory === null ? 'active' : '' }}"
            href="{{ route('videos.index') }}"
        >
            All Videos
        </a>


        @foreach($categories as $category)

            <a
                class="category-link {{ $activeCategory?->id === $category->id ? 'active' : '' }}"
                href="{{ route('videos.category', $category->slug) }}"
            >
                {{ $category->name }}
            </a>

        @endforeach

    </nav>


    @if($activeCategory && $activeCategory->description)

        <div class="category-description">

            {{ $activeCategory->description }}

        </div>

    @endif


    <div class="video-grid">

        @forelse($videos as $video)

            <article class="video-card">

                <a
                    class="video-link"
                    href="{{ route('videos.show', $video->slug) }}"
                >

                    <div class="thumbnail">

                        @if($video->thumbnail)

                            <img
                                src="{{ $video->thumbnail }}"
                                alt="{{ $video->title }}"
                                loading="lazy"
                            >

                        @else

                            <div class="thumbnail-placeholder">
                                No thumbnail
                            </div>

                        @endif


                        <div class="play-button">
                            &#9654;
                        </div>


                        @if($video->duration)

                            <div class="duration">

                                {{ gmdate('H:i:s', $video->duration) }}

                            </div>

                        @endif


                        <div class="badges">

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

                    </div>

                </a>


                <a
                    class="title"
                    href="{{ route('videos.show', $video->slug) }}"
                >
                    {{ $video->title }}
                </a>


                <div class="meta">

                    {{ number_format($video->views) }}
                    views

                    @if($video->video_source)

                        &middot;
                        {{ $video->video_source }}

                    @endif


                    @if($video->category)

                        &middot;

                        <span class="category-name">
                            {{ $video->category->name }}
                        </span>

                    @endif

                </div>

            </article>

        @empty

            <div class="empty-state">
                No videos found in this category.
            </div>

        @endforelse

    </div>


    @if($videos->hasPages())

        <div class="pagination">

            {{ $videos->links() }}

        </div>

    @endif

</div>

</body>

</html>
