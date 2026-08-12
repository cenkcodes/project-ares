@php
    $showSource =
        $showSource ?? false;

    $compact =
        $compact ?? false;
@endphp

<article
    class="video-card {{ $compact ? 'video-card--compact' : '' }}"
>

    <a
        href="{{ route(
            'videos.show',
            $video->slug
        ) }}"
    >

        <div class="video-thumbnail">

            @if($video->thumbnail)

                <img
                    src="{{ $video->thumbnail }}"
                    alt="{{ $video->title }}"
                    loading="lazy"
                >

            @else

                <div class="video-thumbnail-placeholder">
                    No thumbnail
                </div>

            @endif

            <div class="video-play-button">
                &#9654;
            </div>

            @if($video->duration)

                <div class="video-duration">

                    {{ gmdate(
                        'H:i:s',
                        $video->duration
                    ) }}

                </div>

            @endif

            <div class="video-badges">

                @if($video->is_4k)

                    <span class="video-badge">
                        4K
                    </span>

                @elseif($video->is_hd)

                    <span class="video-badge">
                        HD
                    </span>

                @endif

            </div>

        </div>

    </a>

    <a
        class="video-card-title"
        href="{{ route(
            'videos.show',
            $video->slug
        ) }}"
    >
        {{ $video->title }}
    </a>

    <div class="video-card-meta">

        {{ number_format(
            $video->views
        ) }}
        views

        @if(
            $showSource &&
            $video->video_source
        )

            &middot;

            {{ $video->video_source }}

        @endif

        @if($video->category)

            &middot;

            {{ $video->category->name }}

        @endif

    </div>

</article>
