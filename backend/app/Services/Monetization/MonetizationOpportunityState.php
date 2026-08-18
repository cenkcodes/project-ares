<?php

namespace App\Services\Monetization;

use App\Models\AdEvent;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Str;
use InvalidArgumentException;

class MonetizationOpportunityState
{
    private const SESSION_KEY =
        'monetization.opportunities';

    private const MAX_OPPORTUNITIES =
        100;

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

        /*
         * Re-inserting an existing UUID moves it
         * to the newest position and resets its
         * event claims.
         */
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
                now()->toIso8601String(),
        ];

        while (
            count($opportunities) >
            self::MAX_OPPORTUNITIES
        ) {
            array_shift(
                $opportunities
            );
        }

        $this->session->put(
            self::SESSION_KEY,
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
                'Unknown monetization opportunity.'
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

        $claimedEvents[] =
            $eventType;

        $opportunity[
            'claimed_events'
        ] = $claimedEvents;

        $opportunities[
            $opportunityUuid
        ] = $opportunity;

        $this->session->put(
            self::SESSION_KEY,
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
            return [];
        }

        return $value;
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
