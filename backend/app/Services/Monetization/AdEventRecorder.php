<?php

namespace App\Services\Monetization;

use App\Models\AdEvent;
use App\Models\MonetizationSetting;
use App\Models\Video;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;
use InvalidArgumentException;

class AdEventRecorder
{
    public function newOpportunityUuid(): string
    {
        return (string) Str::uuid();
    }

    public function recordDecision(
        array $decision,
        ?Video $video = null,
        ?string $opportunityUuid = null,
        ?string $placementKey = null,
        ?string $sessionKey = null,
        ?string $deviceType = null,
        array $metadata = [],
        ?CarbonInterface $occurredAt = null
    ): ?AdEvent {
        $format = $decision['format'] ?? null;

        if (! is_string($format) || $format === '') {
            throw new InvalidArgumentException(
                'Ad decision must contain a valid format.'
            );
        }

        $show = (bool) ($decision['show'] ?? false);

        $decisionMetadata = $decision['metadata'] ?? [];

        if (! is_array($decisionMetadata)) {
            $decisionMetadata = [];
        }

        return $this->record([
            'opportunity_uuid' => $opportunityUuid,
            'video_id' => $video?->id,
            'provider_slug' => $video?->video_source,
            'format' => $format,
            'event_type' => AdEvent::EVENT_DECISION,
            'decision_outcome' => $show
                ? AdEvent::OUTCOME_SHOW
                : AdEvent::OUTCOME_SKIP,
            'decision_reason' => $decision['reason'] ?? null,
            'placement_key' => $placementKey,
            'session_key' => $sessionKey,
            'device_type' => $deviceType,
            'metadata' => array_merge(
                $decisionMetadata,
                $metadata
            ),
            'occurred_at' => $occurredAt,
        ]);
    }

    public function recordImpression(
        string $format,
        ?Video $video = null,
        ?string $opportunityUuid = null,
        ?string $placementKey = null,
        ?string $sessionKey = null,
        ?string $deviceType = null,
        int $interruptionCost = 0,
        ?string $adNetwork = null,
        ?string $campaignKey = null,
        ?int $revenueMicros = null,
        ?string $currency = null,
        array $metadata = [],
        ?CarbonInterface $occurredAt = null
    ): ?AdEvent {
        return $this->record([
            'opportunity_uuid' => $opportunityUuid,
            'video_id' => $video?->id,
            'provider_slug' => $video?->video_source,
            'format' => $format,
            'event_type' => AdEvent::EVENT_IMPRESSION,
            'placement_key' => $placementKey,
            'ad_network' => $adNetwork,
            'campaign_key' => $campaignKey,
            'session_key' => $sessionKey,
            'device_type' => $deviceType,
            'interruption_cost' => $interruptionCost,
            'revenue_micros' => $revenueMicros,
            'currency' => $currency,
            'metadata' => $metadata,
            'occurred_at' => $occurredAt,
        ]);
    }

    public function recordClick(
        string $format,
        ?Video $video = null,
        ?string $opportunityUuid = null,
        ?string $placementKey = null,
        ?string $sessionKey = null,
        ?string $deviceType = null,
        ?string $adNetwork = null,
        ?string $campaignKey = null,
        array $metadata = [],
        ?CarbonInterface $occurredAt = null
    ): ?AdEvent {
        return $this->recordInteractionEvent(
            eventType: AdEvent::EVENT_CLICK,
            format: $format,
            video: $video,
            opportunityUuid: $opportunityUuid,
            placementKey: $placementKey,
            sessionKey: $sessionKey,
            deviceType: $deviceType,
            adNetwork: $adNetwork,
            campaignKey: $campaignKey,
            metadata: $metadata,
            occurredAt: $occurredAt
        );
    }

    public function recordSkip(
        string $format,
        ?Video $video = null,
        ?string $opportunityUuid = null,
        ?string $placementKey = null,
        ?string $sessionKey = null,
        ?string $deviceType = null,
        ?string $adNetwork = null,
        ?string $campaignKey = null,
        array $metadata = [],
        ?CarbonInterface $occurredAt = null
    ): ?AdEvent {
        return $this->recordInteractionEvent(
            eventType: AdEvent::EVENT_SKIP,
            format: $format,
            video: $video,
            opportunityUuid: $opportunityUuid,
            placementKey: $placementKey,
            sessionKey: $sessionKey,
            deviceType: $deviceType,
            adNetwork: $adNetwork,
            campaignKey: $campaignKey,
            metadata: $metadata,
            occurredAt: $occurredAt
        );
    }

    public function recordClose(
        string $format,
        ?Video $video = null,
        ?string $opportunityUuid = null,
        ?string $placementKey = null,
        ?string $sessionKey = null,
        ?string $deviceType = null,
        ?string $adNetwork = null,
        ?string $campaignKey = null,
        array $metadata = [],
        ?CarbonInterface $occurredAt = null
    ): ?AdEvent {
        return $this->recordInteractionEvent(
            eventType: AdEvent::EVENT_CLOSE,
            format: $format,
            video: $video,
            opportunityUuid: $opportunityUuid,
            placementKey: $placementKey,
            sessionKey: $sessionKey,
            deviceType: $deviceType,
            adNetwork: $adNetwork,
            campaignKey: $campaignKey,
            metadata: $metadata,
            occurredAt: $occurredAt
        );
    }

    public function recordError(
        string $format,
        string $errorReason,
        ?Video $video = null,
        ?string $opportunityUuid = null,
        ?string $placementKey = null,
        ?string $sessionKey = null,
        ?string $deviceType = null,
        ?string $adNetwork = null,
        ?string $campaignKey = null,
        array $metadata = [],
        ?CarbonInterface $occurredAt = null
    ): ?AdEvent {
        return $this->record([
            'opportunity_uuid' => $opportunityUuid,
            'video_id' => $video?->id,
            'provider_slug' => $video?->video_source,
            'format' => $format,
            'event_type' => AdEvent::EVENT_ERROR,
            'decision_reason' => $errorReason,
            'placement_key' => $placementKey,
            'ad_network' => $adNetwork,
            'campaign_key' => $campaignKey,
            'session_key' => $sessionKey,
            'device_type' => $deviceType,
            'metadata' => $metadata,
            'occurred_at' => $occurredAt,
        ]);
    }

    public function record(array $attributes): ?AdEvent
    {
        if (! $this->trackingIsEnabled()) {
            return null;
        }

        $this->validateAttributes(
            $attributes
        );

        if (
            isset($attributes['event_uuid'])
            && filled($attributes['event_uuid'])
        ) {
            return AdEvent::firstOrCreate(
                [
                    'event_uuid' =>
                        $attributes['event_uuid'],
                ],
                $attributes
            );
        }

        return AdEvent::create(
            $attributes
        );
    }

    private function recordInteractionEvent(
        string $eventType,
        string $format,
        ?Video $video,
        ?string $opportunityUuid,
        ?string $placementKey,
        ?string $sessionKey,
        ?string $deviceType,
        ?string $adNetwork,
        ?string $campaignKey,
        array $metadata,
        ?CarbonInterface $occurredAt
    ): ?AdEvent {
        return $this->record([
            'opportunity_uuid' => $opportunityUuid,
            'video_id' => $video?->id,
            'provider_slug' => $video?->video_source,
            'format' => $format,
            'event_type' => $eventType,
            'placement_key' => $placementKey,
            'ad_network' => $adNetwork,
            'campaign_key' => $campaignKey,
            'session_key' => $sessionKey,
            'device_type' => $deviceType,
            'metadata' => $metadata,
            'occurred_at' => $occurredAt,
        ]);
    }

    private function trackingIsEnabled(): bool
    {
        $settings = MonetizationSetting::global();

        if ($settings === null) {
            return false;
        }

        return $settings->shouldTrackAdEvents();
    }

    private function validateAttributes(
        array $attributes
    ): void {
        $format = $attributes['format'] ?? null;
        $eventType = $attributes['event_type'] ?? null;

        if (
            ! is_string($format)
            || ! in_array(
                $format,
                AdEvent::formats(),
                true
            )
        ) {
            throw new InvalidArgumentException(
                'Unsupported ad format.'
            );
        }

        if (
            ! is_string($eventType)
            || ! in_array(
                $eventType,
                AdEvent::eventTypes(),
                true
            )
        ) {
            throw new InvalidArgumentException(
                'Unsupported ad event type.'
            );
        }

        $decisionOutcome =
            $attributes['decision_outcome'] ?? null;

        if (
            $decisionOutcome !== null
            && ! in_array(
                $decisionOutcome,
                AdEvent::decisionOutcomes(),
                true
            )
        ) {
            throw new InvalidArgumentException(
                'Unsupported ad decision outcome.'
            );
        }

        $deviceType =
            $attributes['device_type'] ?? null;

        if (
            $deviceType !== null
            && ! in_array(
                $deviceType,
                AdEvent::deviceTypes(),
                true
            )
        ) {
            throw new InvalidArgumentException(
                'Unsupported ad device type.'
            );
        }

        $interruptionCost =
            $attributes['interruption_cost'] ?? 0;

        if (
            ! is_int($interruptionCost)
            || $interruptionCost < 0
            || $interruptionCost > 65535
        ) {
            throw new InvalidArgumentException(
                'Interruption cost must be an integer '
                . 'between 0 and 65535.'
            );
        }

        $revenueMicros =
            $attributes['revenue_micros'] ?? null;

        if (
            $revenueMicros !== null
            && (
                ! is_int($revenueMicros)
                || $revenueMicros < 0
            )
        ) {
            throw new InvalidArgumentException(
                'Revenue micros must be a '
                . 'non-negative integer.'
            );
        }

        $currency =
            $attributes['currency'] ?? null;

        if (
            $currency !== null
            && (
                ! is_string($currency)
                || strlen($currency) !== 3
            )
        ) {
            throw new InvalidArgumentException(
                'Currency must be a 3-character code.'
            );
        }
    }
}
