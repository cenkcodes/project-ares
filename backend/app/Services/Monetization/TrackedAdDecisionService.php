<?php

namespace App\Services\Monetization;

use App\Models\AdEvent;
use App\Models\Video;
use App\Models\VideoProvider;
use Carbon\CarbonInterface;

class TrackedAdDecisionService
{
    public function __construct(
        private readonly AdDecisionEngine $decisionEngine,
        private readonly AdEventRecorder $eventRecorder
    ) {
    }

    public function providerForVideo(
        Video $video
    ): ?VideoProvider {
        return $this->decisionEngine
            ->providerForVideo($video);
    }

    public function decideNative(
        ?Video $video,
        bool $isMobile,
        ?string $placementKey = null,
        ?string $sessionKey = null
    ): array {
        $provider = $video !== null
            ? $this->providerForVideo($video)
            : null;

        $decision = $this->decisionEngine
            ->decideNative(
                provider: $provider,
                isMobile: $isMobile
            );

        return $this->trackDecision(
            decision: $decision,
            video: $video,
            isMobile: $isMobile,
            placementKey: $placementKey,
            sessionKey: $sessionKey
        );
    }

    public function decideBanner(
        ?Video $video,
        bool $isMobile,
        ?string $placementKey = null,
        ?string $sessionKey = null
    ): array {
        $provider = $video !== null
            ? $this->providerForVideo($video)
            : null;

        $decision = $this->decisionEngine
            ->decideBanner(
                provider: $provider,
                isMobile: $isMobile
            );

        return $this->trackDecision(
            decision: $decision,
            video: $video,
            isMobile: $isMobile,
            placementKey: $placementKey,
            sessionKey: $sessionKey
        );
    }

    public function decidePreroll(
        Video $video,
        bool $isMobile,
        int $videoInteractionNumber,
        int $sessionPrerollCount,
        int $consumedInterruptionBudget,
        ?CarbonInterface $lastPrerollAt = null,
        ?CarbonInterface $now = null,
        ?string $placementKey = null,
        ?string $sessionKey = null
    ): array {
        $provider = $this->providerForVideo(
            $video
        );

        if ($provider === null) {
            return $this->trackMissingProviderDecision(
                format: AdDecisionEngine::FORMAT_PREROLL,
                video: $video,
                isMobile: $isMobile,
                placementKey: $placementKey,
                sessionKey: $sessionKey
            );
        }

        $decision = $this->decisionEngine
            ->decidePreroll(
                provider: $provider,
                isMobile: $isMobile,
                videoInteractionNumber:
                    $videoInteractionNumber,
                sessionPrerollCount:
                    $sessionPrerollCount,
                consumedInterruptionBudget:
                    $consumedInterruptionBudget,
                lastPrerollAt: $lastPrerollAt,
                now: $now
            );

        return $this->trackDecision(
            decision: $decision,
            video: $video,
            isMobile: $isMobile,
            placementKey: $placementKey,
            sessionKey: $sessionKey
        );
    }

    public function decideMidroll(
        Video $video,
        bool $isMobile,
        ?string $placementKey = null,
        ?string $sessionKey = null
    ): array {
        $provider = $this->providerForVideo(
            $video
        );

        if ($provider === null) {
            return $this->trackMissingProviderDecision(
                format: AdDecisionEngine::FORMAT_MIDROLL,
                video: $video,
                isMobile: $isMobile,
                placementKey: $placementKey,
                sessionKey: $sessionKey
            );
        }

        $decision = $this->decisionEngine
            ->decideMidroll(
                provider: $provider,
                isMobile: $isMobile
            );

        return $this->trackDecision(
            decision: $decision,
            video: $video,
            isMobile: $isMobile,
            placementKey: $placementKey,
            sessionKey: $sessionKey
        );
    }

    public function decidePopunder(
        Video $video,
        bool $isMobile,
        int $meaningfulInteractionCount,
        int $sessionPopunderCount,
        int $dailyPopunderCount,
        int $consumedInterruptionBudget,
        ?CarbonInterface $lastPopunderAt = null,
        ?CarbonInterface $now = null,
        ?string $placementKey = null,
        ?string $sessionKey = null
    ): array {
        $provider = $this->providerForVideo(
            $video
        );

        if ($provider === null) {
            return $this->trackMissingProviderDecision(
                format: AdDecisionEngine::FORMAT_POPUNDER,
                video: $video,
                isMobile: $isMobile,
                placementKey: $placementKey,
                sessionKey: $sessionKey
            );
        }

        $decision = $this->decisionEngine
            ->decidePopunder(
                provider: $provider,
                isMobile: $isMobile,
                meaningfulInteractionCount:
                    $meaningfulInteractionCount,
                sessionPopunderCount:
                    $sessionPopunderCount,
                dailyPopunderCount:
                    $dailyPopunderCount,
                consumedInterruptionBudget:
                    $consumedInterruptionBudget,
                lastPopunderAt: $lastPopunderAt,
                now: $now
            );

        return $this->trackDecision(
            decision: $decision,
            video: $video,
            isMobile: $isMobile,
            placementKey: $placementKey,
            sessionKey: $sessionKey
        );
    }

    public function decideInterstitial(
        Video $video,
        bool $isMobile,
        int $meaningfulInteractionCount,
        int $sessionInterstitialCount,
        int $consumedInterruptionBudget,
        ?CarbonInterface $lastInterstitialAt = null,
        ?CarbonInterface $now = null,
        ?string $placementKey = null,
        ?string $sessionKey = null
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
                placementKey: $placementKey,
                sessionKey: $sessionKey
            );
        }

        $decision = $this->decisionEngine
            ->decideInterstitial(
                provider: $provider,
                isMobile: $isMobile,
                meaningfulInteractionCount:
                    $meaningfulInteractionCount,
                sessionInterstitialCount:
                    $sessionInterstitialCount,
                consumedInterruptionBudget:
                    $consumedInterruptionBudget,
                lastInterstitialAt:
                    $lastInterstitialAt,
                now: $now
            );

        return $this->trackDecision(
            decision: $decision,
            video: $video,
            isMobile: $isMobile,
            placementKey: $placementKey,
            sessionKey: $sessionKey
        );
    }

    private function trackMissingProviderDecision(
        string $format,
        Video $video,
        bool $isMobile,
        ?string $placementKey,
        ?string $sessionKey
    ): array {
        return $this->trackDecision(
            decision: [
                'show' => false,
                'format' => $format,
                'reason' => 'missing_provider_policy',
                'metadata' => [
                    'video_source' =>
                        $video->video_source,
                ],
            ],
            video: $video,
            isMobile: $isMobile,
            placementKey: $placementKey,
            sessionKey: $sessionKey
        );
    }

    private function trackDecision(
        array $decision,
        ?Video $video,
        bool $isMobile,
        ?string $placementKey,
        ?string $sessionKey
    ): array {
        $opportunityUuid =
            $this->eventRecorder
                ->newOpportunityUuid();

        $this->eventRecorder
            ->recordDecision(
                decision: $decision,
                video: $video,
                opportunityUuid:
                    $opportunityUuid,
                placementKey:
                    $placementKey,
                sessionKey:
                    $sessionKey,
                deviceType:
                    $this->deviceType(
                        $isMobile
                    )
            );

        $decision['opportunity_uuid'] =
            $opportunityUuid;

        return $decision;
    }

    private function deviceType(
        bool $isMobile
    ): string {
        return $isMobile
            ? AdEvent::DEVICE_MOBILE
            : AdEvent::DEVICE_DESKTOP;
    }
}
