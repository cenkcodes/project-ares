<?php

use App\Models\AdEvent;
use App\Models\MonetizationSetting;
use App\Models\Video;
use App\Models\VideoProvider;
use App\Services\Monetization\TrackedAdDecisionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function createTrackedDecisionSettings(
    bool $trackingEnabled = true
): MonetizationSetting {
    return MonetizationSetting::create([
        'settings_key' =>
            MonetizationSetting::GLOBAL_SETTINGS_KEY,
        'profile' =>
            MonetizationSetting::PROFILE_BALANCED,
        'master_enabled' => true,
        'ad_event_tracking_enabled' =>
            $trackingEnabled,
    ]);
}

function createTrackedDecisionProvider(
    string $slug = 'xvideos',
    bool $hasOwnAds = true
): VideoProvider {
    return VideoProvider::create([
        'name' => ucfirst($slug),
        'slug' => $slug,
        'is_active' => true,
        'monetization_enabled' => true,
        'has_own_ads' => $hasOwnAds,
        'allow_xurvexa_preroll' =>
            ! $hasOwnAds,
        'allow_xurvexa_midroll' => false,
        'allow_popunder' => true,
        'allow_native_ads' => true,
        'allow_banner_ads' => true,
        'allow_interstitial' => false,
    ]);
}

function createTrackedDecisionVideo(
    string $provider = 'xvideos'
): Video {
    $uuid = (string) Str::uuid();

    return Video::create([
        'title' => 'Tracked Decision Test',
        'slug' =>
            'tracked-decision-' . $uuid,
        'embed_url' =>
            'https://example.com/embed/' . $uuid,
        'video_source' => $provider,
        'views' => 0,
        'is_hd' => false,
        'is_4k' => false,
        'is_featured' => false,
        'is_premium' => false,
        'is_active' => true,
    ]);
}

test(
    'banner decision is tracked with opportunity uuid',
    function () {
        createTrackedDecisionSettings();

        createTrackedDecisionProvider(
            slug: 'test-provider',
            hasOwnAds: false
        );

        $video = createTrackedDecisionVideo(
            'test-provider'
        );

        $service = app(
            TrackedAdDecisionService::class
        );

        $decision = $service->decideBanner(
            video: $video,
            isMobile: false,
            placementKey: 'video_sidebar',
            sessionKey: 'tracked-session-1'
        );

        expect($decision['show'])
            ->toBeTrue()
            ->and($decision['reason'])
            ->toBe('eligible')
            ->and(
                Str::isUuid(
                    $decision['opportunity_uuid']
                )
            )
            ->toBeTrue();

        $event = AdEvent::query()
            ->sole();

        expect($event->event_type)
            ->toBe(AdEvent::EVENT_DECISION)
            ->and($event->format)
            ->toBe(AdEvent::FORMAT_BANNER)
            ->and($event->decision_outcome)
            ->toBe(AdEvent::OUTCOME_SHOW)
            ->and($event->decision_reason)
            ->toBe('eligible')
            ->and($event->video_id)
            ->toBe($video->id)
            ->and($event->provider_slug)
            ->toBe('test-provider')
            ->and($event->placement_key)
            ->toBe('video_sidebar')
            ->and($event->session_key)
            ->toBe('tracked-session-1')
            ->and($event->device_type)
            ->toBe(AdEvent::DEVICE_DESKTOP)
            ->and($event->opportunity_uuid)
            ->toBe(
                $decision['opportunity_uuid']
            );
    }
);

test(
    'xvideos preroll is skipped because provider has own ads',
    function () {
        createTrackedDecisionSettings();

        createTrackedDecisionProvider(
            slug: 'xvideos',
            hasOwnAds: true
        );

        $video = createTrackedDecisionVideo(
            'xvideos'
        );

        $service = app(
            TrackedAdDecisionService::class
        );

        $decision = $service->decidePreroll(
            video: $video,
            isMobile: true,
            videoInteractionNumber: 2,
            sessionPrerollCount: 0,
            consumedInterruptionBudget: 0,
            placementKey: 'video_player',
            sessionKey: 'tracked-session-2'
        );

        expect($decision['show'])
            ->toBeFalse()
            ->and($decision['reason'])
            ->toBe('provider_has_own_ads')
            ->and(
                Str::isUuid(
                    $decision['opportunity_uuid']
                )
            )
            ->toBeTrue();

        $event = AdEvent::query()
            ->sole();

        expect($event->format)
            ->toBe(AdEvent::FORMAT_PREROLL)
            ->and($event->decision_outcome)
            ->toBe(AdEvent::OUTCOME_SKIP)
            ->and($event->decision_reason)
            ->toBe('provider_has_own_ads')
            ->and($event->provider_slug)
            ->toBe('xvideos')
            ->and($event->device_type)
            ->toBe(AdEvent::DEVICE_MOBILE)
            ->and($event->opportunity_uuid)
            ->toBe(
                $decision['opportunity_uuid']
            );
    }
);

test(
    'tracking can be disabled without disabling ad decisions',
    function () {
        createTrackedDecisionSettings(
            trackingEnabled: false
        );

        createTrackedDecisionProvider(
            slug: 'test-provider',
            hasOwnAds: false
        );

        $video = createTrackedDecisionVideo(
            'test-provider'
        );

        $service = app(
            TrackedAdDecisionService::class
        );

        $decision = $service->decideBanner(
            video: $video,
            isMobile: false,
            placementKey: 'video_sidebar',
            sessionKey: 'tracked-session-3'
        );

        expect($decision['show'])
            ->toBeTrue()
            ->and($decision['reason'])
            ->toBe('eligible')
            ->and(
                Str::isUuid(
                    $decision['opportunity_uuid']
                )
            )
            ->toBeTrue()
            ->and(AdEvent::count())
            ->toBe(0);
    }
);

test(
    'missing provider policy fails closed and is tracked',
    function () {
        createTrackedDecisionSettings();

        $video = createTrackedDecisionVideo(
            'unknown-provider'
        );

        $service = app(
            TrackedAdDecisionService::class
        );

        $decision = $service->decidePreroll(
            video: $video,
            isMobile: false,
            videoInteractionNumber: 2,
            sessionPrerollCount: 0,
            consumedInterruptionBudget: 0,
            placementKey: 'video_player',
            sessionKey: 'tracked-session-4'
        );

        expect($decision['show'])
            ->toBeFalse()
            ->and($decision['reason'])
            ->toBe('missing_provider_policy')
            ->and($decision['metadata'])
            ->toMatchArray([
                'video_source' =>
                    'unknown-provider',
            ]);

        $event = AdEvent::query()
            ->sole();

        expect($event->decision_outcome)
            ->toBe(AdEvent::OUTCOME_SKIP)
            ->and($event->decision_reason)
            ->toBe('missing_provider_policy')
            ->and($event->provider_slug)
            ->toBe('unknown-provider')
            ->and($event->metadata)
            ->toMatchArray([
                'video_source' =>
                    'unknown-provider',
            ])
            ->and($event->opportunity_uuid)
            ->toBe(
                $decision['opportunity_uuid']
            );
    }
);

test(
    'native decision can be tracked without a video',
    function () {
        createTrackedDecisionSettings();

        $service = app(
            TrackedAdDecisionService::class
        );

        $decision = $service->decideNative(
            video: null,
            isMobile: true,
            placementKey: 'home_grid',
            sessionKey: 'tracked-session-5'
        );

        expect($decision['show'])
            ->toBeTrue()
            ->and($decision['reason'])
            ->toBe('eligible');

        $event = AdEvent::query()
            ->sole();

        expect($event->video_id)
            ->toBeNull()
            ->and($event->provider_slug)
            ->toBeNull()
            ->and($event->format)
            ->toBe(AdEvent::FORMAT_NATIVE)
            ->and($event->placement_key)
            ->toBe('home_grid')
            ->and($event->device_type)
            ->toBe(AdEvent::DEVICE_MOBILE)
            ->and($event->opportunity_uuid)
            ->toBe(
                $decision['opportunity_uuid']
            );
    }
);
