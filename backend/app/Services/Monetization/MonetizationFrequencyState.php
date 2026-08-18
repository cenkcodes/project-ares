<?php

namespace App\Services\Monetization;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Cache\Repository;
use InvalidArgumentException;
use Throwable;

class MonetizationFrequencyState
{
    /*
     * Current admin limits allow frequency windows
     * up to 365 days. Keep cached state slightly
     * longer than that maximum.
     */
    private const STATE_TTL_DAYS = 370;

    private const PREFIX =
        'xurvexa:monetization:frequency:';

    public function __construct(
        private readonly Repository $cache
    ) {
    }

    public function lastPrerollAt(
        string $visitorKey
    ): ?CarbonImmutable {
        return $this->lastShownAt(
            visitorKey: $visitorKey,
            format: 'preroll'
        );
    }

    public function lastPopunderAt(
        string $visitorKey
    ): ?CarbonImmutable {
        return $this->lastShownAt(
            visitorKey: $visitorKey,
            format: 'popunder'
        );
    }

    public function lastInterstitialAt(
        string $visitorKey
    ): ?CarbonImmutable {
        return $this->lastShownAt(
            visitorKey: $visitorKey,
            format: 'interstitial'
        );
    }

    public function dailyPopunderCount(
        string $visitorKey,
        ?CarbonInterface $now = null
    ): int {
        $currentTime = $now?->toImmutable()
            ?? CarbonImmutable::now();

        $key = $this->dailyCounterKey(
            visitorKey: $visitorKey,
            format: 'popunder',
            now: $currentTime
        );

        return max(
            0,
            (int) $this->cache->get(
                $key,
                0
            )
        );
    }

    public function recordPrerollImpression(
        string $visitorKey,
        ?CarbonInterface $occurredAt = null
    ): void {
        $this->recordShownAt(
            visitorKey: $visitorKey,
            format: 'preroll',
            occurredAt: $occurredAt
        );
    }

    public function recordPopunderImpression(
        string $visitorKey,
        ?CarbonInterface $occurredAt = null
    ): int {
        $timestamp = $occurredAt?->toImmutable()
            ?? CarbonImmutable::now();

        $this->recordShownAt(
            visitorKey: $visitorKey,
            format: 'popunder',
            occurredAt: $timestamp
        );

        return $this->incrementDailyCounter(
            visitorKey: $visitorKey,
            format: 'popunder',
            now: $timestamp
        );
    }

    public function recordInterstitialImpression(
        string $visitorKey,
        ?CarbonInterface $occurredAt = null
    ): void {
        $this->recordShownAt(
            visitorKey: $visitorKey,
            format: 'interstitial',
            occurredAt: $occurredAt
        );
    }

    public function resetVisitor(
        string $visitorKey
    ): void {
        $visitorHash = $this->visitorHash(
            $visitorKey
        );

        foreach (
            [
                'preroll',
                'popunder',
                'interstitial',
            ] as $format
        ) {
            $this->cache->forget(
                $this->lastShownKeyFromHash(
                    visitorHash: $visitorHash,
                    format: $format
                )
            );
        }

        /*
         * Daily counters use date-specific keys.
         * They expire automatically and therefore
         * do not need broad cache scans here.
         */
    }

    private function lastShownAt(
        string $visitorKey,
        string $format
    ): ?CarbonImmutable {
        $value = $this->cache->get(
            $this->lastShownKey(
                visitorKey: $visitorKey,
                format: $format
            )
        );

        if (
            ! is_string($value)
            || $value === ''
        ) {
            return null;
        }

        try {
            return CarbonImmutable::parse(
                $value
            );
        } catch (Throwable) {
            return null;
        }
    }

    private function recordShownAt(
        string $visitorKey,
        string $format,
        ?CarbonInterface $occurredAt = null
    ): void {
        $timestamp = $occurredAt?->toImmutable()
            ?? CarbonImmutable::now();

        $this->cache->put(
            $this->lastShownKey(
                visitorKey: $visitorKey,
                format: $format
            ),
            $timestamp->toIso8601String(),
            CarbonImmutable::now()
                ->addDays(
                    self::STATE_TTL_DAYS
                )
        );
    }

    private function incrementDailyCounter(
        string $visitorKey,
        string $format,
        CarbonImmutable $now
    ): int {
        $key = $this->dailyCounterKey(
            visitorKey: $visitorKey,
            format: $format,
            now: $now
        );

        $current = max(
            0,
            (int) $this->cache->get(
                $key,
                0
            )
        );

        $newValue = $current + 1;

        /*
         * Keep today's counter until slightly after
         * the end of the UTC day.
         */
        $expiresAt = $now
            ->utc()
            ->endOfDay()
            ->addHour();

        $this->cache->put(
            $key,
            $newValue,
            $expiresAt
        );

        return $newValue;
    }

    private function dailyCounterKey(
        string $visitorKey,
        string $format,
        CarbonInterface $now
    ): string {
        return self::PREFIX
            . $this->visitorHash($visitorKey)
            . ':'
            . $format
            . ':daily:'
            . $now->toImmutable()
                ->utc()
                ->format('Y-m-d');
    }

    private function lastShownKey(
        string $visitorKey,
        string $format
    ): string {
        return $this->lastShownKeyFromHash(
            visitorHash:
                $this->visitorHash(
                    $visitorKey
                ),
            format: $format
        );
    }

    private function lastShownKeyFromHash(
        string $visitorHash,
        string $format
    ): string {
        return self::PREFIX
            . $visitorHash
            . ':'
            . $format
            . ':last_shown_at';
    }

    private function visitorHash(
        string $visitorKey
    ): string {
        $visitorKey = trim(
            $visitorKey
        );

        if (
            $visitorKey === ''
            || strlen($visitorKey) > 128
        ) {
            throw new InvalidArgumentException(
                'Visitor key must contain between '
                . '1 and 128 characters.'
            );
        }

        /*
         * Do not expose the anonymous visitor token
         * directly inside cache keys.
         */
        return hash(
            'sha256',
            $visitorKey
        );
    }
}
