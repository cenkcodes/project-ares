<?php

namespace App\Http\Controllers;

use App\Models\AdEvent;
use App\Models\Video;
use App\Services\Monetization\MonetizationOpportunityState;
use App\Services\Monetization\TrackedAdDecisionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class MonetizationRuntimeController extends Controller
{
    public function interaction(
        TrackedAdDecisionService $monetization
    ): JsonResponse {
        $count = $monetization
            ->recordMeaningfulInteraction();

        return response()->json([
            'ok' => true,
            'meaningful_interaction_count' => $count,
        ]);
    }

    public function decision(
        Request $request,
        TrackedAdDecisionService $monetization,
        MonetizationOpportunityState $opportunityState
    ): JsonResponse {
        $validated = $request->validate([
            'format' => [
                'required',
                'string',
                Rule::in(AdEvent::formats()),
            ],
            'video_id' => [
                'nullable',
                'integer',
            ],
            'is_mobile' => [
                'required',
                'boolean',
            ],
            'placement_key' => [
                'nullable',
                'string',
                'max:255',
            ],
        ]);

        $format = $validated['format'];

        $video = $this->resolveVideo(
            videoId: $validated['video_id'] ?? null,
            required: $this->formatRequiresVideo(
                $format
            )
        );

        $isMobile = (bool) $validated[
            'is_mobile'
        ];

        $placementKey =
            $validated['placement_key'] ?? null;

        $decision = match ($format) {
            AdEvent::FORMAT_NATIVE =>
                $monetization->decideNative(
                    video: $video,
                    isMobile: $isMobile,
                    placementKey: $placementKey
                ),

            AdEvent::FORMAT_BANNER =>
                $monetization->decideBanner(
                    video: $video,
                    isMobile: $isMobile,
                    placementKey: $placementKey
                ),

            AdEvent::FORMAT_PREROLL =>
                $monetization->decidePreroll(
                    video: $video,
                    isMobile: $isMobile,
                    placementKey: $placementKey
                ),

            AdEvent::FORMAT_MIDROLL =>
                $monetization->decideMidroll(
                    video: $video,
                    isMobile: $isMobile,
                    placementKey: $placementKey
                ),

            AdEvent::FORMAT_POPUNDER =>
                $monetization->decidePopunder(
                    video: $video,
                    isMobile: $isMobile,
                    placementKey: $placementKey
                ),

            AdEvent::FORMAT_INTERSTITIAL =>
                $monetization->decideInterstitial(
                    video: $video,
                    isMobile: $isMobile,
                    placementKey: $placementKey
                ),
        };

        $commercialContext =
            $this->commercialContextFromDecision(
                $decision
            );

        $opportunityState->rememberDecision(
            decision: $decision,
            videoId: $video?->id,
            placementKey: $placementKey,
            isMobile: $isMobile,
            adNetwork:
                $commercialContext[
                    'ad_network'
                ],
            adPlacementId:
                $commercialContext[
                    'ad_placement_id'
                ],
            adDriver:
                $commercialContext[
                    'ad_driver'
                ]
        );

        return response()->json([
            'ok' => true,
            'decision' => $decision,
        ]);
    }

    public function event(
        Request $request,
        TrackedAdDecisionService $monetization,
        MonetizationOpportunityState $opportunityState
    ): JsonResponse {
        $this->rejectClientControlledCommercialFields(
            $request
        );

        $validated = $request->validate([
            'event_type' => [
                'required',
                'string',
                Rule::in([
                    AdEvent::EVENT_IMPRESSION,
                    AdEvent::EVENT_CLICK,
                    AdEvent::EVENT_SKIP,
                    AdEvent::EVENT_CLOSE,
                    AdEvent::EVENT_ERROR,
                ]),
            ],
            'format' => [
                'required',
                'string',
                Rule::in(AdEvent::formats()),
            ],
            'opportunity_uuid' => [
                'required',
                'uuid',
            ],
            'video_id' => [
                'nullable',
                'integer',
            ],
            'is_mobile' => [
                'required',
                'boolean',
            ],
            'placement_key' => [
                'nullable',
                'string',
                'max:255',
            ],
            'error_reason' => [
                'nullable',
                'string',
                'max:255',
                'required_if:event_type,' .
                    AdEvent::EVENT_ERROR,
            ],
        ]);

        $video = $this->resolveVideo(
            videoId: $validated['video_id'] ?? null,
            required: false
        );

        $format = $validated['format'];
        $eventType = $validated['event_type'];

        $opportunityUuid =
            $validated['opportunity_uuid'];

        $isMobile =
            (bool) $validated['is_mobile'];

        $placementKey =
            $validated['placement_key'] ?? null;

        $opportunity =
            $this->claimOpportunityEvent(
                opportunityState:
                    $opportunityState,

                opportunityUuid:
                    $opportunityUuid,

                eventType:
                    $eventType,

                format:
                    $format,

                videoId:
                    $video?->id,

                placementKey:
                    $placementKey,

                isMobile:
                    $isMobile
            );

        $adNetwork =
            $this->trustedOpportunityString(
                opportunity:
                    $opportunity,
                key:
                    'ad_network'
            );

        $adDriver =
            $this->trustedOpportunityString(
                opportunity:
                    $opportunity,
                key:
                    'ad_driver'
            );

        $adPlacementId =
            $this->trustedOpportunityPositiveInt(
                opportunity:
                    $opportunity,
                key:
                    'ad_placement_id'
            );

        $commercialMetadata = [
            'ad_placement_id' =>
                $adPlacementId,
            'ad_driver' =>
                $adDriver,
        ];

        $event = match ($eventType) {
            AdEvent::EVENT_IMPRESSION =>
                $monetization->recordImpression(
                    format: $format,
                    video: $video,
                    opportunityUuid:
                        $opportunityUuid,
                    isMobile: $isMobile,
                    placementKey: $placementKey,
                    interruptionCost:
                        $this->interruptionCost(
                            $format
                        ),
                    adNetwork:
                        $adNetwork,
                    metadata:
                        $commercialMetadata
                ),

            AdEvent::EVENT_CLICK =>
                $monetization->recordClick(
                    format: $format,
                    video: $video,
                    opportunityUuid:
                        $opportunityUuid,
                    isMobile: $isMobile,
                    placementKey: $placementKey,
                    adNetwork:
                        $adNetwork,
                    metadata:
                        $commercialMetadata
                ),

            AdEvent::EVENT_SKIP =>
                $monetization->recordSkip(
                    format: $format,
                    video: $video,
                    opportunityUuid:
                        $opportunityUuid,
                    isMobile: $isMobile,
                    placementKey: $placementKey,
                    adNetwork:
                        $adNetwork,
                    metadata:
                        $commercialMetadata
                ),

            AdEvent::EVENT_CLOSE =>
                $monetization->recordClose(
                    format: $format,
                    video: $video,
                    opportunityUuid:
                        $opportunityUuid,
                    isMobile: $isMobile,
                    placementKey: $placementKey,
                    adNetwork:
                        $adNetwork,
                    metadata:
                        $commercialMetadata
                ),

            AdEvent::EVENT_ERROR =>
                $monetization->recordError(
                    format: $format,
                    errorReason:
                        $validated[
                            'error_reason'
                        ],
                    video: $video,
                    opportunityUuid:
                        $opportunityUuid,
                    isMobile: $isMobile,
                    placementKey: $placementKey,
                    adNetwork:
                        $adNetwork,
                    metadata:
                        $commercialMetadata
                ),
        };

        return response()->json([
            'ok' => true,
            'tracked' => $event !== null,
            'event_uuid' => $event?->event_uuid,
        ]);
    }

    private function claimOpportunityEvent(
        MonetizationOpportunityState $opportunityState,
        string $opportunityUuid,
        string $eventType,
        string $format,
        ?int $videoId,
        ?string $placementKey,
        bool $isMobile
    ): array {
        try {
            return $opportunityState->claimEvent(
                opportunityUuid:
                    $opportunityUuid,

                eventType:
                    $eventType,

                format:
                    $format,

                videoId:
                    $videoId,

                placementKey:
                    $placementKey,

                isMobile:
                    $isMobile
            );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'opportunity_uuid' => [
                    $exception->getMessage(),
                ],
            ]);
        }
    }

    private function resolveVideo(
        ?int $videoId,
        bool $required
    ): ?Video {
        if ($videoId === null) {
            if ($required) {
                throw ValidationException::withMessages([
                    'video_id' => [
                        'This ad format requires a video.',
                    ],
                ]);
            }

            return null;
        }

        return Video::query()
            ->whereKey($videoId)
            ->where('is_active', true)
            ->firstOrFail();
    }

    private function formatRequiresVideo(
        string $format
    ): bool {
        return in_array(
            $format,
            [
                AdEvent::FORMAT_PREROLL,
                AdEvent::FORMAT_MIDROLL,
                AdEvent::FORMAT_POPUNDER,
                AdEvent::FORMAT_INTERSTITIAL,
            ],
            true
        );
    }

    private function interruptionCost(
        string $format
    ): int {
        return in_array(
            $format,
            [
                AdEvent::FORMAT_PREROLL,
                AdEvent::FORMAT_MIDROLL,
                AdEvent::FORMAT_POPUNDER,
                AdEvent::FORMAT_INTERSTITIAL,
            ],
            true
        )
            ? 1
            : 0;
    }

    /**
     * Read commercial delivery context only from the
     * server-created decision response.
     */
    private function commercialContextFromDecision(
        array $decision
    ): array {
        if (
            ($decision['show'] ?? false)
            !== true
        ) {
            return [
                'ad_network' => null,
                'ad_placement_id' => null,
                'ad_driver' => null,
            ];
        }

        $delivery =
            $decision['delivery']
            ?? null;

        if (! is_array($delivery)) {
            throw new InvalidArgumentException(
                'Eligible monetization decision is missing delivery context.'
            );
        }

        $adNetwork =
            $delivery['ad_network']
            ?? null;

        $adPlacementId =
            $delivery['ad_placement_id']
            ?? null;

        $adDriver =
            $delivery['ad_driver']
            ?? null;

        if (
            ! is_string($adNetwork)
            || trim($adNetwork) === ''
            || strlen(trim($adNetwork)) > 64
            || ! is_int($adPlacementId)
            || $adPlacementId < 1
            || ! is_string($adDriver)
            || trim($adDriver) === ''
            || strlen(trim($adDriver)) > 64
        ) {
            throw new InvalidArgumentException(
                'Eligible monetization decision has invalid delivery context.'
            );
        }

        return [
            'ad_network' =>
                trim($adNetwork),
            'ad_placement_id' =>
                $adPlacementId,
            'ad_driver' =>
                trim($adDriver),
        ];
    }

    private function trustedOpportunityString(
        array $opportunity,
        string $key
    ): ?string {
        $value =
            $opportunity[$key]
            ?? null;

        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException(
                'Invalid monetization opportunity commercial state.'
            );
        }

        $value = trim($value);

        if (
            $value === ''
            || strlen($value) > 64
        ) {
            throw new InvalidArgumentException(
                'Invalid monetization opportunity commercial state.'
            );
        }

        return $value;
    }

    private function trustedOpportunityPositiveInt(
        array $opportunity,
        string $key
    ): ?int {
        $value =
            $opportunity[$key]
            ?? null;

        if ($value === null) {
            return null;
        }

        if (
            ! is_int($value)
            || $value < 1
        ) {
            throw new InvalidArgumentException(
                'Invalid monetization opportunity commercial state.'
            );
        }

        return $value;
    }

    private function rejectClientControlledCommercialFields(
        Request $request
    ): void {
        $forbidden = [
            'revenue_micros',
            'currency',
            'ad_network',
            'ad_placement_id',
            'ad_driver',
            'campaign_key',
            'interruption_cost',
        ];

        foreach ($forbidden as $field) {
            if ($request->exists($field)) {
                throw ValidationException::withMessages([
                    $field => [
                        'This field is controlled by the server.',
                    ],
                ]);
            }
        }
    }
}
