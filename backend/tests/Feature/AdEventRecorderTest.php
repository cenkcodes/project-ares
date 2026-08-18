<?php

use App\Models\AdEvent;
use App\Models\MonetizationSetting;
use App\Models\Video;
use App\Services\Monetization\AdEventRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function enableAdEventTracking(
    bool $enabled = true
): MonetizationSetting {
    return MonetizationSetting::create([
        'settings_key' =>
            MonetizationSetting::GLOBAL_SETTINGS_KEY,
        'profile' =>
            MonetizationSetting::PROFILE_BALANCED,
        'master_enabled' => true,
        'ad_event_tracking_enabled' => $enabled,
    ]);
}

function createAdEventTestVideo(
    string $provider = 'xvideos'
): Video {
    $uuid = (string) Str::uuid();

    return Video::create([
        'title' => 'Ad Event Recorder Test',
        'slug' => 'ad-event-recorder-' . $uuid,
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
    'recorder fails closed when global settings are missing',
    function () {
        $recorder = new AdEventRecorder();

        $event = $recorder->recordImpression(
            format: AdEvent::FORMAT_BANNER
        );

        expect($event)
            ->toBeNull()
            ->and(AdEvent::count())
            ->toBe(0);
    }
);

test(
    'recorder does not write events when tracking is disabled',
    function () {
        enableAdEventTracking(false);

        $recorder = new AdEventRecorder();

        $event = $recorder->recordImpression(
            format: AdEvent::FORMAT_BANNER
        );

        expect($event)
            ->toBeNull()
            ->and(AdEvent::count())
            ->toBe(0);
    }
);

test(
    'recorder generates valid opportunity uuids',
    function () {
        $recorder = new AdEventRecorder();

        $uuid = $recorder->newOpportunityUuid();

        expect(Str::isUuid($uuid))
            ->toBeTrue();
    }
);

test(
    'recorder stores ad decisions with provider and metadata',
    function () {
        enableAdEventTracking();

        $video = createAdEventTestVideo(
            'xvideos'
        );

        $recorder = new AdEventRecorder();

        $opportunityUuid =
            $recorder->newOpportunityUuid();

        $event = $recorder->recordDecision(
            decision: [
                'show' => false,
                'format' =>
                    AdEvent::FORMAT_PREROLL,
                'reason' =>
                    'provider_has_own_ads',
                'metadata' => [
                    'provider_has_own_ads' => true,
                ],
            ],
            video: $video,
            opportunityUuid: $opportunityUuid,
            placementKey: 'video_player',
            sessionKey: 'session-test',
            deviceType: AdEvent::DEVICE_DESKTOP,
            metadata: [
                'interaction_number' => 1,
            ]
        );

        expect($event)
            ->toBeInstanceOf(AdEvent::class)
            ->and($event->video_id)
            ->toBe($video->id)
            ->and($event->provider_slug)
            ->toBe('xvideos')
            ->and($event->format)
            ->toBe(AdEvent::FORMAT_PREROLL)
            ->and($event->event_type)
            ->toBe(AdEvent::EVENT_DECISION)
            ->and($event->decision_outcome)
            ->toBe(AdEvent::OUTCOME_SKIP)
            ->and($event->decision_reason)
            ->toBe('provider_has_own_ads')
            ->and($event->opportunity_uuid)
            ->toBe($opportunityUuid)
            ->and($event->metadata)
            ->toMatchArray([
                'provider_has_own_ads' => true,
                'interaction_number' => 1,
            ]);
    }
);

test(
    'recorder stores impressions with revenue data',
    function () {
        enableAdEventTracking();

        $video = createAdEventTestVideo();

        $recorder = new AdEventRecorder();

        $event = $recorder->recordImpression(
            format: AdEvent::FORMAT_POPUNDER,
            video: $video,
            opportunityUuid:
                $recorder->newOpportunityUuid(),
            placementKey: 'video_player',
            sessionKey: 'session-impression',
            deviceType: AdEvent::DEVICE_MOBILE,
            interruptionCost: 1,
            adNetwork: 'test-network',
            campaignKey: 'campaign-001',
            revenueMicros: 250000,
            currency: 'usd',
            metadata: [
                'source' => 'test',
            ]
        );

        expect($event)
            ->toBeInstanceOf(AdEvent::class)
            ->and($event->event_type)
            ->toBe(AdEvent::EVENT_IMPRESSION)
            ->and($event->interruption_cost)
            ->toBe(1)
            ->and($event->revenue_micros)
            ->toBe(250000)
            ->and($event->currency)
            ->toBe('USD')
            ->and($event->ad_network)
            ->toBe('test-network')
            ->and($event->campaign_key)
            ->toBe('campaign-001');
    }
);

test(
    'recorder stores click skip close and error events',
    function () {
        enableAdEventTracking();

        $video = createAdEventTestVideo();

        $recorder = new AdEventRecorder();

        $opportunityUuid =
            $recorder->newOpportunityUuid();

        $click = $recorder->recordClick(
            format: AdEvent::FORMAT_BANNER,
            video: $video,
            opportunityUuid: $opportunityUuid
        );

        $skip = $recorder->recordSkip(
            format: AdEvent::FORMAT_PREROLL,
            video: $video,
            opportunityUuid: $opportunityUuid
        );

        $close = $recorder->recordClose(
            format: AdEvent::FORMAT_INTERSTITIAL,
            video: $video,
            opportunityUuid: $opportunityUuid
        );

        $error = $recorder->recordError(
            format: AdEvent::FORMAT_POPUNDER,
            errorReason: 'network_error',
            video: $video,
            opportunityUuid: $opportunityUuid
        );

        expect($click?->event_type)
            ->toBe(AdEvent::EVENT_CLICK)
            ->and($skip?->event_type)
            ->toBe(AdEvent::EVENT_SKIP)
            ->and($close?->event_type)
            ->toBe(AdEvent::EVENT_CLOSE)
            ->and($error?->event_type)
            ->toBe(AdEvent::EVENT_ERROR)
            ->and($error?->decision_reason)
            ->toBe('network_error')
            ->and(AdEvent::count())
            ->toBe(4);
    }
);

test(
    'explicit event uuid makes recording idempotent',
    function () {
        enableAdEventTracking();

        $recorder = new AdEventRecorder();

        $eventUuid = (string) Str::uuid();

        $first = $recorder->record([
            'event_uuid' => $eventUuid,
            'format' => AdEvent::FORMAT_BANNER,
            'event_type' =>
                AdEvent::EVENT_IMPRESSION,
            'placement_key' => 'home_grid',
        ]);

        $second = $recorder->record([
            'event_uuid' => $eventUuid,
            'format' => AdEvent::FORMAT_BANNER,
            'event_type' =>
                AdEvent::EVENT_IMPRESSION,
            'placement_key' => 'home_grid',
        ]);

        expect($first?->id)
            ->toBe($second?->id)
            ->and(AdEvent::count())
            ->toBe(1);
    }
);

test(
    'recorder rejects unsupported ad formats',
    function () {
        enableAdEventTracking();

        $recorder = new AdEventRecorder();

        expect(
            fn () => $recorder->record([
                'format' => 'unsupported-format',
                'event_type' =>
                    AdEvent::EVENT_IMPRESSION,
            ])
        )->toThrow(
            \InvalidArgumentException::class,
            'Unsupported ad format.'
        );
    }
);

test(
    'recorder rejects unsupported event types',
    function () {
        enableAdEventTracking();

        $recorder = new AdEventRecorder();

        expect(
            fn () => $recorder->record([
                'format' => AdEvent::FORMAT_BANNER,
                'event_type' => 'unsupported-event',
            ])
        )->toThrow(
            \InvalidArgumentException::class,
            'Unsupported ad event type.'
        );
    }
);

test(
    'recorder rejects unsupported device types',
    function () {
        enableAdEventTracking();

        $recorder = new AdEventRecorder();

        expect(
            fn () => $recorder->record([
                'format' => AdEvent::FORMAT_BANNER,
                'event_type' =>
                    AdEvent::EVENT_IMPRESSION,
                'device_type' => 'smart-fridge',
            ])
        )->toThrow(
            \InvalidArgumentException::class,
            'Unsupported ad device type.'
        );
    }
);

test(
    'recorder rejects negative revenue',
    function () {
        enableAdEventTracking();

        $recorder = new AdEventRecorder();

        expect(
            fn () => $recorder->record([
                'format' => AdEvent::FORMAT_BANNER,
                'event_type' =>
                    AdEvent::EVENT_IMPRESSION,
                'revenue_micros' => -1,
            ])
        )->toThrow(
            \InvalidArgumentException::class,
            'Revenue micros must be a non-negative integer.'
        );
    }
);

test(
    'recorder rejects invalid currency codes',
    function () {
        enableAdEventTracking();

        $recorder = new AdEventRecorder();

        expect(
            fn () => $recorder->record([
                'format' => AdEvent::FORMAT_BANNER,
                'event_type' =>
                    AdEvent::EVENT_IMPRESSION,
                'currency' => 'US',
            ])
        )->toThrow(
            \InvalidArgumentException::class,
            'Currency must be a 3-character code.'
        );
    }
);
