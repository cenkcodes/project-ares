<?php

use App\Models\AdEvent;
use App\Services\Monetization\MonetizationOpportunityState;
use Illuminate\Support\Str;
use InvalidArgumentException;

beforeEach(function () {
    app('session')->flush();
});

function makeOpportunityState(): MonetizationOpportunityState
{
    return app(
        MonetizationOpportunityState::class
    );
}

function makeOpportunityDecision(
    bool $show = true,
    string $format = AdEvent::FORMAT_POPUNDER,
    ?string $opportunityUuid = null
): array {
    return [
        'show' => $show,
        'format' => $format,
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
    'show decision is remembered with its full context',
    function () {
        $state = makeOpportunityState();

        $decision =
            makeOpportunityDecision();

        $state->rememberDecision(
            decision: $decision,
            videoId: 123,
            placementKey: 'video_player',
            isMobile: false
        );

        $opportunity =
            $state->opportunity(
                $decision[
                    'opportunity_uuid'
                ]
            );

        expect($state->count())
            ->toBe(1)
            ->and($opportunity)
            ->not->toBeNull()
            ->and(
                $opportunity[
                    'opportunity_uuid'
                ]
            )
            ->toBe(
                $decision[
                    'opportunity_uuid'
                ]
            )
            ->and(
                $opportunity['format']
            )
            ->toBe(
                AdEvent::FORMAT_POPUNDER
            )
            ->and(
                $opportunity['video_id']
            )
            ->toBe(123)
            ->and(
                $opportunity[
                    'placement_key'
                ]
            )
            ->toBe('video_player')
            ->and(
                $opportunity['is_mobile']
            )
            ->toBeFalse()
            ->and(
                $opportunity[
                    'claimed_events'
                ]
            )
            ->toBe([])
            ->and(
                $opportunity[
                    'created_at'
                ]
            )
            ->toBeString();
    }
);

test(
    'skip decision is not remembered',
    function () {
        $state = makeOpportunityState();

        $decision =
            makeOpportunityDecision(
                show: false
            );

        $state->rememberDecision(
            decision: $decision,
            videoId: 123,
            placementKey: 'video_player',
            isMobile: false
        );

        expect($state->count())
            ->toBe(0)
            ->and(
                $state->opportunity(
                    $decision[
                        'opportunity_uuid'
                    ]
                )
            )
            ->toBeNull();
    }
);

test(
    'valid event claim is stored on opportunity',
    function () {
        $state = makeOpportunityState();

        $decision =
            makeOpportunityDecision();

        $state->rememberDecision(
            decision: $decision,
            videoId: 123,
            placementKey: 'video_player',
            isMobile: false
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
                    AdEvent::FORMAT_POPUNDER,
                videoId: 123,
                placementKey:
                    'video_player',
                isMobile: false
            );

        expect(
            $claimed[
                'claimed_events'
            ]
        )
            ->toBe([
                AdEvent::EVENT_IMPRESSION,
            ]);

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
        )
            ->toBe([
                AdEvent::EVENT_IMPRESSION,
            ]);
    }
);

test(
    'same event type cannot be claimed twice',
    function () {
        $state = makeOpportunityState();

        $decision =
            makeOpportunityDecision();

        $state->rememberDecision(
            decision: $decision,
            videoId: 123,
            placementKey: 'video_player',
            isMobile: false
        );

        $state->claimEvent(
            opportunityUuid:
                $decision[
                    'opportunity_uuid'
                ],
            eventType:
                AdEvent::EVENT_IMPRESSION,
            format:
                AdEvent::FORMAT_POPUNDER,
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
                        AdEvent::EVENT_IMPRESSION,
                    format:
                        AdEvent::FORMAT_POPUNDER,
                    videoId: 123,
                    placementKey:
                        'video_player',
                    isMobile: false
                )
        )->toThrow(
            InvalidArgumentException::class,
            'This monetization event has already been claimed.'
        );
    }
);

test(
    'different event types may reuse same opportunity',
    function () {
        $state = makeOpportunityState();

        $decision =
            makeOpportunityDecision();

        $state->rememberDecision(
            decision: $decision,
            videoId: 123,
            placementKey: 'video_player',
            isMobile: false
        );

        $state->claimEvent(
            opportunityUuid:
                $decision[
                    'opportunity_uuid'
                ],
            eventType:
                AdEvent::EVENT_IMPRESSION,
            format:
                AdEvent::FORMAT_POPUNDER,
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
                    AdEvent::FORMAT_POPUNDER,
                videoId: 123,
                placementKey:
                    'video_player',
                isMobile: false
            );

        expect(
            $claimed[
                'claimed_events'
            ]
        )
            ->toBe([
                AdEvent::EVENT_IMPRESSION,
                AdEvent::EVENT_CLICK,
            ]);
    }
);

test(
    'unknown opportunity is rejected',
    function () {
        $state = makeOpportunityState();

        expect(
            fn () =>
                $state->claimEvent(
                    opportunityUuid:
                        (string) Str::uuid(),
                    eventType:
                        AdEvent::EVENT_IMPRESSION,
                    format:
                        AdEvent::FORMAT_POPUNDER,
                    videoId: 123,
                    placementKey:
                        'video_player',
                    isMobile: false
                )
        )->toThrow(
            InvalidArgumentException::class,
            'Unknown monetization opportunity.'
        );
    }
);

test(
    'format mismatch is rejected',
    function () {
        $state = makeOpportunityState();

        $decision =
            makeOpportunityDecision();

        $state->rememberDecision(
            decision: $decision,
            videoId: 123,
            placementKey: 'video_player',
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
            'Monetization opportunity format mismatch.'
        );
    }
);

test(
    'video mismatch is rejected',
    function () {
        $state = makeOpportunityState();

        $decision =
            makeOpportunityDecision();

        $state->rememberDecision(
            decision: $decision,
            videoId: 123,
            placementKey: 'video_player',
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
                        AdEvent::EVENT_IMPRESSION,
                    format:
                        AdEvent::FORMAT_POPUNDER,
                    videoId: 999,
                    placementKey:
                        'video_player',
                    isMobile: false
                )
        )->toThrow(
            InvalidArgumentException::class,
            'Monetization opportunity video mismatch.'
        );
    }
);

test(
    'placement mismatch is rejected',
    function () {
        $state = makeOpportunityState();

        $decision =
            makeOpportunityDecision();

        $state->rememberDecision(
            decision: $decision,
            videoId: 123,
            placementKey: 'video_player',
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
                        AdEvent::EVENT_IMPRESSION,
                    format:
                        AdEvent::FORMAT_POPUNDER,
                    videoId: 123,
                    placementKey:
                        'other_placement',
                    isMobile: false
                )
        )->toThrow(
            InvalidArgumentException::class,
            'Monetization opportunity placement mismatch.'
        );
    }
);

test(
    'device mismatch is rejected',
    function () {
        $state = makeOpportunityState();

        $decision =
            makeOpportunityDecision();

        $state->rememberDecision(
            decision: $decision,
            videoId: 123,
            placementKey: 'video_player',
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
                        AdEvent::EVENT_IMPRESSION,
                    format:
                        AdEvent::FORMAT_POPUNDER,
                    videoId: 123,
                    placementKey:
                        'video_player',
                    isMobile: true
                )
        )->toThrow(
            InvalidArgumentException::class,
            'Monetization opportunity device mismatch.'
        );
    }
);

test(
    'opportunity storage is limited to newest one hundred entries',
    function () {
        $state = makeOpportunityState();

        $uuids = [];

        for (
            $index = 1;
            $index <= 101;
            $index++
        ) {
            $uuid =
                (string) Str::uuid();

            $uuids[] = $uuid;

            $state->rememberDecision(
                decision:
                    makeOpportunityDecision(
                        opportunityUuid: $uuid
                    ),
                videoId: $index,
                placementKey:
                    'video_player',
                isMobile: false
            );
        }

        expect($state->count())
            ->toBe(100)
            ->and(
                $state->opportunity(
                    $uuids[0]
                )
            )
            ->toBeNull()
            ->and(
                $state->opportunity(
                    $uuids[1]
                )
            )
            ->not->toBeNull()
            ->and(
                $state->opportunity(
                    $uuids[100]
                )
            )
            ->not->toBeNull();
    }
);

test(
    'reset clears all remembered opportunities',
    function () {
        $state = makeOpportunityState();

        $decision =
            makeOpportunityDecision();

        $state->rememberDecision(
            decision: $decision,
            videoId: 123,
            placementKey: 'video_player',
            isMobile: false
        );

        expect($state->count())
            ->toBe(1);

        $state->reset();

        expect($state->count())
            ->toBe(0)
            ->and(
                $state->opportunity(
                    $decision[
                        'opportunity_uuid'
                    ]
                )
            )
            ->toBeNull();
    }
);
