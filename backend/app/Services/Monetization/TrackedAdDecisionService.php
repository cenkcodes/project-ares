<?php

namespace App\Services\Monetization;

use App\Models\AdEvent;
use App\Models\Video;
use App\Models\VideoProvider;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use InvalidArgumentException;

class TrackedAdDecisionService
{
    private ?string $resolvedVisitorKey = null;

    public function __construct(
        private readonly AdDecisionEngine $decisionEngine,
        private readonly AdEventRecorder $eventRecorder,
        private readonly MonetizationSessionState $sessionState,
        private readonly MonetizationFrequencyState $frequencyState,
        private readonly AnonymousVisitorIdentity $visitorIdentity,
        private readonly AdPlacementSelector $placementSelector,
        private readonly Request $request
    ) {
    }

    public function providerForVideo(
        Video $video
    ): ?VideoProvider {
        return $this->decisionEngine
            ->providerForVideo($video);
    }

    public function recordMeaningfulInteraction(
        int $amount = 1
    ): int {
        return $this->sessionState
            ->recordMeaningfulInteraction(
                $amount
            );
    }

    public function sessionSnapshot(): array
    {
        return $this->sessionState
            ->snapshot();
    }

    public function decideNative(
        ?Video $video,
        bool $isMobile,
        ?string $placementKey = null
    ): array {
        $provider = $video !== null
            ? $this->providerForVideo($video)
            : null;

        $decision = $this->decisionEngine
            ->decideNative(
                provider: $provider,
                isMobile: $isMobile
            );

        $decision =
            $this->applyPlacementSelection(
                decision: $decision,
                placementKey: $placementKey,
                isMobile: $isMobile
            );

        return $this->trackDecision(
            decision: $decision,
            video: $video,
            isMobile: $isMobile,
            placementKey: $placementKey
        );
    }

    public function decideBanner(
        ?Video $video,
        bool $isMobile,
        ?string $placementKey = null
    ): array {
        $provider = $video !== null
            ? $this->providerForVideo($video)
            : null;

        $decision = $this->decisionEngine
            ->decideBanner(
                provider: $provider,
                isMobile: $isMobile
            );

        $decision =
            $this->applyPlacementSelection(
                decision: $decision,
                placementKey: $placementKey,
                isMobile: $isMobile
            );

        return $this->trackDecision(
            decision: $decision,
            video: $video,
            isMobile: $isMobile,
            placementKey: $placementKey
        );
    }

    public function decidePreroll(
        Video $video,
        bool $isMobile,
        ?CarbonInterface $now = null,
        ?string $placementKey = null
    ): array {
        $provider = $this->providerForVideo(
            $video
        );

        if ($provider === null) {
            return $this->trackMissingProviderDecision(
                format: AdDecisionEngine::FORMAT_PREROLL,
                video: $video,
                isMobile: $isMobile,
                placementKey: $placementKey
            );
        }

        $visitorKey = $this->visitorKey();

        $decision = $this->decisionEngine
            ->decidePreroll(
                provider: $provider,
                isMobile: $isMobile,
                videoInteractionNumber:
                    max(
                        1,
                        $this->sessionState
                            ->meaningfulInteractionCount()
                    ),
                sessionPrerollCount:
                    $this->sessionState
                        ->sessionPrerollCount(),
                consumedInterruptionBudget:
                    $this->sessionState
                        ->consumedInterruptionBudget(),
                lastPrerollAt:
                    $this->frequencyState
                        ->lastPrerollAt(
                            $visitorKey
                        ),
                now: $now
            );

        $decision =
            $this->applyPlacementSelection(
                decision: $decision,
                placementKey: $placementKey,
                isMobile: $isMobile
            );

        return $this->trackDecision(
            decision: $decision,
            video: $video,
            isMobile: $isMobile,
            placementKey: $placementKey
        );
    }

    public function decideMidroll(
        Video $video,
        bool $isMobile,
        ?string $placementKey = null
    ): array {
        $provider = $this->providerForVideo(
            $video
        );

        if ($provider === null) {
            return $this->trackMissingProviderDecision(
                format: AdDecisionEngine::FORMAT_MIDROLL,
                video: $video,
                isMobile: $isMobile,
                placementKey: $placementKey
            );
        }

        $decision = $this->decisionEngine
            ->decideMidroll(
                provider: $provider,
                isMobile: $isMobile
            );

        $decision =
            $this->applyPlacementSelection(
                decision: $decision,
                placementKey: $placementKey,
                isMobile: $isMobile
            );

        return $this->trackDecision(
            decision: $decision,
            video: $video,
            isMobile: $isMobile,
            placementKey: $placementKey
        );
    }

    public function decidePopunder(
        Video $video,
        bool $isMobile,
        ?CarbonInterface $now = null,
        ?string $placementKey = null
    ): array {
        $provider = $this->providerForVideo(
            $video
        );

        if ($provider === null) {
            return $this->trackMissingProviderDecision(
                format: AdDecisionEngine::FORMAT_POPUNDER,
                video: $video,
                isMobile: $isMobile,
                placementKey: $placementKey
            );
        }

        $visitorKey = $this->visitorKey();

        $decision = $this->decisionEngine
            ->decidePopunder(
                provider: $provider,
                isMobile: $isMobile,
                meaningfulInteractionCount:
                    $this->sessionState
                        ->meaningfulInteractionCount(),
                sessionPopunderCount:
                    $this->sessionState
                        ->sessionPopunderCount(),
                dailyPopunderCount:
                    $this->frequencyState
                        ->dailyPopunderCount(
                            visitorKey: $visitorKey,
                            now: $now
                        ),
                consumedInterruptionBudget:
                    $this->sessionState
                        ->consumedInterruptionBudget(),
                lastPopunderAt:
                    $this->frequencyState
                        ->lastPopunderAt(
                            $visitorKey
                        ),
                now: $now
            );

        $decision =
            $this->applyPlacementSelection(
                decision: $decision,
                placementKey: $placementKey,
                isMobile: $isMobile
            );

        return $this->trackDecision(
            decision: $decision,
            video: $video,
            isMobile: $isMobile,
            placementKey: $placementKey
        );
    }

    public function decideInterstitial(
        Video $video,
        bool $isMobile,
        ?CarbonInterface $now = null,
        ?string $placementKey = null
    ): array {
        $provider = $this->providerForVideo(
            $video
        );

        if ($provider === null) {
            return $this->trackMissingProviderDecision(
                format:
                    AdDecisionEngine::FORMAT_INTERSTITIAL,
                video: $video,
                isMobile: $isMobile,
                placementKey: $placementKey
            );
        }

        $visitorKey = $this->visitorKey();

        $decision = $this->decisionEngine
            ->decideInterstitial(
                provider: $provider,
                isMobile: $isMobile,
                meaningfulInteractionCount:
                    $this->sessionState
                        ->meaningfulInteractionCount(),
                sessionInterstitialCount:
                    $this->sessionState
                        ->sessionInterstitialCount(),
                consumedInterruptionBudget:
                    $this->sessionState
                        ->consumedInterruptionBudget(),
                lastInterstitialAt:
                    $this->frequencyState
                        ->lastInterstitialAt(
                            $visitorKey
                        ),
                now: $now
            );

        $decision =
            $this->applyPlacementSelection(
                decision: $decision,
                placementKey: $placementKey,
                isMobile: $isMobile
            );

        return $this->trackDecision(
            decision: $decision,
            video: $video,
            isMobile: $isMobile,
            placementKey: $placementKey
        );
    }

    public function recordImpression(
        string $format,
        ?Video $video,
        string $opportunityUuid,
        bool $isMobile,
        ?string $placementKey = null,
        int $interruptionCost = 0,
        ?string $adNetwork = null,
        ?string $campaignKey = null,
        ?int $revenueMicros = null,
        ?string $currency = null,
        array $metadata = [],
        ?CarbonInterface $occurredAt = null
    ): ?AdEvent {
        $this->validateFormat(
            $format
        );

        $eventTime = $occurredAt
            ? $occurredAt->toImmutable()
            : CarbonImmutable::now();

        $this->recordImpressionState(
            format: $format,
            interruptionCost: $interruptionCost,
            occurredAt: $eventTime
        );

        return $this->eventRecorder
            ->recordImpression(
                format: $format,
                video: $video,
                opportunityUuid:
                    $opportunityUuid,
                placementKey:
                    $placementKey,
                sessionKey:
                    $this->sessionState
                        ->sessionKey(),
                deviceType:
                    $this->deviceType(
                        $isMobile
                    ),
                interruptionCost:
                    $interruptionCost,
                adNetwork:
                    $adNetwork,
                campaignKey:
                    $campaignKey,
                revenueMicros:
                    $revenueMicros,
                currency:
                    $currency,
                metadata:
                    $metadata,
                occurredAt:
                    $eventTime
            );
    }

    public function recordClick(
        string $format,
        ?Video $video,
        string $opportunityUuid,
        bool $isMobile,
        ?string $placementKey = null,
        ?string $adNetwork = null,
        ?string $campaignKey = null,
        array $metadata = [],
        ?CarbonInterface $occurredAt = null
    ): ?AdEvent {
        $this->validateFormat(
            $format
        );

        return $this->eventRecorder
            ->recordClick(
                format: $format,
                video: $video,
                opportunityUuid:
                    $opportunityUuid,
                placementKey:
                    $placementKey,
                sessionKey:
                    $this->sessionState
                        ->sessionKey(),
                deviceType:
                    $this->deviceType(
                        $isMobile
                    ),
                adNetwork:
                    $adNetwork,
                campaignKey:
                    $campaignKey,
                metadata:
                    $metadata,
                occurredAt:
                    $occurredAt
            );
    }

    public function recordSkip(
        string $format,
        ?Video $video,
        string $opportunityUuid,
        bool $isMobile,
        ?string $placementKey = null,
        ?string $adNetwork = null,
        ?string $campaignKey = null,
        array $metadata = [],
        ?CarbonInterface $occurredAt = null
    ): ?AdEvent {
        $this->validateFormat(
            $format
        );

        return $this->eventRecorder
            ->recordSkip(
                format: $format,
                video: $video,
                opportunityUuid:
                    $opportunityUuid,
                placementKey:
                    $placementKey,
                sessionKey:
                    $this->sessionState
                        ->sessionKey(),
                deviceType:
                    $this->deviceType(
                        $isMobile
                    ),
                adNetwork:
                    $adNetwork,
                campaignKey:
                    $campaignKey,
                metadata:
                    $metadata,
                occurredAt:
                    $occurredAt
            );
    }

    public function recordClose(
        string $format,
        ?Video $video,
        string $opportunityUuid,
        bool $isMobile,
        ?string $placementKey = null,
        ?string $adNetwork = null,
        ?string $campaignKey = null,
        array $metadata = [],
        ?CarbonInterface $occurredAt = null
    ): ?AdEvent {
        $this->validateFormat(
            $format
        );

        return $this->eventRecorder
            ->recordClose(
                format: $format,
                video: $video,
                opportunityUuid:
                    $opportunityUuid,
                placementKey:
                    $placementKey,
                sessionKey:
                    $this->sessionState
                        ->sessionKey(),
                deviceType:
                    $this->deviceType(
                        $isMobile
                    ),
                adNetwork:
                    $adNetwork,
                campaignKey:
                    $campaignKey,
                metadata:
                    $metadata,
                occurredAt:
                    $occurredAt
            );
    }

    public function recordError(
        string $format,
        string $errorReason,
        ?Video $video,
        string $opportunityUuid,
        bool $isMobile,
        ?string $placementKey = null,
        ?string $adNetwork = null,
        ?string $campaignKey = null,
        array $metadata = [],
        ?CarbonInterface $occurredAt = null
    ): ?AdEvent {
        $this->validateFormat(
            $format
        );

        return $this->eventRecorder
            ->recordError(
                format: $format,
                errorReason:
                    $errorReason,
                video: $video,
                opportunityUuid:
                    $opportunityUuid,
                placementKey:
                    $placementKey,
                sessionKey:
                    $this->sessionState
                        ->sessionKey(),
                deviceType:
                    $this->deviceType(
                        $isMobile
                    ),
                adNetwork:
                    $adNetwork,
                campaignKey:
                    $campaignKey,
                metadata:
                    $metadata,
                occurredAt:
                    $occurredAt
            );
    }

    private function recordImpressionState(
        string $format,
        int $interruptionCost,
        CarbonInterface $occurredAt
    ): void {
        if ($format === AdEvent::FORMAT_PREROLL) {
            $this->sessionState
                ->recordPrerollImpression(
                    interruptionCost:
                        $interruptionCost,
                    occurredAt:
                        $occurredAt
                );

            $this->frequencyState
                ->recordPrerollImpression(
                    visitorKey:
                        $this->visitorKey(),
                    occurredAt:
                        $occurredAt
                );

            return;
        }

        if ($format === AdEvent::FORMAT_POPUNDER) {
            $this->sessionState
                ->recordPopunderImpression(
                    interruptionCost:
                        $interruptionCost,
                    occurredAt:
                        $occurredAt
                );

            $this->frequencyState
                ->recordPopunderImpression(
                    visitorKey:
                        $this->visitorKey(),
                    occurredAt:
                        $occurredAt
                );

            return;
        }

        if (
            $format ===
            AdEvent::FORMAT_INTERSTITIAL
        ) {
            $this->sessionState
                ->recordInterstitialImpression(
                    interruptionCost:
                        $interruptionCost,
                    occurredAt:
                        $occurredAt
                );

            $this->frequencyState
                ->recordInterstitialImpression(
                    visitorKey:
                        $this->visitorKey(),
                    occurredAt:
                        $occurredAt
                );
        }
    }

    private function trackMissingProviderDecision(
        string $format,
        Video $video,
        bool $isMobile,
        ?string $placementKey
    ): array {
        return $this->trackDecision(
            decision: [
                'show' => false,
                'format' => $format,
                'reason' =>
                    'missing_provider_policy',
                'metadata' => [
                    'video_source' =>
                        $video->video_source,
                ],
            ],
            video: $video,
            isMobile: $isMobile,
            placementKey: $placementKey
        );
    }

    /**
     * Apply the trusted network/placement delivery layer
     * after policy eligibility but before decision tracking.
     *
     * A policy-eligible decision must also have a real,
     * active and compatible placement. Missing delivery
     * configuration fails closed.
     */
    private function applyPlacementSelection(
        array $decision,
        ?string $placementKey,
        bool $isMobile
    ): array {
        if (
            ($decision['show'] ?? false)
            !== true
        ) {
            return $decision;
        }

        $format =
            $decision['format']
            ?? null;

        $this->validateFormat(
            is_string($format)
                ? $format
                : ''
        );

        if (
            $placementKey === null
            || trim($placementKey) === ''
        ) {
            return $this->skipForPlacement(
                decision: $decision,
                reason:
                    'missing_ad_placement_key'
            );
        }

        $normalizedPlacementKey =
            trim($placementKey);

        $placement =
            $this->placementSelector
                ->select(
                    placementKey:
                        $normalizedPlacementKey,
                    format:
                        $format,
                    isMobile:
                        $isMobile
                );

        if ($placement === null) {
            return $this->skipForPlacement(
                decision: $decision,
                reason:
                    'no_eligible_ad_placement'
            );
        }

        $network =
            $placement->network;

        if ($network === null) {
            return $this->skipForPlacement(
                decision: $decision,
                reason:
                    'no_eligible_ad_network'
            );
        }

        $publicConfiguration =
            $placement
                ->publicRuntimeConfiguration();

        $metadata =
            $decision['metadata']
            ?? [];

        if (! is_array($metadata)) {
            $metadata = [];
        }

        $decision['metadata'] =
            array_merge(
                $metadata,
                [
                    'ad_network' =>
                        $network->slug,

                    'ad_placement_id' =>
                        $placement->id,

                    'ad_driver' =>
                        $network->driver,
                ]
            );

        /*
         * Browser-safe delivery configuration.
         *
         * No API secret, private token or arbitrary
         * executable script URL is exposed here.
         */
        $decision['delivery'] = [
            'ad_network' =>
                $network->slug,

            'ad_placement_id' =>
                $placement->id,

            'ad_driver' =>
                $network->driver,

            'public_placement_id' =>
                $publicConfiguration[
                    'public_placement_id'
                ] ?? null,

            'public_config' =>
                $publicConfiguration[
                    'public_config'
                ] ?? [],
        ];

        return $decision;
    }

    /**
     * Convert a policy-eligible decision into a
     * fail-closed delivery skip while preserving
     * the original policy reason for analytics.
     */
    private function skipForPlacement(
        array $decision,
        string $reason
    ): array {
        $metadata =
            $decision['metadata']
            ?? [];

        if (! is_array($metadata)) {
            $metadata = [];
        }

        $originalReason =
            $decision['reason']
            ?? null;

        if (
            is_string($originalReason)
            && $originalReason !== ''
        ) {
            $metadata[
                'policy_reason'
            ] = $originalReason;
        }

        $decision['show'] =
            false;

        $decision['reason'] =
            $reason;

        $decision['metadata'] =
            $metadata;

        unset(
            $decision['delivery']
        );

        return $decision;
    }

    private function trackDecision(
        array $decision,
        ?Video $video,
        bool $isMobile,
        ?string $placementKey
    ): array {
        $opportunityUuid =
            $this->eventRecorder
                ->newOpportunityUuid();

        $adNetwork =
            $this->trustedAdNetworkFromDecision(
                $decision
            );

        $this->eventRecorder
            ->recordDecision(
                decision: $decision,
                video: $video,
                opportunityUuid:
                    $opportunityUuid,
                placementKey:
                    $placementKey,
                sessionKey:
                    $this->sessionState
                        ->sessionKey(),
                deviceType:
                    $this->deviceType(
                        $isMobile
                    ),
                adNetwork:
                    $adNetwork
            );

        $decision['opportunity_uuid'] =
            $opportunityUuid;

        return $decision;
    }

    /**
     * Read ad network only from trusted server-created
     * delivery context.
     *
     * A malformed delivery block fails closed by
     * returning no network attribution.
     */
    private function trustedAdNetworkFromDecision(
        array $decision
    ): ?string {
        $delivery =
            $decision['delivery']
            ?? null;

        if (! is_array($delivery)) {
            return null;
        }

        $adNetwork =
            $delivery['ad_network']
            ?? null;

        if (! is_string($adNetwork)) {
            return null;
        }

        $adNetwork =
            trim($adNetwork);

        if (
            $adNetwork === ''
            || strlen($adNetwork) > 64
        ) {
            return null;
        }

        return $adNetwork;
    }

    private function visitorKey(): string
    {
        if (
            $this->resolvedVisitorKey !== null
        ) {
            return $this->resolvedVisitorKey;
        }

        $this->resolvedVisitorKey =
            $this->visitorIdentity
                ->resolve(
                    $this->request
                );

        return $this->resolvedVisitorKey;
    }

    private function validateFormat(
        string $format
    ): void {
        if (
            ! in_array(
                $format,
                AdEvent::formats(),
                true
            )
        ) {
            throw new InvalidArgumentException(
                'Unsupported ad format.'
            );
        }
    }

    private function deviceType(
        bool $isMobile
    ): string {
        return $isMobile
            ? AdEvent::DEVICE_MOBILE
            : AdEvent::DEVICE_DESKTOP;
    }
}
