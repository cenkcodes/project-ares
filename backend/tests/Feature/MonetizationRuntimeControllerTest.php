<?php

use App\Http\Middleware\RequireAdultConsent;
use App\Models\AdEvent;
use App\Models\AdNetwork;
use App\Models\AdPlacement;
use App\Models\MonetizationSetting;
use App\Models\Video;
use App\Models\VideoProvider;
use App\Services\Monetization\AnonymousVisitorIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    Cookie::flushQueuedCookies();

    app('session')->flush();

    createRuntimeControllerAdDeliveryFixtures();

    $this
        ->withCredentials()
        ->withCookie(
            RequireAdultConsent::COOKIE_NAME,
            RequireAdultConsent::COOKIE_VALUE
        );
});

function createRuntimeControllerAdDeliveryFixtures(): AdNetwork
{
    $network = AdNetwork::create([
        'name' =>
            'Runtime Test Network',

        'slug' =>
            'runtime-test-network',

        'driver' =>
            'runtime-test-driver',

        'is_active' => true,
        'priority' => 10,

        'supports_native' => false,
        'supports_banner' => true,
        'supports_preroll' => false,
        'supports_midroll' => false,
        'supports_popunder' => true,
        'supports_interstitial' => false,
    ]);

    AdPlacement::create([
        'ad_network_id' =>
            $network->id,

        'placement_key' =>
            'video_sidebar',

        'format' =>
            AdNetwork::FORMAT_BANNER,

        'is_active' => true,
        'priority' => 10,
        'desktop_enabled' => true,
        'mobile_enabled' => true,

        'public_placement_id' =>
            'runtime-banner-zone',

        'public_config' => [
            'width' => 728,
            'height' => 90,
        ],
    ]);

    AdPlacement::create([
        'ad_network_id' =>
            $network->id,

        'placement_key' =>
            'video_player',

        'format' =>
            AdNetwork::FORMAT_POPUNDER,

        'is_active' => true,
        'priority' => 10,
        'desktop_enabled' => true,
        'mobile_enabled' => true,

        'public_placement_id' =>
            'runtime-popunder-zone',

        'public_config' => [],
    ]);

    return $network;
}

function createRuntimeControllerSettings(
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

function createRuntimeControllerProvider(
    string $slug = 'runtime-provider',
    bool $hasOwnAds = false
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

function createRuntimeControllerVideo(
    string $provider = 'runtime-provider'
): Video {
    $uuid = (string) Str::uuid();

    return Video::create([
        'title' =>
            'Monetization Runtime Controller Test',

        'slug' =>
            'runtime-controller-' . $uuid,

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
    'monetization runtime routes use web middleware and post method',
    function () {
        $routeNames = [
            'monetization.interaction',
            'monetization.decision',
            'monetization.event',
        ];

        foreach ($routeNames as $routeName) {
            $route = Route::getRoutes()
                ->getByName($routeName);

            expect($route)
                ->not->toBeNull()
                ->and($route->methods())
                ->toContain('POST')
                ->and($route->gatherMiddleware())
                ->toContain('web')
                ->toContain(
                    RequireAdultConsent::class
                );
        }
    }
);

test(
    'interaction endpoint increments server session by exactly one',
    function () {
        $response = $this
            ->withSession([
                'monetization.meaningful_interactions' =>
                    7,
            ])
            ->postJson(
                route(
                    'monetization.interaction'
                ),
                [
                    'amount' => 1000,
                ]
            );

        $response
            ->assertOk()
            ->assertJson([
                'ok' => true,
                'meaningful_interaction_count' => 8,
            ])
            ->assertSessionHas(
                'monetization.meaningful_interactions',
                8
            );
    }
);

test(
    'banner decision returns tracked opportunity uuid',
    function () {
        createRuntimeControllerSettings();

        createRuntimeControllerProvider();

        $video =
            createRuntimeControllerVideo();

        $response = $this->postJson(
            route(
                'monetization.decision'
            ),
            [
                'format' =>
                    AdEvent::FORMAT_BANNER,

                'video_id' =>
                    $video->id,

                'is_mobile' =>
                    false,

                'placement_key' =>
                    'video_sidebar',
            ]
        );

        $response
            ->assertOk()
            ->assertJsonPath(
                'ok',
                true
            )
            ->assertJsonPath(
                'decision.show',
                true
            )
            ->assertJsonPath(
                'decision.reason',
                'eligible'
            )
            ->assertJsonPath(
                'decision.delivery.ad_network',
                'runtime-test-network'
            )
            ->assertJsonPath(
                'decision.delivery.ad_driver',
                'runtime-test-driver'
            )
            ->assertJsonPath(
                'decision.delivery.public_placement_id',
                'runtime-banner-zone'
            )
            ->assertSessionHas(
                'monetization.opportunities'
            );

        $opportunityUuid =
            $response->json(
                'decision.opportunity_uuid'
            );

        expect(
            Str::isUuid(
                $opportunityUuid
            )
        )->toBeTrue();

        $event = AdEvent::query()
            ->sole();

        expect($event->event_type)
            ->toBe(
                AdEvent::EVENT_DECISION
            )
            ->and($event->format)
            ->toBe(
                AdEvent::FORMAT_BANNER
            )
            ->and($event->video_id)
            ->toBe($video->id)
            ->and($event->provider_slug)
            ->toBe(
                'runtime-provider'
            )
            ->and($event->placement_key)
            ->toBe(
                'video_sidebar'
            )
            ->and($event->ad_network)
            ->toBe(
                'runtime-test-network'
            )
            ->and(
                Str::isUuid(
                    $event->session_key
                )
            )
            ->toBeTrue()
            ->and(
                $event->opportunity_uuid
            )
            ->toBe(
                $opportunityUuid
            );
    }
);

test(
    'video based ad format rejects missing video id',
    function () {
        createRuntimeControllerSettings();

        $response = $this->postJson(
            route(
                'monetization.decision'
            ),
            [
                'format' =>
                    AdEvent::FORMAT_POPUNDER,

                'is_mobile' =>
                    false,

                'placement_key' =>
                    'video_player',
            ]
        );

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'video_id',
            ]);

        expect(
            AdEvent::count()
        )->toBe(0);
    }
);

test(
    'popunder decision uses session interaction state and queues visitor cookie',
    function () {
        createRuntimeControllerSettings();

        createRuntimeControllerProvider();

        $video =
            createRuntimeControllerVideo();

        $response = $this
            ->withSession([
                'monetization.meaningful_interactions' =>
                    2,
            ])
            ->postJson(
                route(
                    'monetization.decision'
                ),
                [
                    'format' =>
                        AdEvent::FORMAT_POPUNDER,

                    'video_id' =>
                        $video->id,

                    'is_mobile' =>
                        false,

                    'placement_key' =>
                        'video_player',
                ]
            );

        $response
            ->assertOk()
            ->assertJsonPath(
                'decision.show',
                true
            )
            ->assertJsonPath(
                'decision.reason',
                'eligible'
            )
            ->assertJsonPath(
                'decision.delivery.ad_network',
                'runtime-test-network'
            )
            ->assertJsonPath(
                'decision.delivery.ad_driver',
                'runtime-test-driver'
            )
            ->assertJsonPath(
                'decision.delivery.public_placement_id',
                'runtime-popunder-zone'
            )
            ->assertCookie(
                AnonymousVisitorIdentity::COOKIE_NAME
            )
            ->assertSessionHas(
                'monetization.opportunities'
            );

        expect(
            Str::isUuid(
                $response->json(
                    'decision.opportunity_uuid'
                )
            )
        )->toBeTrue();
    }
);

test(
    'skipped decision is not accepted as an event opportunity',
    function () {
        createRuntimeControllerSettings();

        createRuntimeControllerProvider(
            slug: 'xvideos',
            hasOwnAds: true
        );

        $video =
            createRuntimeControllerVideo(
                'xvideos'
            );

        $decisionResponse =
            $this->postJson(
                route(
                    'monetization.decision'
                ),
                [
                    'format' =>
                        AdEvent::FORMAT_PREROLL,

                    'video_id' =>
                        $video->id,

                    'is_mobile' =>
                        false,

                    'placement_key' =>
                        'video_player',
                ]
            );

        $decisionResponse
            ->assertOk()
            ->assertJsonPath(
                'decision.show',
                false
            )
            ->assertJsonPath(
                'decision.reason',
                'provider_has_own_ads'
            );

        $opportunityUuid =
            $decisionResponse->json(
                'decision.opportunity_uuid'
            );

        $eventResponse =
            $this->postJson(
                route(
                    'monetization.event'
                ),
                [
                    'event_type' =>
                        AdEvent::EVENT_IMPRESSION,

                    'format' =>
                        AdEvent::FORMAT_PREROLL,

                    'opportunity_uuid' =>
                        $opportunityUuid,

                    'video_id' =>
                        $video->id,

                    'is_mobile' =>
                        false,

                    'placement_key' =>
                        'video_player',
                ]
            );

        $eventResponse
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'opportunity_uuid',
            ]);

        expect(
            AdEvent::query()
                ->where(
                    'event_type',
                    AdEvent::EVENT_IMPRESSION
                )
                ->count()
        )->toBe(0);
    }
);

test(
    'issued popunder opportunity can record impression and update protection state',
    function () {
        createRuntimeControllerSettings();

        createRuntimeControllerProvider();

        $video =
            createRuntimeControllerVideo();

        $decisionResponse =
            $this
                ->withSession([
                    'monetization.meaningful_interactions' =>
                        2,
                ])
                ->postJson(
                    route(
                        'monetization.decision'
                    ),
                    [
                        'format' =>
                            AdEvent::FORMAT_POPUNDER,

                        'video_id' =>
                            $video->id,

                        'is_mobile' =>
                            false,

                        'placement_key' =>
                            'video_player',
                    ]
                );

        $decisionResponse
            ->assertOk()
            ->assertJsonPath(
                'decision.show',
                true
            );

        $opportunityUuid =
            $decisionResponse->json(
                'decision.opportunity_uuid'
            );

        $response = $this->postJson(
            route(
                'monetization.event'
            ),
            [
                'event_type' =>
                    AdEvent::EVENT_IMPRESSION,

                'format' =>
                    AdEvent::FORMAT_POPUNDER,

                'opportunity_uuid' =>
                    $opportunityUuid,

                'video_id' =>
                    $video->id,

                'is_mobile' =>
                    false,

                'placement_key' =>
                    'video_player',
            ]
        );

        $response
            ->assertOk()
            ->assertJsonPath(
                'ok',
                true
            )
            ->assertJsonPath(
                'tracked',
                true
            )
            ->assertSessionHas(
                'monetization.popunder_count',
                1
            )
            ->assertSessionHas(
                'monetization.interruption_budget_consumed',
                1
            );

        $eventUuid =
            $response->json(
                'event_uuid'
            );

        expect(
            Str::isUuid(
                $eventUuid
            )
        )->toBeTrue();

        $event = AdEvent::query()
            ->where(
                'event_type',
                AdEvent::EVENT_IMPRESSION
            )
            ->sole();

        expect($event->format)
            ->toBe(
                AdEvent::FORMAT_POPUNDER
            )
            ->and(
                $event->opportunity_uuid
            )
            ->toBe(
                $opportunityUuid
            )
            ->and(
                $event->interruption_cost
            )
            ->toBe(1)
            ->and(
                $event->ad_network
            )
            ->toBe(
                'runtime-test-network'
            )
            ->and(
                $event->metadata[
                    'ad_driver'
                ] ?? null
            )
            ->toBe(
                'runtime-test-driver'
            )
            ->and(
                $event->metadata[
                    'ad_placement_id'
                ] ?? null
            )
            ->toBeInt()
            ->toBeGreaterThan(0);

        expect(
            AdEvent::count()
        )->toBe(2);
    }
);

test(
    'tracking disabled still applies protection for a valid issued opportunity',
    function () {
        createRuntimeControllerSettings(
            trackingEnabled: false
        );

        createRuntimeControllerProvider();

        $video =
            createRuntimeControllerVideo();

        $decisionResponse =
            $this
                ->withSession([
                    'monetization.meaningful_interactions' =>
                        2,
                ])
                ->postJson(
                    route(
                        'monetization.decision'
                    ),
                    [
                        'format' =>
                            AdEvent::FORMAT_POPUNDER,

                        'video_id' =>
                            $video->id,

                        'is_mobile' =>
                            false,

                        'placement_key' =>
                            'video_player',
                    ]
                );

        $decisionResponse
            ->assertOk()
            ->assertJsonPath(
                'decision.show',
                true
            );

        expect(
            AdEvent::count()
        )->toBe(0);

        $opportunityUuid =
            $decisionResponse->json(
                'decision.opportunity_uuid'
            );

        $response = $this->postJson(
            route(
                'monetization.event'
            ),
            [
                'event_type' =>
                    AdEvent::EVENT_IMPRESSION,

                'format' =>
                    AdEvent::FORMAT_POPUNDER,

                'opportunity_uuid' =>
                    $opportunityUuid,

                'video_id' =>
                    $video->id,

                'is_mobile' =>
                    false,

                'placement_key' =>
                    'video_player',
            ]
        );

        $response
            ->assertOk()
            ->assertJsonPath(
                'ok',
                true
            )
            ->assertJsonPath(
                'tracked',
                false
            )
            ->assertJsonPath(
                'event_uuid',
                null
            )
            ->assertSessionHas(
                'monetization.popunder_count',
                1
            )
            ->assertSessionHas(
                'monetization.interruption_budget_consumed',
                1
            );

        expect(
            AdEvent::count()
        )->toBe(0);
    }
);

test(
    'unknown opportunity uuid is rejected before event recording',
    function () {
        createRuntimeControllerSettings();

        $response =
            $this->postJson(
                route(
                    'monetization.event'
                ),
                [
                    'event_type' =>
                        AdEvent::EVENT_IMPRESSION,

                    'format' =>
                        AdEvent::FORMAT_BANNER,

                    'opportunity_uuid' =>
                        (string) Str::uuid(),

                    'is_mobile' =>
                        false,

                    'placement_key' =>
                        'video_sidebar',
                ]
            );

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'opportunity_uuid',
            ]);

        expect(
            AdEvent::count()
        )->toBe(0);
    }
);

test(
    'opportunity context mismatch is rejected',
    function () {
        createRuntimeControllerSettings();

        createRuntimeControllerProvider();

        $video =
            createRuntimeControllerVideo();

        $decisionResponse =
            $this->postJson(
                route(
                    'monetization.decision'
                ),
                [
                    'format' =>
                        AdEvent::FORMAT_BANNER,

                    'video_id' =>
                        $video->id,

                    'is_mobile' =>
                        false,

                    'placement_key' =>
                        'video_sidebar',
                ]
            );

        $decisionResponse
            ->assertOk()
            ->assertJsonPath(
                'decision.show',
                true
            );

        $opportunityUuid =
            $decisionResponse->json(
                'decision.opportunity_uuid'
            );

        $response =
            $this->postJson(
                route(
                    'monetization.event'
                ),
                [
                    'event_type' =>
                        AdEvent::EVENT_IMPRESSION,

                    'format' =>
                        AdEvent::FORMAT_BANNER,

                    'opportunity_uuid' =>
                        $opportunityUuid,

                    'video_id' =>
                        $video->id,

                    'is_mobile' =>
                        false,

                    'placement_key' =>
                        'different_placement',
                ]
            );

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'opportunity_uuid',
            ]);

        expect(
            AdEvent::query()
                ->where(
                    'event_type',
                    AdEvent::EVENT_IMPRESSION
                )
                ->count()
        )->toBe(0)
            ->and(
                AdEvent::query()
                    ->where(
                        'event_type',
                        AdEvent::EVENT_DECISION
                    )
                    ->count()
            )
            ->toBe(1);
    }
);

test(
    'duplicate impression replay is rejected without consuming state twice',
    function () {
        createRuntimeControllerSettings();

        createRuntimeControllerProvider();

        $video =
            createRuntimeControllerVideo();

        $decisionResponse =
            $this
                ->withSession([
                    'monetization.meaningful_interactions' =>
                        2,
                ])
                ->postJson(
                    route(
                        'monetization.decision'
                    ),
                    [
                        'format' =>
                            AdEvent::FORMAT_POPUNDER,

                        'video_id' =>
                            $video->id,

                        'is_mobile' =>
                            false,

                        'placement_key' =>
                            'video_player',
                    ]
                );

        $decisionResponse
            ->assertOk()
            ->assertJsonPath(
                'decision.show',
                true
            );

        $opportunityUuid =
            $decisionResponse->json(
                'decision.opportunity_uuid'
            );

        $payload = [
            'event_type' =>
                AdEvent::EVENT_IMPRESSION,

            'format' =>
                AdEvent::FORMAT_POPUNDER,

            'opportunity_uuid' =>
                $opportunityUuid,

            'video_id' =>
                $video->id,

            'is_mobile' =>
                false,

            'placement_key' =>
                'video_player',
        ];

        $firstResponse =
            $this->postJson(
                route(
                    'monetization.event'
                ),
                $payload
            );

        $firstResponse
            ->assertOk()
            ->assertJsonPath(
                'tracked',
                true
            )
            ->assertSessionHas(
                'monetization.popunder_count',
                1
            )
            ->assertSessionHas(
                'monetization.interruption_budget_consumed',
                1
            );

        $secondResponse =
            $this->postJson(
                route(
                    'monetization.event'
                ),
                $payload
            );

        $secondResponse
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'opportunity_uuid',
            ])
            ->assertSessionHas(
                'monetization.popunder_count',
                1
            )
            ->assertSessionHas(
                'monetization.interruption_budget_consumed',
                1
            );

        expect(
            AdEvent::query()
                ->where(
                    'event_type',
                    AdEvent::EVENT_IMPRESSION
                )
                ->count()
        )->toBe(1)
            ->and(
                AdEvent::count()
            )
            ->toBe(2);
    }
);

test(
    'different event types may use the same issued opportunity',
    function () {
        createRuntimeControllerSettings();

        createRuntimeControllerProvider();

        $video =
            createRuntimeControllerVideo();

        $decisionResponse =
            $this->postJson(
                route(
                    'monetization.decision'
                ),
                [
                    'format' =>
                        AdEvent::FORMAT_BANNER,

                    'video_id' =>
                        $video->id,

                    'is_mobile' =>
                        false,

                    'placement_key' =>
                        'video_sidebar',
                ]
            );

        $decisionResponse
            ->assertOk()
            ->assertJsonPath(
                'decision.show',
                true
            );

        $opportunityUuid =
            $decisionResponse->json(
                'decision.opportunity_uuid'
            );

        $impressionResponse =
            $this->postJson(
                route(
                    'monetization.event'
                ),
                [
                    'event_type' =>
                        AdEvent::EVENT_IMPRESSION,

                    'format' =>
                        AdEvent::FORMAT_BANNER,

                    'opportunity_uuid' =>
                        $opportunityUuid,

                    'video_id' =>
                        $video->id,

                    'is_mobile' =>
                        false,

                    'placement_key' =>
                        'video_sidebar',
                ]
            );

        $clickResponse =
            $this->postJson(
                route(
                    'monetization.event'
                ),
                [
                    'event_type' =>
                        AdEvent::EVENT_CLICK,

                    'format' =>
                        AdEvent::FORMAT_BANNER,

                    'opportunity_uuid' =>
                        $opportunityUuid,

                    'video_id' =>
                        $video->id,

                    'is_mobile' =>
                        false,

                    'placement_key' =>
                        'video_sidebar',
                ]
            );

        $impressionResponse
            ->assertOk()
            ->assertJsonPath(
                'tracked',
                true
            );

        $clickResponse
            ->assertOk()
            ->assertJsonPath(
                'tracked',
                true
            );

        expect(
            AdEvent::query()
                ->where(
                    'opportunity_uuid',
                    $opportunityUuid
                )
                ->count()
        )->toBe(3);
    }
);

test(
    'error event requires error reason',
    function () {
        createRuntimeControllerSettings();

        $response = $this->postJson(
            route(
                'monetization.event'
            ),
            [
                'event_type' =>
                    AdEvent::EVENT_ERROR,

                'format' =>
                    AdEvent::FORMAT_BANNER,

                'opportunity_uuid' =>
                    (string) Str::uuid(),

                'is_mobile' =>
                    false,
            ]
        );

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'error_reason',
            ]);

        expect(
            AdEvent::count()
        )->toBe(0);
    }
);

test(
    'client controlled commercial fields are rejected',
    function (
        string $field,
        mixed $value
    ) {
        createRuntimeControllerSettings();

        $payload = [
            'event_type' =>
                AdEvent::EVENT_IMPRESSION,

            'format' =>
                AdEvent::FORMAT_BANNER,

            'opportunity_uuid' =>
                (string) Str::uuid(),

            'is_mobile' =>
                false,

            $field => $value,
        ];

        $response = $this->postJson(
            route(
                'monetization.event'
            ),
            $payload
        );

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                $field,
            ]);

        expect(
            AdEvent::count()
        )->toBe(0);
    }
)->with([
    'revenue micros' => [
        'revenue_micros',
        999999999,
    ],

    'currency' => [
        'currency',
        'USD',
    ],

    'ad network' => [
        'ad_network',
        'fake-network',
    ],

    'ad placement id' => [
        'ad_placement_id',
        999,
    ],

    'ad driver' => [
        'ad_driver',
        'fake-driver',
    ],

    'campaign key' => [
        'campaign_key',
        'fake-campaign',
    ],

    'interruption cost' => [
        'interruption_cost',
        99,
    ],
]);
