<?php

use App\Services\Monetization\MonetizationSessionState;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Str;

function makeMonetizationSessionStateForTest(): MonetizationSessionState
{
    return new MonetizationSessionState(
        app(Session::class)
    );
}

test(
    'session state generates and persists a valid session uuid',
    function () {
        $state = makeMonetizationSessionStateForTest();

        $first = $state->sessionKey();
        $second = $state->sessionKey();

        expect(Str::isUuid($first))
            ->toBeTrue()
            ->and($second)
            ->toBe($first);
    }
);

test(
    'meaningful interactions start at zero and increment',
    function () {
        $state = makeMonetizationSessionStateForTest();

        expect($state->meaningfulInteractionCount())
            ->toBe(0);

        $first = $state->recordMeaningfulInteraction();

        $second = $state->recordMeaningfulInteraction(
            2
        );

        expect($first)
            ->toBe(1)
            ->and($second)
            ->toBe(3)
            ->and(
                $state->meaningfulInteractionCount()
            )
            ->toBe(3);
    }
);

test(
    'preroll impression updates count budget and timestamp',
    function () {
        $state = makeMonetizationSessionStateForTest();

        $occurredAt = CarbonImmutable::parse(
            '2026-08-18 10:00:00 UTC'
        );

        $state->recordPrerollImpression(
            interruptionCost: 1,
            occurredAt: $occurredAt
        );

        expect($state->sessionPrerollCount())
            ->toBe(1)
            ->and(
                $state->consumedInterruptionBudget()
            )
            ->toBe(1)
            ->and(
                $state
                    ->lastPrerollAt()
                    ?->equalTo($occurredAt)
            )
            ->toBeTrue();
    }
);

test(
    'popunder impression updates count budget and timestamp',
    function () {
        $state = makeMonetizationSessionStateForTest();

        $occurredAt = CarbonImmutable::parse(
            '2026-08-18 11:00:00 UTC'
        );

        $state->recordPopunderImpression(
            interruptionCost: 1,
            occurredAt: $occurredAt
        );

        expect($state->sessionPopunderCount())
            ->toBe(1)
            ->and(
                $state->consumedInterruptionBudget()
            )
            ->toBe(1)
            ->and(
                $state
                    ->lastPopunderAt()
                    ?->equalTo($occurredAt)
            )
            ->toBeTrue();
    }
);

test(
    'interstitial impression updates count budget and timestamp',
    function () {
        $state = makeMonetizationSessionStateForTest();

        $occurredAt = CarbonImmutable::parse(
            '2026-08-18 12:00:00 UTC'
        );

        $state->recordInterstitialImpression(
            interruptionCost: 1,
            occurredAt: $occurredAt
        );

        expect(
            $state->sessionInterstitialCount()
        )
            ->toBe(1)
            ->and(
                $state->consumedInterruptionBudget()
            )
            ->toBe(1)
            ->and(
                $state
                    ->lastInterstitialAt()
                    ?->equalTo($occurredAt)
            )
            ->toBeTrue();
    }
);

test(
    'non disruptive impression can use zero interruption cost',
    function () {
        $state = makeMonetizationSessionStateForTest();

        $state->recordPrerollImpression(
            interruptionCost: 0
        );

        expect($state->sessionPrerollCount())
            ->toBe(1)
            ->and(
                $state->consumedInterruptionBudget()
            )
            ->toBe(0);
    }
);

test(
    'interruption budget can be consumed directly',
    function () {
        $state = makeMonetizationSessionStateForTest();

        expect(
            $state->consumeInterruptionBudget(2)
        )
            ->toBe(2)
            ->and(
                $state->consumeInterruptionBudget(1)
            )
            ->toBe(3)
            ->and(
                $state->consumedInterruptionBudget()
            )
            ->toBe(3);
    }
);

test(
    'snapshot returns the current monetization session state',
    function () {
        $state = makeMonetizationSessionStateForTest();

        $sessionKey = $state->sessionKey();

        $state->recordMeaningfulInteraction(
            2
        );

        $state->recordPrerollImpression(
            interruptionCost: 1
        );

        $state->recordPopunderImpression(
            interruptionCost: 1
        );

        $snapshot = $state->snapshot();

        expect($snapshot['session_key'])
            ->toBe($sessionKey)
            ->and(
                $snapshot[
                    'meaningful_interaction_count'
                ]
            )
            ->toBe(2)
            ->and(
                $snapshot[
                    'session_preroll_count'
                ]
            )
            ->toBe(1)
            ->and(
                $snapshot[
                    'session_popunder_count'
                ]
            )
            ->toBe(1)
            ->and(
                $snapshot[
                    'session_interstitial_count'
                ]
            )
            ->toBe(0)
            ->and(
                $snapshot[
                    'consumed_interruption_budget'
                ]
            )
            ->toBe(2)
            ->and(
                $snapshot['last_preroll_at']
            )
            ->not->toBeNull()
            ->and(
                $snapshot['last_popunder_at']
            )
            ->not->toBeNull()
            ->and(
                $snapshot['last_interstitial_at']
            )
            ->toBeNull();
    }
);

test(
    'invalid stored timestamp fails safely',
    function () {
        $session = app(Session::class);

        $session->put(
            'monetization.last_preroll_at',
            'not-a-valid-date'
        );

        $state = new MonetizationSessionState(
            $session
        );

        expect($state->lastPrerollAt())
            ->toBeNull();
    }
);

test(
    'reset clears all monetization session state',
    function () {
        $state = makeMonetizationSessionStateForTest();

        $originalSessionKey =
            $state->sessionKey();

        $state->recordMeaningfulInteraction(
            3
        );

        $state->recordPrerollImpression();
        $state->recordPopunderImpression();
        $state->recordInterstitialImpression();

        $state->reset();

        expect(
            $state->meaningfulInteractionCount()
        )
            ->toBe(0)
            ->and(
                $state->sessionPrerollCount()
            )
            ->toBe(0)
            ->and(
                $state->sessionPopunderCount()
            )
            ->toBe(0)
            ->and(
                $state->sessionInterstitialCount()
            )
            ->toBe(0)
            ->and(
                $state->consumedInterruptionBudget()
            )
            ->toBe(0)
            ->and($state->lastPrerollAt())
            ->toBeNull()
            ->and($state->lastPopunderAt())
            ->toBeNull()
            ->and($state->lastInterstitialAt())
            ->toBeNull();

        $newSessionKey =
            $state->sessionKey();

        expect(Str::isUuid($newSessionKey))
            ->toBeTrue()
            ->and($newSessionKey)
            ->not->toBe($originalSessionKey);
    }
);

test(
    'counter increment rejects zero and negative amounts',
    function () {
        $state = makeMonetizationSessionStateForTest();

        expect(
            fn () =>
                $state->recordMeaningfulInteraction(
                    0
                )
        )->toThrow(
            \InvalidArgumentException::class,
            'Counter increment must be at least 1.'
        );
    }
);

test(
    'interruption budget rejects invalid amounts',
    function () {
        $state = makeMonetizationSessionStateForTest();

        expect(
            fn () =>
                $state->consumeInterruptionBudget(
                    -1
                )
        )->toThrow(
            \InvalidArgumentException::class,
            'Interruption budget amount must be '
            . 'between 0 and 65535.'
        );

        expect(
            fn () =>
                $state->consumeInterruptionBudget(
                    65536
                )
        )->toThrow(
            \InvalidArgumentException::class,
            'Interruption budget amount must be '
            . 'between 0 and 65535.'
        );
    }
);
