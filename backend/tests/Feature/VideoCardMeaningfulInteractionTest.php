<?php

use App\Models\Video;
use Illuminate\Support\Str;

function makeMeaningfulInteractionVideo(): Video
{
    $uuid = (string) Str::uuid();

    return new Video([
        'title' =>
            'Meaningful Interaction Video',

        'slug' =>
            'meaningful-interaction-' . $uuid,

        'thumbnail' =>
            'https://example.com/thumbnail.jpg',

        'embed_url' =>
            'https://example.com/embed/' . $uuid,

        'video_source' =>
            'test-provider',

        'views' => 123,

        'duration' => 120,

        'is_hd' => true,
        'is_4k' => false,
        'is_featured' => false,
        'is_premium' => false,
        'is_active' => true,
    ]);
}

test(
    'video card marks both video navigation links as meaningful interactions',
    function () {
        $video =
            makeMeaningfulInteractionVideo();

        $html = view(
            'partials.video-card',
            [
                'video' => $video,
                'showSource' => false,
                'compact' => false,
            ]
        )->render();

        expect(
            substr_count(
                $html,
                'data-xurvexa-meaningful-interaction="video_navigation"'
            )
        )->toBe(2);
    }
);

test(
    'meaningful interaction markers remain attached to video links',
    function () {
        $video =
            makeMeaningfulInteractionVideo();

        $html = view(
            'partials.video-card',
            [
                'video' => $video,
                'showSource' => false,
                'compact' => false,
            ]
        )->render();

        $videoUrl =
            route(
                'videos.show',
                $video->slug
            );

        expect(
            substr_count(
                $html,
                'href="' . $videoUrl . '"'
            )
        )
            ->toBe(2)
            ->and(
                substr_count(
                    $html,
                    'data-xurvexa-meaningful-interaction="video_navigation"'
                )
            )
            ->toBe(2);
    }
);

test(
    'video card does not mark non navigation elements as meaningful interactions',
    function () {
        $video =
            makeMeaningfulInteractionVideo();

        $html = view(
            'partials.video-card',
            [
                'video' => $video,
                'showSource' => true,
                'compact' => true,
            ]
        )->render();

        expect(
            substr_count(
                $html,
                'data-xurvexa-meaningful-interaction'
            )
        )->toBe(2);
    }
);
