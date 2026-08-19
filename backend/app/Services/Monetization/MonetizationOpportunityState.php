<?php

namespace App\Services\Monetization;

use App\Models\AdEvent;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

class MonetizationOpportunityState
{
    private const SESSION_KEY =
        'monetization.opportunities';

    private const MAX_OPPORTUNITIES =
        100;

    public const OPPORTUNITY_TTL_SECONDS =
        60;

    public function __construct(
        private readonly Session $session
    ) {
    }

    public function rememberDecision(
        array $decision,
        ?int $videoId,
        ?string $placementKey,
        bool $isMobile
    ): void {
        if (
            ($decision['show'] ?? false)
            !== true
        ) {
            return;
        }

        $opportunityUuid =
            $decision['opportunity_uuid']
            ?? null;

        $format =
            $decision['format']
            ?? null;

        $this->validateOpportunityUuid(
            $opportunityUuid
        );

        $this->validateFormat(
            $format
        );

        $opportunities =
            $this->opportunities();

        unset(
            $opportunities[
                $opportunityUuid
            ]
        );

        $opportunities[
            $opportunityUuid
        ] = [
            'opportunity_uuid' =>
                $opportunityUuid,

            'format' =>
                $format,

            'video_id' =>
                $videoId,

            'placement_key' =>
                $placementKey,

            'is_mobile' =>
                $isMobile,

            'claimed_events' =>
                [],

            'created_at' =>
                CarbonImmutable::now()
                    ->toIso8601String(),
        ];

        while (
            count($opportunities) >
            self::MAX_OPPORTUNITIES
        ) {
            array_shift(
                $opportunities
            );
        }

        $this->storeOpportunities(
            $opportunities
        );
    }

    public function claimEvent(
        string $opportunityUuid,
        string $eventType,
        string $format,
        ?int $videoId,
        ?string $placementKey,
        bool $isMobile
    ): array {
        $this->validateOpportunityUuid(
            $opportunityUuid
        );

        $this->validateEventType(
            $eventType
        );

        $this->validateFormat(
            $format
        );

        $opportunities =
            $this->opportunities();

        if (
            ! array_key_exists(
                $opportunityUuid,
                $opportunities
            )
        ) {
            throw new InvalidArgumentException(
                'Unknown or expired monetization opportunity.'
            );
        }

        $opportunity =
            $opportunities[
                $opportunityUuid
            ];

        $this->assertContextMatches(
            opportunity:
                $opportunity,

            format:
                $format,

            videoId:
                $videoId,

            placementKey:
                $placementKey,

            isMobile:
                $isMobile
        );

        $claimedEvents =
            $opportunity[
                'claimed_events'
            ] ?? [];

        if (! is_array($claimedEvents)) {
            throw new InvalidArgumentException(
                'Invalid monetization opportunity event state.'
            );
        }

        if (
            in_array(
                $eventType,
                $claimedEvents,
                true
            )
        ) {
            throw new InvalidArgumentException(
                'This monetization event has already been claimed.'
            );
        }

        $this->assertLifecycleAllowsEvent(
            format:
                $format,
            eventType:
                $eventType,
            claimedEvents:
                $claimedEvents
        );

        $claimedEvents[] =
            $eventType;

        $opportunity[
            'claimed_events'
        ] = $claimedEvents;

        $opportunities[
            $opportunityUuid
        ] = $opportunity;

        $this->storeOpportunities(
            $opportunities
        );

        return $opportunity;
    }

    public function opportunity(
        string $opportunityUuid
    ): ?array {
        $this->validateOpportunityUuid(
            $opportunityUuid
        );

        $opportunities =
            $this->opportunities();

        return $opportunities[
            $opportunityUuid
        ] ?? null;
    }

    public function count(): int
    {
        return count(
            $this->opportunities()
        );
    }

    public function reset(): void
    {
        $this->session->forget(
            self::SESSION_KEY
        );
    }

    private function opportunities(): array
    {
        $value = $this->session->get(
            self::SESSION_KEY,
            []
        );

        if (! is_array($value)) {
            $this->storeOpportunities(
                []
            );

            return [];
        }

        $filtered =
            $this->removeExpired(
                $value
            );

        if (
            count($filtered) !==
            count($value)
        ) {
            $this->storeOpportunities(
                $filtered
            );
        }

        return $filtered;
    }

    private function removeExpired(
        array $opportunities
    ): array {
        $now =
            CarbonImmutable::now();

        foreach (
            $opportunities
            as $uuid => $opportunity
        ) {
            if (
                ! is_array(
                    $opportunity
                ) ||
                $this->isExpired(
                    $opportunity,
                    $now
                )
            ) {
                unset(
                    $opportunities[
                        $uuid
                    ]
                );
            }
        }

        return $opportunities;
    }

    private function isExpired(
        array $opportunity,
        CarbonImmutable $now
    ): bool {
        $createdAt =
            $opportunity[
                'created_at'
            ] ?? null;

        if (
            ! is_string(
                $createdAt
            ) ||
            trim($createdAt) === ''
        ) {
            return true;
        }

        try {
            $created =
                CarbonImmutable::parse(
                    $createdAt
                );
        } catch (Throwable) {
            return true;
        }

        return $created
            ->addSeconds(
                self::OPPORTUNITY_TTL_SECONDS
            )
            ->lessThanOrEqualTo(
                $now
            );
    }

    private function storeOpportunities(
        array $opportunities
    ): void {
        $this->session->put(
            self::SESSION_KEY,
            $opportunities
        );
    }

    private function assertLifecycleAllowsEvent(
        string $format,
        string $eventType,
        array $claimedEvents
    ): void {
        $this->assertNoTerminalEventClaimed(
            $claimedEvents
        );

        if (
            $eventType ===
            AdEvent::EVENT_IMPRESSION
        ) {
            if ($claimedEvents !== []) {
                throw new InvalidArgumentException(
                    'Impression must be the first monetization event.'
                );
            }

            return;
        }

        if (
            $eventType ===
            AdEvent::EVENT_ERROR
        ) {
            return;
        }

        $hasImpression =
            in_array(
                AdEvent::EVENT_IMPRESSION,
                $claimedEvents,
                true
            );

        if (! $hasImpression) {
            throw new InvalidArgumentException(
                'Monetization event requires a prior impression.'
            );
        }

        if (
            $eventType ===
            AdEvent::EVENT_CLICK
        ) {
            return;
        }

        if (
            $eventType ===
            AdEvent::EVENT_SKIP
        ) {
            $this->assertSkipSupported(
                $format
            );

            return;
        }

        if (
            $eventType ===
            AdEvent::EVENT_CLOSE
        ) {
            $this->assertCloseSupported(
                $format
            );

            return;
        }

        throw new InvalidArgumentException(
            'Invalid monetization event lifecycle transition.'
        );
    }

    private function assertNoTerminalEventClaimed(
        array $claimedEvents
    ): void {
        $terminalEvents = [
            AdEvent::EVENT_SKIP,
            AdEvent::EVENT_CLOSE,
            AdEvent::EVENT_ERROR,
        ];

        foreach (
            $terminalEvents
            as $terminalEvent
        ) {
            if (
                in_array(
                    $terminalEvent,
                    $claimedEvents,
                    true
                )
            ) {
                throw new InvalidArgumentException(
                    'Monetization opportunity lifecycle is already complete.'
                );
            }
        }
    }

    private function assertSkipSupported(
        string $format
    ): void {
        if (
            ! in_array(
                $format,
                [
                    AdEvent::FORMAT_PREROLL,
                    AdEvent::FORMAT_MIDROLL,
                ],
                true
            )
        ) {
            throw new InvalidArgumentException(
                'Skip event is not supported for this monetization format.'
            );
        }
    }

    private function assertCloseSupported(
        string $format
    ): void {
        if (
            ! in_array(
                $format,
                [
                    AdEvent::FORMAT_PREROLL,
                    AdEvent::FORMAT_MIDROLL,
                    AdEvent::FORMAT_INTERSTITIAL,
                ],
                true
            )
        ) {
            throw new InvalidArgumentException(
                'Close event is not supported for this monetization format.'
            );
        }
    }

    private function assertContextMatches(
        array $opportunity,
        string $format,
        ?int $videoId,
        ?string $placementKey,
        bool $isMobile
    ): void {
        if (
            ($opportunity['format'] ?? null)
            !== $format
        ) {
            throw new InvalidArgumentException(
                'Monetization opportunity format mismatch.'
            );
        }

        if (
            ($opportunity['video_id'] ?? null)
            !== $videoId
        ) {
            throw new InvalidArgumentException(
                'Monetization opportunity video mismatch.'
            );
        }

        if (
            ($opportunity['placement_key'] ?? null)
            !== $placementKey
        ) {
            throw new InvalidArgumentException(
                'Monetization opportunity placement mismatch.'
            );
        }

        if (
            ($opportunity['is_mobile'] ?? null)
            !== $isMobile
        ) {
            throw new InvalidArgumentException(
                'Monetization opportunity device mismatch.'
            );
        }
    }

    private function validateOpportunityUuid(
        mixed $opportunityUuid
    ): void {
        if (
            ! is_string(
                $opportunityUuid
            ) ||
            ! Str::isUuid(
                $opportunityUuid
            )
        ) {
            throw new InvalidArgumentException(
                'Invalid monetization opportunity UUID.'
            );
        }
    }

    private function validateFormat(
        mixed $format
    ): void {
        if (
            ! is_string($format) ||
            ! in_array(
                $format,
                AdEvent::formats(),
                true
            )
        ) {
            throw new InvalidArgumentException(
                'Unsupported monetization format.'
            );
        }
    }

    private function validateEventType(
        string $eventType
    ): void {
        if (
            ! in_array(
                $eventType,
                [
                    AdEvent::EVENT_IMPRESSION,
                    AdEvent::EVENT_CLICK,
                    AdEvent::EVENT_SKIP,
                    AdEvent::EVENT_CLOSE,
                    AdEvent::EVENT_ERROR,
                ],
                true
            )
        ) {
            throw new InvalidArgumentException(
                'Unsupported monetization event type.'
            );
        }
    }
}
