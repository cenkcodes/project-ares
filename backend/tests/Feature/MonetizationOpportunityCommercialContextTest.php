<?php

use App\Models\AdEvent;
use App\Services\Monetization\MonetizationOpportunityState;
use Illuminate\Support\Str;

beforeEach(function () {
    app('session')->flush();
});

function makeCommercialContextOpportunityState(): MonetizationOpportunityState
{
    return app(
        MonetizationOpportunityState::class
    );
}

function makeCommercialContextDecision(
    bool $show = true,
    ?string $opportunityUuid = null
): array {
    return [
        'show' => $show,
        'format' => AdEvent::FORMAT_BANNER,
        'reason' =>
            $show
                ? 'eligible'
                : 'not_eligible',
        'opportunity_uuid' =>
            $opportunityUuid
            ?? (string) Str::uuid(),
    ];
}

test(
    'trusted commercial context is remembered with opportunity',
    function () {
        $state =
            makeCommercialContextOpportunityState();

        $decision =
            makeCommercialContextDecision();

        $state->rememberDecision(
            decision: $decision,
            videoId: 123,
            placementKey: 'video_banner',
            isMobile: false,
            adNetwork: 'test-network',
            adPlacementId: 55,
            adDriver: 'test-driver'
        );

        $opportunity =
            $state->opportunity(
                $decision[
                    'opportunity_uuid'
                ]
            );

        expect($opportunity)
            ->not->toBeNull()
            ->and(
                $opportunity[
                    'ad_network'
                ]
            )
            ->toBe('test-network')
            ->and(
                $opportunity[
                    'ad_placement_id'
                ]
            )
            ->toBe(55)
            ->and(
                $opportunity[
                    'ad_driver'
                ]
            )
            ->toBe('test-driver');
    }
);

test(
    'claimed event returns trusted commercial context',
    function () {
        $state =
            makeCommercialContextOpportunityState();

        $decision =
            makeCommercialContextDecision();

        $state->rememberDecision(
            decision: $decision,
            videoId: 123,
            placementKey: 'video_banner',
            isMobile: true,
            adNetwork: 'test-network',
            adPlacementId: 77,
            adDriver: 'test-driver'
        );

        $claimed =
            $state->claimEvent(
                opportunityUuid:
                    $decision[
                        'opportunity_uuid'
                    ],
                eventType:
                    AdEvent::EVENT_IMPRESSION,
                format:
                    AdEvent::FORMAT_BANNER,
                videoId: 123,
                placementKey:
                    'video_banner',
                isMobile: true
            );

        expect(
            $claimed[
                'ad_network'
            ]
        )
            ->toBe('test-network')
            ->and(
                $claimed[
                    'ad_placement_id'
                ]
            )
            ->toBe(77)
            ->and(
                $claimed[
                    'ad_driver'
                ]
            )
            ->toBe('test-driver')
            ->and(
                $claimed[
                    'claimed_events'
                ]
            )
            ->toBe([
                AdEvent::EVENT_IMPRESSION,
            ]);
    }
);

test(
    'commercial context may be completely absent',
    function () {
        $state =
            makeCommercialContextOpportunityState();

        $decision =
            makeCommercialContextDecision();

        $state->rememberDecision(
            decision: $decision,
            videoId: 123,
            placementKey: 'video_banner',
            isMobile: false
        );

        $opportunity =
            $state->opportunity(
                $decision[
                    'opportunity_uuid'
                ]
            );

        expect($opportunity)
            ->not->toBeNull()
            ->and(
                $opportunity[
                    'ad_network'
                ]
            )
            ->toBeNull()
            ->and(
                $opportunity[
                    'ad_placement_id'
                ]
            )
            ->toBeNull()
            ->and(
                $opportunity[
                    'ad_driver'
                ]
            )
            ->toBeNull();
    }
);

test(
    'partial commercial context fails closed',
    function () {
        $state =
            makeCommercialContextOpportunityState();

        expect(
            fn () =>
                $state->rememberDecision(
                    decision:
                        makeCommercialContextDecision(),
                    videoId: 123,
                    placementKey:
                        'video_banner',
                    isMobile: false,
                    adNetwork:
                        'test-network'
                )
        )->toThrow(
            InvalidArgumentException::class,
            'Incomplete monetization commercial context.'
        );

        expect(
            fn () =>
                $state->rememberDecision(
                    decision:
                        makeCommercialContextDecision(),
                    videoId: 123,
                    placementKey:
                        'video_banner',
                    isMobile: false,
                    adNetwork:
                        'test-network',
                    adPlacementId:
                        55
                )
        )->toThrow(
            InvalidArgumentException::class,
            'Incomplete monetization commercial context.'
        );

        expect(
            fn () =>
                $state->rememberDecision(
                    decision:
                        makeCommercialContextDecision(),
                    videoId: 123,
                    placementKey:
                        'video_banner',
                    isMobile: false,
                    adPlacementId:
                        55,
                    adDriver:
                        'test-driver'
                )
        )->toThrow(
            InvalidArgumentException::class,
            'Incomplete monetization commercial context.'
        );
    }
);

test(
    'invalid commercial context values fail closed',
    function () {
        $state =
            makeCommercialContextOpportunityState();

        expect(
            fn () =>
                $state->rememberDecision(
                    decision:
                        makeCommercialContextDecision(),
                    videoId: 123,
                    placementKey:
                        'video_banner',
                    isMobile: false,
                    adNetwork:
                        '   ',
                    adPlacementId:
                        55,
                    adDriver:
                        'test-driver'
                )
        )->toThrow(
            InvalidArgumentException::class,
            'Invalid monetization ad network.'
        );

        expect(
            fn () =>
                $state->rememberDecision(
                    decision:
                        makeCommercialContextDecision(),
                    videoId: 123,
                    placementKey:
                        'video_banner',
                    isMobile: false,
                    adNetwork:
                        'test-network',
                    adPlacementId:
                        0,
                    adDriver:
                        'test-driver'
                )
        )->toThrow(
            InvalidArgumentException::class,
            'Invalid monetization ad placement.'
        );

        expect(
            fn () =>
                $state->rememberDecision(
                    decision:
                        makeCommercialContextDecision(),
                    videoId: 123,
                    placementKey:
                        'video_banner',
                    isMobile: false,
                    adNetwork:
                        'test-network',
                    adPlacementId:
                        55,
                    adDriver:
                        '   '
                )
        )->toThrow(
            InvalidArgumentException::class,
            'Invalid monetization ad driver.'
        );
    }
);

test(
    'skip decision does not create trusted commercial state',
    function () {
        $state =
            makeCommercialContextOpportunityState();

        $decision =
            makeCommercialContextDecision(
                show: false
            );

        $state->rememberDecision(
            decision: $decision,
            videoId: 123,
            placementKey: 'video_banner',
            isMobile: false,
            adNetwork: 'test-network',
            adPlacementId: 55,
            adDriver: 'test-driver'
        );

        expect(
            $state->opportunity(
                $decision[
                    'opportunity_uuid'
                ]
            )
        )->toBeNull()
            ->and(
                $state->count()
            )
            ->toBe(0);
    }
);
