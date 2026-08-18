<?php

use App\Services\Monetization\MonetizationFrequencyState;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::flush();
});

function makeMonetizationFrequencyStateForTest(): MonetizationFrequencyState
{
    return new MonetizationFrequencyState(
        app('cache.store')
    );
}

test(
    'frequency state stores preroll popunder and interstitial timestamps',
    function () {
        $state = makeMonetizationFrequencyStateForTest();

        $visitorKey = 'visitor-a';

        $prerollAt = CarbonImmutable::parse(
            '2026-08-18 10:00:00 UTC'
        );

        $popunderAt = CarbonImmutable::parse(
            '2026-08-18 11:00:00 UTC'
        );

        $interstitialAt = CarbonImmutable::parse(
            '2026-08-18 12:00:00 UTC'
        );

        $state->recordPrerollImpression(
            visitorKey: $visitorKey,
            occurredAt: $prerollAt
        );

        $state->recordPopunderImpression(
            visitorKey: $visitorKey,
            occurredAt: $popunderAt
        );

        $state->recordInterstitialImpression(
            visitorKey: $visitorKey,
            occurredAt: $interstitialAt
        );

        expect(
            $state
                ->lastPrerollAt($visitorKey)
                ?->equalTo($prerollAt)
        )
            ->toBeTrue()
            ->and(
                $state
                    ->lastPopunderAt($visitorKey)
                    ?->equalTo($popunderAt)
            )
            ->toBeTrue()
            ->and(
                $state
                    ->lastInterstitialAt($visitorKey)
                    ?->equalTo($interstitialAt)
            )
            ->toBeTrue();
    }
);

test(
    'frequency state isolates visitors',
    function () {
        $state = makeMonetizationFrequencyStateForTest();

        $visitorA = 'visitor-a';
        $visitorB = 'visitor-b';

        $occurredAt = CarbonImmutable::parse(
            '2026-08-18 10:00:00 UTC'
        );

        $state->recordPopunderImpression(
            visitorKey: $visitorA,
            occurredAt: $occurredAt
        );

        expect(
            $state->lastPopunderAt($visitorA)
        )
            ->not->toBeNull()
            ->and(
                $state->lastPopunderAt($visitorB)
            )
            ->toBeNull()
            ->and(
                $state->dailyPopunderCount(
                    visitorKey: $visitorA,
                    now: $occurredAt
                )
            )
            ->toBe(1)
            ->and(
                $state->dailyPopunderCount(
                    visitorKey: $visitorB,
                    now: $occurredAt
                )
            )
            ->toBe(0);
    }
);

test(
    'daily popunder counter increments during the same utc day',
    function () {
        $state = makeMonetizationFrequencyStateForTest();

        $visitorKey = 'visitor-counter';

        $first = CarbonImmutable::parse(
            '2026-08-18 08:00:00 UTC'
        );

        $second = CarbonImmutable::parse(
            '2026-08-18 20:00:00 UTC'
        );

        $firstCount =
            $state->recordPopunderImpression(
                visitorKey: $visitorKey,
                occurredAt: $first
            );

        $secondCount =
            $state->recordPopunderImpression(
                visitorKey: $visitorKey,
                occurredAt: $second
            );

        expect($firstCount)
            ->toBe(1)
            ->and($secondCount)
            ->toBe(2)
            ->and(
                $state->dailyPopunderCount(
                    visitorKey: $visitorKey,
                    now: $second
                )
            )
            ->toBe(2);
    }
);

test(
    'daily popunder counter starts fresh on the next utc day',
    function () {
        $state = makeMonetizationFrequencyStateForTest();

        $visitorKey = 'visitor-next-day';

        $dayOne = CarbonImmutable::parse(
            '2026-08-18 23:30:00 UTC'
        );

        $dayTwo = CarbonImmutable::parse(
            '2026-08-19 00:30:00 UTC'
        );

        $state->recordPopunderImpression(
            visitorKey: $visitorKey,
            occurredAt: $dayOne
        );

        expect(
            $state->dailyPopunderCount(
                visitorKey: $visitorKey,
                now: $dayOne
            )
        )
            ->toBe(1)
            ->and(
                $state->dailyPopunderCount(
                    visitorKey: $visitorKey,
                    now: $dayTwo
                )
            )
            ->toBe(0);
    }
);

test(
    'daily counter uses utc day boundary',
    function () {
        $state = makeMonetizationFrequencyStateForTest();

        $visitorKey = 'visitor-utc-boundary';

        /*
         * Local date is August 18,
         * but UTC date is already August 19.
         */
        $occurredAt = CarbonImmutable::parse(
            '2026-08-18 23:30:00 -02:00'
        );

        $sameUtcDay = CarbonImmutable::parse(
            '2026-08-19 02:00:00 UTC'
        );

        $previousUtcDay = CarbonImmutable::parse(
            '2026-08-18 23:59:00 UTC'
        );

        $state->recordPopunderImpression(
            visitorKey: $visitorKey,
            occurredAt: $occurredAt
        );

        expect(
            $state->dailyPopunderCount(
                visitorKey: $visitorKey,
                now: $sameUtcDay
            )
        )
            ->toBe(1)
            ->and(
                $state->dailyPopunderCount(
                    visitorKey: $visitorKey,
                    now: $previousUtcDay
                )
            )
            ->toBe(0);
    }
);

test(
    'latest impression replaces previous last shown timestamp',
    function () {
        $state = makeMonetizationFrequencyStateForTest();

        $visitorKey = 'visitor-latest';

        $first = CarbonImmutable::parse(
            '2026-08-18 09:00:00 UTC'
        );

        $second = CarbonImmutable::parse(
            '2026-08-18 15:00:00 UTC'
        );

        $state->recordPrerollImpression(
            visitorKey: $visitorKey,
            occurredAt: $first
        );

        $state->recordPrerollImpression(
            visitorKey: $visitorKey,
            occurredAt: $second
        );

        expect(
            $state
                ->lastPrerollAt($visitorKey)
                ?->equalTo($second)
        )->toBeTrue();
    }
);

test(
    'invalid stored timestamp fails safely',
    function () {
        $state = makeMonetizationFrequencyStateForTest();

        $visitorKey = 'visitor-invalid-date';

        $cacheKey =
            'xurvexa:monetization:frequency:'
            . hash('sha256', $visitorKey)
            . ':preroll:last_shown_at';

        app('cache.store')->put(
            $cacheKey,
            'not-a-valid-date',
            3600
        );

        expect(
            $state->lastPrerollAt(
                $visitorKey
            )
        )->toBeNull();
    }
);

test(
    'reset visitor clears last shown timestamps',
    function () {
        $state = makeMonetizationFrequencyStateForTest();

        $visitorKey = 'visitor-reset';

        $occurredAt = CarbonImmutable::parse(
            '2026-08-18 10:00:00 UTC'
        );

        $state->recordPrerollImpression(
            visitorKey: $visitorKey,
            occurredAt: $occurredAt
        );

        $state->recordPopunderImpression(
            visitorKey: $visitorKey,
            occurredAt: $occurredAt
        );

        $state->recordInterstitialImpression(
            visitorKey: $visitorKey,
            occurredAt: $occurredAt
        );

        $state->resetVisitor(
            $visitorKey
        );

        expect(
            $state->lastPrerollAt($visitorKey)
        )
            ->toBeNull()
            ->and(
                $state->lastPopunderAt($visitorKey)
            )
            ->toBeNull()
            ->and(
                $state->lastInterstitialAt(
                    $visitorKey
                )
            )
            ->toBeNull();
    }
);

test(
    'reset visitor does not bypass the current daily popunder counter',
    function () {
        $state = makeMonetizationFrequencyStateForTest();

        $visitorKey = 'visitor-daily-reset';

        $occurredAt = CarbonImmutable::parse(
            '2026-08-18 10:00:00 UTC'
        );

        $state->recordPopunderImpression(
            visitorKey: $visitorKey,
            occurredAt: $occurredAt
        );

        $state->resetVisitor(
            $visitorKey
        );

        expect(
            $state->dailyPopunderCount(
                visitorKey: $visitorKey,
                now: $occurredAt
            )
        )->toBe(1);
    }
);

test(
    'blank visitor key is rejected',
    function () {
        $state = makeMonetizationFrequencyStateForTest();

        expect(
            fn () =>
                $state->lastPopunderAt('   ')
        )->toThrow(
            \InvalidArgumentException::class,
            'Visitor key must contain between '
            . '1 and 128 characters.'
        );
    }
);

test(
    'visitor key longer than 128 characters is rejected',
    function () {
        $state = makeMonetizationFrequencyStateForTest();

        $visitorKey = str_repeat(
            'a',
            129
        );

        expect(
            fn () =>
                $state->lastPopunderAt(
                    $visitorKey
                )
        )->toThrow(
            \InvalidArgumentException::class,
            'Visitor key must contain between '
            . '1 and 128 characters.'
        );
    }
);

test(
    'visitor token is not exposed directly in cache keys',
    function () {
        $cache = app('cache.store');

        expect($cache)
            ->toBeInstanceOf(
                Repository::class
            );

        $state = new MonetizationFrequencyState(
            $cache
        );

        $visitorKey =
            'anonymous-sensitive-visitor-token';

        $occurredAt = CarbonImmutable::parse(
            '2026-08-18 10:00:00 UTC'
        );

        $state->recordPrerollImpression(
            visitorKey: $visitorKey,
            occurredAt: $occurredAt
        );

        $hashedKey =
            'xurvexa:monetization:frequency:'
            . hash('sha256', $visitorKey)
            . ':preroll:last_shown_at';

        expect(
            $cache->has($hashedKey)
        )->toBeTrue();
    }
);
