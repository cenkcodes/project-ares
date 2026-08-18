<?php

namespace App\Services\Monetization;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

class MonetizationSessionState
{
    private const KEY_SESSION_UUID =
        'monetization.session_uuid';

    private const KEY_MEANINGFUL_INTERACTIONS =
        'monetization.meaningful_interactions';

    private const KEY_PREROLL_COUNT =
        'monetization.preroll_count';

    private const KEY_POPUNDER_COUNT =
        'monetization.popunder_count';

    private const KEY_INTERSTITIAL_COUNT =
        'monetization.interstitial_count';

    private const KEY_INTERRUPTION_BUDGET =
        'monetization.interruption_budget_consumed';

    private const KEY_LAST_PREROLL_AT =
        'monetization.last_preroll_at';

    private const KEY_LAST_POPUNDER_AT =
        'monetization.last_popunder_at';

    private const KEY_LAST_INTERSTITIAL_AT =
        'monetization.last_interstitial_at';

    public function __construct(
        private readonly Session $session
    ) {
    }

    public function sessionKey(): string
    {
        $existing = $this->session->get(
            self::KEY_SESSION_UUID
        );

        if (
            is_string($existing)
            && Str::isUuid($existing)
        ) {
            return $existing;
        }

        $sessionKey = (string) Str::uuid();

        $this->session->put(
            self::KEY_SESSION_UUID,
            $sessionKey
        );

        return $sessionKey;
    }

    public function meaningfulInteractionCount(): int
    {
        return $this->counter(
            self::KEY_MEANINGFUL_INTERACTIONS
        );
    }

    public function recordMeaningfulInteraction(
        int $amount = 1
    ): int {
        return $this->incrementCounter(
            self::KEY_MEANINGFUL_INTERACTIONS,
            $amount
        );
    }

    public function sessionPrerollCount(): int
    {
        return $this->counter(
            self::KEY_PREROLL_COUNT
        );
    }

    public function sessionPopunderCount(): int
    {
        return $this->counter(
            self::KEY_POPUNDER_COUNT
        );
    }

    public function sessionInterstitialCount(): int
    {
        return $this->counter(
            self::KEY_INTERSTITIAL_COUNT
        );
    }

    public function consumedInterruptionBudget(): int
    {
        return $this->counter(
            self::KEY_INTERRUPTION_BUDGET
        );
    }

    public function lastPrerollAt(): ?CarbonImmutable
    {
        return $this->timestamp(
            self::KEY_LAST_PREROLL_AT
        );
    }

    public function lastPopunderAt(): ?CarbonImmutable
    {
        return $this->timestamp(
            self::KEY_LAST_POPUNDER_AT
        );
    }

    public function lastInterstitialAt(): ?CarbonImmutable
    {
        return $this->timestamp(
            self::KEY_LAST_INTERSTITIAL_AT
        );
    }

    public function recordPrerollImpression(
        int $interruptionCost = 1,
        ?CarbonInterface $occurredAt = null
    ): void {
        $this->incrementCounter(
            self::KEY_PREROLL_COUNT
        );

        $this->consumeInterruptionBudget(
            $interruptionCost
        );

        $this->storeTimestamp(
            self::KEY_LAST_PREROLL_AT,
            $occurredAt
        );
    }

    public function recordPopunderImpression(
        int $interruptionCost = 1,
        ?CarbonInterface $occurredAt = null
    ): void {
        $this->incrementCounter(
            self::KEY_POPUNDER_COUNT
        );

        $this->consumeInterruptionBudget(
            $interruptionCost
        );

        $this->storeTimestamp(
            self::KEY_LAST_POPUNDER_AT,
            $occurredAt
        );
    }

    public function recordInterstitialImpression(
        int $interruptionCost = 1,
        ?CarbonInterface $occurredAt = null
    ): void {
        $this->incrementCounter(
            self::KEY_INTERSTITIAL_COUNT
        );

        $this->consumeInterruptionBudget(
            $interruptionCost
        );

        $this->storeTimestamp(
            self::KEY_LAST_INTERSTITIAL_AT,
            $occurredAt
        );
    }

    public function consumeInterruptionBudget(
        int $amount = 1
    ): int {
        if ($amount < 0 || $amount > 65535) {
            throw new InvalidArgumentException(
                'Interruption budget amount must be '
                . 'between 0 and 65535.'
            );
        }

        if ($amount === 0) {
            return $this->consumedInterruptionBudget();
        }

        return $this->incrementCounter(
            self::KEY_INTERRUPTION_BUDGET,
            $amount
        );
    }

    public function snapshot(): array
    {
        return [
            'session_key' =>
                $this->sessionKey(),

            'meaningful_interaction_count' =>
                $this->meaningfulInteractionCount(),

            'session_preroll_count' =>
                $this->sessionPrerollCount(),

            'session_popunder_count' =>
                $this->sessionPopunderCount(),

            'session_interstitial_count' =>
                $this->sessionInterstitialCount(),

            'consumed_interruption_budget' =>
                $this->consumedInterruptionBudget(),

            'last_preroll_at' =>
                $this->lastPrerollAt(),

            'last_popunder_at' =>
                $this->lastPopunderAt(),

            'last_interstitial_at' =>
                $this->lastInterstitialAt(),
        ];
    }

    public function reset(): void
    {
        $this->session->forget([
            self::KEY_SESSION_UUID,
            self::KEY_MEANINGFUL_INTERACTIONS,
            self::KEY_PREROLL_COUNT,
            self::KEY_POPUNDER_COUNT,
            self::KEY_INTERSTITIAL_COUNT,
            self::KEY_INTERRUPTION_BUDGET,
            self::KEY_LAST_PREROLL_AT,
            self::KEY_LAST_POPUNDER_AT,
            self::KEY_LAST_INTERSTITIAL_AT,
        ]);
    }

    private function counter(
        string $key
    ): int {
        return max(
            0,
            (int) $this->session->get(
                $key,
                0
            )
        );
    }

    private function incrementCounter(
        string $key,
        int $amount = 1
    ): int {
        if ($amount < 1) {
            throw new InvalidArgumentException(
                'Counter increment must be '
                . 'at least 1.'
            );
        }

        $current = $this->counter(
            $key
        );

        $newValue = $current + $amount;

        $this->session->put(
            $key,
            $newValue
        );

        return $newValue;
    }

    private function storeTimestamp(
        string $key,
        ?CarbonInterface $occurredAt = null
    ): void {
        $timestamp = $occurredAt
            ? $occurredAt->toImmutable()
            : CarbonImmutable::now();

        $this->session->put(
            $key,
            $timestamp->toIso8601String()
        );
    }

    private function timestamp(
        string $key
    ): ?CarbonImmutable {
        $value = $this->session->get(
            $key
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
}
