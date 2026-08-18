<?php

use App\Models\AdEvent;
use App\Models\MonetizationSetting;
use App\Models\Video;
use App\Models\VideoProvider;
use App\Services\Monetization\AdDecisionEngine;
use App\Services\Monetization\AdEventRecorder;
use App\Services\Monetization\AnonymousVisitorIdentity;
use App\Services\Monetization\MonetizationFrequencyState;
use App\Services\Monetization\MonetizationSessionState;
use App\Services\Monetization\TrackedAdDecisionService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    Cookie::flushQueuedCookies();

    app('session')->flush();
});

function createStateAwareTrackedSettings(
    bool $trackingEnabled = true
): MonetizationSetting {
    return MonetizationSetting::create([
        'settings_key' =>
            MonetizationSetting::GLOBAL_SETTINGS_KEY,

        'profile' =>
            MonetizationSetting::PROFILE_BALANCED,

        'master_enabled' => true,

        'mobile_ads_enabled' => true,
        'desktop_ads_enabled' => true,

        'native_ads_enabled' => true,
        'banner_ads_enabled' => true,

        'preroll_enabled' => true,
        'skip_preroll_when_provider_has_ads' => true,
        'preroll_on_first_video' => false,
        'preroll_skip_after_seconds' => 5,
        'preroll_max_per_session' => 2,
        'preroll_cooldown_minutes' => 30,

        'midroll_enabled' => false,

        'popunder_enabled' => true,
        'popunder_trigger_after_interactions' => 2,
        'popunder_frequency_minutes' => 1440,
        'popunder_max_per_session' => 1,
        'popunder_max_per_day' => 1,
        'popunder_mobile_enabled' => true,
        'popunder_desktop_enabled' => true,

        'interstitial_enabled' => false,
        'interstitial_trigger_after_interactions' => 3,
        'interstitial_frequency_minutes' => 1440,
        'interstitial_max_per_session' => 1,

        'session_interruption_budget' => 2,

        'autoplay_sound_ads_enabled' => false,

        'ad_event_tracking_enabled' =>
            $trackingEnabled,
    ]);
}

function createStateAwareTrackedProvider(
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

function createStateAwareTrackedVideo(
    string $provider = 'xvideos'
): Video {
    $uuid = (string) Str::uuid();

    return Video::create([
        'title' =>
            'State Aware Tracked Decision Test',

        'slug' =>
            'state-aware-tracked-' . $uuid,

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

function makeStateAwareTrackedService(
    ?Request $request = null
): TrackedAdDecisionService {
    $request ??= Request::create(
        '/',
        'GET'
    );

    return new TrackedAdDecisionService(
        decisionEngine:
            app(AdDecisionEngine::class),

        eventRecorder:
            app(AdEventRecorder::class),

        sessionState:
            app(MonetizationSessionState::class),

        frequencyState:
            app(MonetizationFrequencyState::class),

        visitorIdentity:
            app(AnonymousVisitorIdentity::class),

        request:
            $request
    );
}

test(
    'banner decision is tracked with generated session and opportunity uuids',
    function () {
        createStateAwareTrackedSettings();

        createStateAwareTrackedProvider(
            slug: 'test-provider',
            hasOwnAds: false
        );

        $video =
            createStateAwareTrackedVideo(
                'test-provider'
            );

        $service =
            makeStateAwareTrackedService();

        $decision = $service->decideBanner(
            video: $video,
            isMobile: false,
            placementKey: 'video_sidebar'
        );

        expect($decision['show'])
            ->toBeTrue()
            ->and($decision['reason'])
            ->toBe('eligible')
            ->and(
                Str::isUuid(
                    $decision[
                        'opportunity_uuid'
                    ]
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
            ->and(Str::isUuid($event->session_key))
            ->toBeTrue()
            ->and($event->device_type)
            ->toBe(AdEvent::DEVICE_DESKTOP)
            ->and($event->opportunity_uuid)
            ->toBe(
                $decision[
                    'opportunity_uuid'
                ]
            );
    }
);

test(
    'xvideos preroll is skipped because provider has own ads',
    function () {
        createStateAwareTrackedSettings();

        createStateAwareTrackedProvider(
            slug: 'xvideos',
            hasOwnAds: true
        );

        $video =
            createStateAwareTrackedVideo(
                'xvideos'
            );

        $service =
            makeStateAwareTrackedService();

        $service->recordMeaningfulInteraction(
            2
        );

        $decision = $service->decidePreroll(
            video: $video,
            isMobile: true,
            placementKey: 'video_player'
        );

        expect($decision['show'])
            ->toBeFalse()
            ->and($decision['reason'])
            ->toBe('provider_has_own_ads')
            ->and(
                Str::isUuid(
                    $decision[
                        'opportunity_uuid'
                    ]
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
            ->toBe(AdEvent::DEVICE_MOBILE);
    }
);

test(
    'popunder becomes eligible after required meaningful interactions',
    function () {
        createStateAwareTrackedSettings();

        createStateAwareTrackedProvider(
            slug: 'test-provider',
            hasOwnAds: false
        );

        $video =
            createStateAwareTrackedVideo(
                'test-provider'
            );

        $service =
            makeStateAwareTrackedService();

        $before = $service->decidePopunder(
            video: $video,
            isMobile: false,
            placementKey: 'video_player'
        );

        expect($before['show'])
            ->toBeFalse()
            ->and($before['reason'])
            ->toBe(
                'interaction_threshold_not_reached'
            );

        $service->recordMeaningfulInteraction(
            2
        );

        $after = $service->decidePopunder(
            video: $video,
            isMobile: false,
            placementKey: 'video_player'
        );

        expect($after['show'])
            ->toBeTrue()
            ->and($after['reason'])
            ->toBe('eligible');
    }
);

test(
    'real popunder impression consumes session state and blocks another popunder',
    function () {
        createStateAwareTrackedSettings();

        createStateAwareTrackedProvider(
            slug: 'test-provider',
            hasOwnAds: false
        );

        $video =
            createStateAwareTrackedVideo(
                'test-provider'
            );

        $service =
            makeStateAwareTrackedService();

        $service->recordMeaningfulInteraction(
            2
        );

        $decision = $service->decidePopunder(
            video: $video,
            isMobile: false,
            placementKey: 'video_player'
        );

        expect($decision['show'])
            ->toBeTrue();

        $occurredAt = CarbonImmutable::parse(
            '2026-08-18 10:00:00 UTC'
        );

        $impression =
            $service->recordImpression(
                format:
                    AdEvent::FORMAT_POPUNDER,

                video: $video,

                opportunityUuid:
                    $decision[
                        'opportunity_uuid'
                    ],

                isMobile: false,

                placementKey:
                    'video_player',

                interruptionCost: 1,

                occurredAt:
                    $occurredAt
            );

        expect($impression)
            ->toBeInstanceOf(
                AdEvent::class
            )
            ->and($impression->event_type)
            ->toBe(
                AdEvent::EVENT_IMPRESSION
            );

        $snapshot =
            $service->sessionSnapshot();

        expect(
            $snapshot[
                'session_popunder_count'
            ]
        )
            ->toBe(1)
            ->and(
                $snapshot[
                    'consumed_interruption_budget'
                ]
            )
            ->toBe(1)
            ->and(
                $snapshot[
                    'last_popunder_at'
                ]?->equalTo(
                    $occurredAt
                )
            )
            ->toBeTrue();

        $secondDecision =
            $service->decidePopunder(
                video: $video,
                isMobile: false,
                now: $occurredAt
                    ->addMinute(),
                placementKey:
                    'video_player'
            );

        expect(
            $secondDecision['show']
        )
            ->toBeFalse()
            ->and(
                $secondDecision['reason']
            )
            ->toBe(
                'session_popunder_limit_reached'
            );
    }
);

test(
    'popunder frequency survives a new session for the same anonymous visitor',
    function () {
        createStateAwareTrackedSettings();

        createStateAwareTrackedProvider(
            slug: 'test-provider',
            hasOwnAds: false
        );

        $video =
            createStateAwareTrackedVideo(
                'test-provider'
            );

        $visitorKey =
            (string) Str::uuid();

        $firstRequest = Request::create(
            '/',
            'GET',
            [],
            [
                AnonymousVisitorIdentity::COOKIE_NAME =>
                    $visitorKey,
            ]
        );

        $firstService =
            makeStateAwareTrackedService(
                $firstRequest
            );

        $firstService
            ->recordMeaningfulInteraction(
                2
            );

        $decision =
            $firstService
                ->decidePopunder(
                    video: $video,
                    isMobile: false,
                    placementKey:
                        'video_player'
                );

        expect($decision['show'])
            ->toBeTrue();

        $occurredAt =
            CarbonImmutable::parse(
                '2026-08-18 10:00:00 UTC'
            );

        $firstService->recordImpression(
            format:
                AdEvent::FORMAT_POPUNDER,

            video: $video,

            opportunityUuid:
                $decision[
                    'opportunity_uuid'
                ],

            isMobile: false,

            placementKey:
                'video_player',

            interruptionCost: 1,

            occurredAt:
                $occurredAt
        );

        app('session')->flush();

        $secondRequest = Request::create(
            '/',
            'GET',
            [],
            [
                AnonymousVisitorIdentity::COOKIE_NAME =>
                    $visitorKey,
            ]
        );

        $secondService =
            makeStateAwareTrackedService(
                $secondRequest
            );

        $secondService
            ->recordMeaningfulInteraction(
                2
            );

        $secondDecision =
            $secondService
                ->decidePopunder(
                    video: $video,
                    isMobile: false,
                    now: $occurredAt
                        ->addHour(),
                    placementKey:
                        'video_player'
                );

        expect(
            $secondDecision['show']
        )
            ->toBeFalse()
            ->and(
                $secondDecision['reason']
            )
            ->toBe(
                'daily_popunder_limit_reached'
            );
    }
);

test(
    'tracking can be disabled while session and frequency protection still works',
    function () {
        createStateAwareTrackedSettings(
            trackingEnabled: false
        );

        createStateAwareTrackedProvider(
            slug: 'test-provider',
            hasOwnAds: false
        );

        $video =
            createStateAwareTrackedVideo(
                'test-provider'
            );

        $service =
            makeStateAwareTrackedService();

        $service->recordMeaningfulInteraction(
            2
        );

        $decision =
            $service->decidePopunder(
                video: $video,
                isMobile: false,
                placementKey:
                    'video_player'
            );

        expect($decision['show'])
            ->toBeTrue()
            ->and(AdEvent::count())
            ->toBe(0);

        $impression =
            $service->recordImpression(
                format:
                    AdEvent::FORMAT_POPUNDER,

                video: $video,

                opportunityUuid:
                    $decision[
                        'opportunity_uuid'
                    ],

                isMobile: false,

                placementKey:
                    'video_player',

                interruptionCost: 1
            );

        expect($impression)
            ->toBeNull()
            ->and(AdEvent::count())
            ->toBe(0);

        $second =
            $service->decidePopunder(
                video: $video,
                isMobile: false,
                placementKey:
                    'video_player'
            );

        expect($second['show'])
            ->toBeFalse()
            ->and($second['reason'])
            ->toBe(
                'session_popunder_limit_reached'
            );
    }
);

test(
    'missing provider policy fails closed and is tracked',
    function () {
        createStateAwareTrackedSettings();

        $video =
            createStateAwareTrackedVideo(
                'unknown-provider'
            );

        $service =
            makeStateAwareTrackedService();

        $decision =
            $service->decidePreroll(
                video: $video,
                isMobile: false,
                placementKey:
                    'video_player'
            );

        expect($decision['show'])
            ->toBeFalse()
            ->and($decision['reason'])
            ->toBe(
                'missing_provider_policy'
            )
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
            ->toBe(
                'missing_provider_policy'
            )
            ->and($event->provider_slug)
            ->toBe(
                'unknown-provider'
            )
            ->and($event->metadata)
            ->toMatchArray([
                'video_source' =>
                    'unknown-provider',
            ]);
    }
);

test(
    'native decision can be tracked without a video',
    function () {
        createStateAwareTrackedSettings();

        $service =
            makeStateAwareTrackedService();

        $decision =
            $service->decideNative(
                video: null,
                isMobile: true,
                placementKey: 'home_grid'
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
            ->toBe(
                AdEvent::FORMAT_NATIVE
            )
            ->and($event->placement_key)
            ->toBe('home_grid')
            ->and($event->device_type)
            ->toBe(
                AdEvent::DEVICE_MOBILE
            )
            ->and(
                Str::isUuid(
                    $event->session_key
                )
            )
            ->toBeTrue();
    }
);

test(
    'click skip close and error events reuse the decision opportunity uuid',
    function () {
        createStateAwareTrackedSettings();

        createStateAwareTrackedProvider(
            slug: 'test-provider',
            hasOwnAds: false
        );

        $video =
            createStateAwareTrackedVideo(
                'test-provider'
            );

        $service =
            makeStateAwareTrackedService();

        $decision =
            $service->decideBanner(
                video: $video,
                isMobile: false,
                placementKey:
                    'video_sidebar'
            );

        $opportunityUuid =
            $decision[
                'opportunity_uuid'
            ];

        $click =
            $service->recordClick(
                format:
                    AdEvent::FORMAT_BANNER,

                video: $video,

                opportunityUuid:
                    $opportunityUuid,

                isMobile: false,

                placementKey:
                    'video_sidebar'
            );

        $skip =
            $service->recordSkip(
                format:
                    AdEvent::FORMAT_BANNER,

                video: $video,

                opportunityUuid:
                    $opportunityUuid,

                isMobile: false,

                placementKey:
                    'video_sidebar'
            );

        $close =
            $service->recordClose(
                format:
                    AdEvent::FORMAT_BANNER,

                video: $video,

                opportunityUuid:
                    $opportunityUuid,

                isMobile: false,

                placementKey:
                    'video_sidebar'
            );

        $error =
            $service->recordError(
                format:
                    AdEvent::FORMAT_BANNER,

                errorReason:
                    'test_network_error',

                video: $video,

                opportunityUuid:
                    $opportunityUuid,

                isMobile: false,

                placementKey:
                    'video_sidebar'
            );

        expect(
            $click?->opportunity_uuid
        )
            ->toBe($opportunityUuid)
            ->and(
                $skip?->opportunity_uuid
            )
            ->toBe($opportunityUuid)
            ->and(
                $close?->opportunity_uuid
            )
            ->toBe($opportunityUuid)
            ->and(
                $error?->opportunity_uuid
            )
            ->toBe($opportunityUuid)
            ->and(
                $error?->decision_reason
            )
            ->toBe(
                'test_network_error'
            );
    }
);
