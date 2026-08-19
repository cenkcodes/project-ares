<?php

use App\Models\AdEvent;
use App\Services\Monetization\MonetizationOpportunityState;
use Illuminate\Support\Str;

beforeEach(function () {
    app('session')->flush();
});

function makeLifecycleOpportunityState(): MonetizationOpportunityState
{
    return app(
        MonetizationOpportunityState::class
    );
}

function makeLifecycleDecision(
    string $format
): array {
    return [
        'show' => true,
        'format' => $format,
        'reason' => 'eligible',
        'opportunity_uuid' =>
            (string) Str::uuid(),
    ];
}

function rememberLifecycleDecision(
    MonetizationOpportunityState $state,
    array $decision
): void {
    $state->rememberDecision(
        decision: $decision,
        videoId: 123,
        placementKey: 'video_player',
        isMobile: false
    );
}

test(
    'click is rejected before impression',
    function () {
        $state =
            makeLifecycleOpportunityState();

        $decision =
            makeLifecycleDecision(
                AdEvent::FORMAT_BANNER
            );

        rememberLifecycleDecision(
            $state,
            $decision
        );

        expect(
            fn () =>
                $state->claimEvent(
                    opportunityUuid:
                        $decision[
                            'opportunity_uuid'
                        ],
                    eventType:
                        AdEvent::EVENT_CLICK,
                    format:
                        AdEvent::FORMAT_BANNER,
                    videoId: 123,
                    placementKey:
                        'video_player',
                    isMobile: false
                )
        )->toThrow(
            InvalidArgumentException::class,
            'Monetization event requires a prior impression.'
        );

        $stored =
            $state->opportunity(
                $decision[
                    'opportunity_uuid'
                ]
            );

        expect(
            $stored[
                'claimed_events'
            ]
        )->toBe([]);
    }
);

test(
    'banner impression followed by click is valid',
    function () {
        $state =
            makeLifecycleOpportunityState();

        $decision =
            makeLifecycleDecision(
                AdEvent::FORMAT_BANNER
            );

        rememberLifecycleDecision(
            $state,
            $decision
        );

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
                'video_player',
            isMobile: false
        );

        $claimed =
            $state->claimEvent(
                opportunityUuid:
                    $decision[
                        'opportunity_uuid'
                    ],
                eventType:
                    AdEvent::EVENT_CLICK,
                format:
                    AdEvent::FORMAT_BANNER,
                videoId: 123,
                placementKey:
                    'video_player',
                isMobile: false
            );

        expect(
            $claimed[
                'claimed_events'
            ]
        )->toBe([
            AdEvent::EVENT_IMPRESSION,
            AdEvent::EVENT_CLICK,
        ]);
    }
);

test(
    'skip is rejected before impression even for preroll',
    function () {
        $state =
            makeLifecycleOpportunityState();

        $decision =
            makeLifecycleDecision(
                AdEvent::FORMAT_PREROLL
            );

        rememberLifecycleDecision(
            $state,
            $decision
        );

        expect(
            fn () =>
                $state->claimEvent(
                    opportunityUuid:
                        $decision[
                            'opportunity_uuid'
                        ],
                    eventType:
                        AdEvent::EVENT_SKIP,
                    format:
                        AdEvent::FORMAT_PREROLL,
                    videoId: 123,
                    placementKey:
                        'video_player',
                    isMobile: false
                )
        )->toThrow(
            InvalidArgumentException::class,
            'Monetization event requires a prior impression.'
        );
    }
);

test(
    'skip is rejected for unsupported format',
    function () {
        $state =
            makeLifecycleOpportunityState();

        $decision =
            makeLifecycleDecision(
                AdEvent::FORMAT_BANNER
            );

        rememberLifecycleDecision(
            $state,
            $decision
        );

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
                'video_player',
            isMobile: false
        );

        expect(
            fn () =>
                $state->claimEvent(
                    opportunityUuid:
                        $decision[
                            'opportunity_uuid'
                        ],
                    eventType:
                        AdEvent::EVENT_SKIP,
                    format:
                        AdEvent::FORMAT_BANNER,
                    videoId: 123,
                    placementKey:
                        'video_player',
                    isMobile: false
                )
        )->toThrow(
            InvalidArgumentException::class,
            'Skip event is not supported for this monetization format.'
        );

        $stored =
            $state->opportunity(
                $decision[
                    'opportunity_uuid'
                ]
            );

        expect(
            $stored[
                'claimed_events'
            ]
        )->toBe([
            AdEvent::EVENT_IMPRESSION,
        ]);
    }
);

test(
    'preroll skip after impression is valid and terminal',
    function () {
        $state =
            makeLifecycleOpportunityState();

        $decision =
            makeLifecycleDecision(
                AdEvent::FORMAT_PREROLL
            );

        rememberLifecycleDecision(
            $state,
            $decision
        );

        $state->claimEvent(
            opportunityUuid:
                $decision[
                    'opportunity_uuid'
                ],
            eventType:
                AdEvent::EVENT_IMPRESSION,
            format:
                AdEvent::FORMAT_PREROLL,
            videoId: 123,
            placementKey:
                'video_player',
            isMobile: false
        );

        $claimed =
            $state->claimEvent(
                opportunityUuid:
                    $decision[
                        'opportunity_uuid'
                    ],
                eventType:
                    AdEvent::EVENT_SKIP,
                format:
                    AdEvent::FORMAT_PREROLL,
                videoId: 123,
                placementKey:
                    'video_player',
                isMobile: false
            );

        expect(
            $claimed[
                'claimed_events'
            ]
        )->toBe([
            AdEvent::EVENT_IMPRESSION,
            AdEvent::EVENT_SKIP,
        ]);

        expect(
            fn () =>
                $state->claimEvent(
                    opportunityUuid:
                        $decision[
                            'opportunity_uuid'
                        ],
                    eventType:
                        AdEvent::EVENT_CLICK,
                    format:
                        AdEvent::FORMAT_PREROLL,
                    videoId: 123,
                    placementKey:
                        'video_player',
                    isMobile: false
                )
        )->toThrow(
            InvalidArgumentException::class,
            'Monetization opportunity lifecycle is already complete.'
        );
    }
);

test(
    'close is rejected before impression even for interstitial',
    function () {
        $state =
            makeLifecycleOpportunityState();

        $decision =
            makeLifecycleDecision(
                AdEvent::FORMAT_INTERSTITIAL
            );

        rememberLifecycleDecision(
            $state,
            $decision
        );

        expect(
            fn () =>
                $state->claimEvent(
                    opportunityUuid:
                        $decision[
                            'opportunity_uuid'
                        ],
                    eventType:
                        AdEvent::EVENT_CLOSE,
                    format:
                        AdEvent::FORMAT_INTERSTITIAL,
                    videoId: 123,
                    placementKey:
                        'video_player',
                    isMobile: false
                )
        )->toThrow(
            InvalidArgumentException::class,
            'Monetization event requires a prior impression.'
        );
    }
);

test(
    'close is rejected for unsupported format',
    function () {
        $state =
            makeLifecycleOpportunityState();

        $decision =
            makeLifecycleDecision(
                AdEvent::FORMAT_BANNER
            );

        rememberLifecycleDecision(
            $state,
            $decision
        );

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
                'video_player',
            isMobile: false
        );

        expect(
            fn () =>
                $state->claimEvent(
                    opportunityUuid:
                        $decision[
                            'opportunity_uuid'
                        ],
                    eventType:
                        AdEvent::EVENT_CLOSE,
                    format:
                        AdEvent::FORMAT_BANNER,
                    videoId: 123,
                    placementKey:
                        'video_player',
                    isMobile: false
                )
        )->toThrow(
            InvalidArgumentException::class,
            'Close event is not supported for this monetization format.'
        );

        $stored =
            $state->opportunity(
                $decision[
                    'opportunity_uuid'
                ]
            );

        expect(
            $stored[
                'claimed_events'
            ]
        )->toBe([
            AdEvent::EVENT_IMPRESSION,
        ]);
    }
);

test(
    'interstitial close after impression is valid and terminal',
    function () {
        $state =
            makeLifecycleOpportunityState();

        $decision =
            makeLifecycleDecision(
                AdEvent::FORMAT_INTERSTITIAL
            );

        rememberLifecycleDecision(
            $state,
            $decision
        );

        $state->claimEvent(
            opportunityUuid:
                $decision[
                    'opportunity_uuid'
                ],
            eventType:
                AdEvent::EVENT_IMPRESSION,
            format:
                AdEvent::FORMAT_INTERSTITIAL,
            videoId: 123,
            placementKey:
                'video_player',
            isMobile: false
        );

        $claimed =
            $state->claimEvent(
                opportunityUuid:
                    $decision[
                        'opportunity_uuid'
                    ],
                eventType:
                    AdEvent::EVENT_CLOSE,
                format:
                    AdEvent::FORMAT_INTERSTITIAL,
                videoId: 123,
                placementKey:
                    'video_player',
                isMobile: false
            );

        expect(
            $claimed[
                'claimed_events'
            ]
        )->toBe([
            AdEvent::EVENT_IMPRESSION,
            AdEvent::EVENT_CLOSE,
        ]);

        expect(
            fn () =>
                $state->claimEvent(
                    opportunityUuid:
                        $decision[
                            'opportunity_uuid'
                        ],
                    eventType:
                        AdEvent::EVENT_CLICK,
                    format:
                        AdEvent::FORMAT_INTERSTITIAL,
                    videoId: 123,
                    placementKey:
                        'video_player',
                    isMobile: false
                )
        )->toThrow(
            InvalidArgumentException::class,
            'Monetization opportunity lifecycle is already complete.'
        );
    }
);

test(
    'error may occur before impression and is terminal',
    function () {
        $state =
            makeLifecycleOpportunityState();

        $decision =
            makeLifecycleDecision(
                AdEvent::FORMAT_BANNER
            );

        rememberLifecycleDecision(
            $state,
            $decision
        );

        $claimed =
            $state->claimEvent(
                opportunityUuid:
                    $decision[
                        'opportunity_uuid'
                    ],
                eventType:
                    AdEvent::EVENT_ERROR,
                format:
                    AdEvent::FORMAT_BANNER,
                videoId: 123,
                placementKey:
                    'video_player',
                isMobile: false
            );

        expect(
            $claimed[
                'claimed_events'
            ]
        )->toBe([
            AdEvent::EVENT_ERROR,
        ]);

        expect(
            fn () =>
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
                        'video_player',
                    isMobile: false
                )
        )->toThrow(
            InvalidArgumentException::class,
            'Monetization opportunity lifecycle is already complete.'
        );
    }
);

test(
    'error may occur after impression and is terminal',
    function () {
        $state =
            makeLifecycleOpportunityState();

        $decision =
            makeLifecycleDecision(
                AdEvent::FORMAT_BANNER
            );

        rememberLifecycleDecision(
            $state,
            $decision
        );

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
                'video_player',
            isMobile: false
        );

        $claimed =
            $state->claimEvent(
                opportunityUuid:
                    $decision[
                        'opportunity_uuid'
                    ],
                eventType:
                    AdEvent::EVENT_ERROR,
                format:
                    AdEvent::FORMAT_BANNER,
                videoId: 123,
                placementKey:
                    'video_player',
                isMobile: false
            );

        expect(
            $claimed[
                'claimed_events'
            ]
        )->toBe([
            AdEvent::EVENT_IMPRESSION,
            AdEvent::EVENT_ERROR,
        ]);

        expect(
            fn () =>
                $state->claimEvent(
                    opportunityUuid:
                        $decision[
                            'opportunity_uuid'
                        ],
                    eventType:
                        AdEvent::EVENT_CLICK,
                    format:
                        AdEvent::FORMAT_BANNER,
                    videoId: 123,
                    placementKey:
                        'video_player',
                    isMobile: false
                )
        )->toThrow(
            InvalidArgumentException::class,
            'Monetization opportunity lifecycle is already complete.'
        );
    }
);
