<?php

use App\Http\Middleware\RequireAdultConsent;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withCookie(
        RequireAdultConsent::COOKIE_NAME,
        RequireAdultConsent::COOKIE_VALUE
    );
});

function createVideoMonetizationFrontendVideo(): Video
{
    $uuid = (string) Str::uuid();

    return Video::create([
        'title' =>
            'Video Monetization Frontend Test',

        'slug' =>
            'video-monetization-frontend-' . $uuid,

        'embed_url' =>
            'https://example.com/embed/' . $uuid,

        'video_source' =>
            'test-provider',

        'views' => 0,

        'is_hd' => false,
        'is_4k' => false,
        'is_featured' => false,
        'is_premium' => false,
        'is_active' => true,
    ]);
}

test(
    'video detail page renders monetization configuration',
    function () {
        $video =
            createVideoMonetizationFrontendVideo();

        $response = $this->get(
            route(
                'videos.show',
                $video->slug
            )
        );

        $response
            ->assertOk()
            ->assertSee(
                'data-xurvexa-monetization',
                false
            )
            ->assertSee(
                'data-video-id="' .
                $video->id .
                '"',
                false
            )
            ->assertSee(
                'data-placement-key="video_player"',
                false
            );
    }
);

test(
    'video detail page exposes monetization runtime endpoints',
    function () {
        $video =
            createVideoMonetizationFrontendVideo();

        $response = $this->get(
            route(
                'videos.show',
                $video->slug
            )
        );

        $response
            ->assertOk()
            ->assertSee(
                'data-interaction-url="' .
                route(
                    'monetization.interaction'
                ) .
                '"',
                false
            )
            ->assertSee(
                'data-decision-url="' .
                route(
                    'monetization.decision'
                ) .
                '"',
                false
            )
            ->assertSee(
                'data-event-url="' .
                route(
                    'monetization.event'
                ) .
                '"',
                false
            );
    }
);

test(
    'video detail page renders a csrf token for monetization requests',
    function () {
        $video =
            createVideoMonetizationFrontendVideo();

        $response = $this->get(
            route(
                'videos.show',
                $video->slug
            )
        );

        $response->assertOk();

        $html =
            $response->getContent();

        expect($html)
            ->toMatch(
                '/data-csrf-token="[^"]+"/'
            );
    }
);

test(
    'video detail page loads video monetization vite entry',
    function () {
        $video =
            createVideoMonetizationFrontendVideo();

        $response = $this->get(
            route(
                'videos.show',
                $video->slug
            )
        );

        $response
            ->assertOk()
            ->assertSee(
                'video-monetization',
                false
            );
    }
);

test(
    'existing video iframe remains rendered with monetization client enabled',
    function () {
        $video =
            createVideoMonetizationFrontendVideo();

        $response = $this->get(
            route(
                'videos.show',
                $video->slug
            )
        );

        $response
            ->assertOk()
            ->assertSee(
                'src="' .
                $video->embed_url .
                '"',
                false
            )
            ->assertSee(
                'allow="autoplay; fullscreen; picture-in-picture"',
                false
            )
            ->assertSee(
                'allowfullscreen',
                false
            );
    }
);

test(
    'video detail page renders disabled banner ad slot',
    function () {
        $video =
            createVideoMonetizationFrontendVideo();

        $response = $this->get(
            route(
                'videos.show',
                $video->slug
            )
        );

        $response
            ->assertOk()
            ->assertSee(
                'data-xurvexa-ad-slot',
                false
            )
            ->assertSee(
                'data-enabled="false"',
                false
            )
            ->assertSee(
                'data-format="banner"',
                false
            )
            ->assertSee(
                'data-placement-key="video_banner"',
                false
            )
            ->assertSee(
                'data-ad-state="idle"',
                false
            )
            ->assertSee(
                'aria-hidden="true"',
                false
            );
    }
);

test(
    'video detail page loads video ad adapter vite entry',
    function () {
        $video =
            createVideoMonetizationFrontendVideo();

        $response = $this->get(
            route(
                'videos.show',
                $video->slug
            )
        );

        $response
            ->assertOk()
            ->assertSee(
                'video-adapter',
                false
            );
    }
);

test(
    'video detail page does not enable automatic ad prefetch yet',
    function () {
        $video =
            createVideoMonetizationFrontendVideo();

        $response = $this->get(
            route(
                'videos.show',
                $video->slug
            )
        );

        $response
            ->assertOk()
            ->assertDontSee(
                'data-prefetch-formats=',
                false
            )
            ->assertSee(
                'data-enabled="false"',
                false
            );
    }
);
