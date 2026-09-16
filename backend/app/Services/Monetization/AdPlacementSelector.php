<?php

namespace App\Services\Monetization;

use App\Models\AdNetwork;
use App\Models\AdPlacement;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class AdPlacementSelector
{
    /**
     * Select an eligible placement for the requested
     * Xurvexa placement, format and device.
     *
     * Selection order:
     * 1. Lowest placement priority creates the active tier.
     * 2. If that tier contains explicit positive
     *    public_config.traffic_weight values, weighted
     *    rotation is used inside that tier.
     * 3. If no weight is configured in the active tier,
     *    the previous deterministic behavior is preserved:
     *    network priority, then placement id.
     *
     * Lower priority numbers win.
     */
    public function select(
        string $placementKey,
        string $format,
        bool $isMobile
    ): ?AdPlacement {
        $placementKey =
            trim($placementKey);

        if ($placementKey === '') {
            return null;
        }

        $supportColumn =
            $this->supportColumnForFormat(
                $format
            );

        $deviceColumn =
            $isMobile
                ? 'ad_placements.mobile_enabled'
                : 'ad_placements.desktop_enabled';

        $placements =
            AdPlacement::query()
                ->select(
                    'ad_placements.*'
                )
                ->join(
                    'ad_networks',
                    'ad_networks.id',
                    '=',
                    'ad_placements.ad_network_id'
                )
                ->with('network')
                ->where(
                    'ad_placements.placement_key',
                    $placementKey
                )
                ->where(
                    'ad_placements.format',
                    $format
                )
                ->where(
                    'ad_placements.is_active',
                    true
                )
                ->where(
                    $deviceColumn,
                    true
                )
                ->where(
                    'ad_networks.is_active',
                    true
                )
                ->where(
                    'ad_networks.' . $supportColumn,
                    true
                )
                ->orderBy(
                    'ad_placements.priority'
                )
                ->orderBy(
                    'ad_networks.priority'
                )
                ->orderBy(
                    'ad_placements.id'
                )
                ->get();

        if ($placements->isEmpty()) {
            return null;
        }

        $activePriority =
            (int) $placements
                ->first()
                ->priority;

        $activeTier =
            $placements
                ->filter(
                    fn (AdPlacement $placement): bool =>
                        (int) $placement->priority
                        === $activePriority
                )
                ->values();

        return $this->selectFromActiveTier(
            $activeTier
        );
    }

    /**
     * Weighted rotation is opt-in.
     *
     * If at least one active-tier placement has a
     * positive traffic_weight, only positive-weight
     * placements participate in the rotation.
     *
     * This keeps unweighted future placements from
     * accidentally receiving traffic merely because
     * they share the same priority tier.
     */
    private function selectFromActiveTier(
        Collection $activeTier
    ): ?AdPlacement {
        if ($activeTier->isEmpty()) {
            return null;
        }

        $weighted =
            $activeTier
                ->map(
                    fn (AdPlacement $placement): array => [
                        'placement' => $placement,
                        'weight' =>
                            $this->trafficWeight(
                                $placement
                            ),
                    ]
                )
                ->filter(
                    fn (array $candidate): bool =>
                        $candidate['weight'] > 0
                )
                ->values();

        if ($weighted->isEmpty()) {
            return $activeTier->first();
        }

        $totalWeight =
            (int) $weighted->sum(
                'weight'
            );

        if ($totalWeight < 1) {
            return $activeTier->first();
        }

        $draw =
            random_int(
                1,
                $totalWeight
            );

        $cursor = 0;

        foreach ($weighted as $candidate) {
            $cursor +=
                (int) $candidate['weight'];

            if ($draw <= $cursor) {
                return $candidate[
                    'placement'
                ];
            }
        }

        return $weighted
            ->last()[
                'placement'
            ];
    }

    /**
     * Read an explicitly configured traffic weight.
     *
     * Missing, invalid, zero or negative values do not
     * participate in weighted rotation.
     */
    private function trafficWeight(
        AdPlacement $placement
    ): int {
        $config =
            $placement->public_config;

        if (! is_array($config)) {
            return 0;
        }

        $value =
            $config[
                'traffic_weight'
            ] ?? null;

        if (
            ! is_int($value)
            && ! is_float($value)
            && ! (
                is_string($value)
                && is_numeric($value)
            )
        ) {
            return 0;
        }

        $weight =
            (int) $value;

        if (
            $weight < 1
            || $weight > 100000
        ) {
            return 0;
        }

        return $weight;
    }

    /**
     * Convert an approved ad format into the
     * corresponding network capability column.
     *
     * Unknown formats fail closed.
     */
    private function supportColumnForFormat(
        string $format
    ): string {
        return match ($format) {
            AdNetwork::FORMAT_NATIVE =>
                'supports_native',

            AdNetwork::FORMAT_BANNER =>
                'supports_banner',

            AdNetwork::FORMAT_PREROLL =>
                'supports_preroll',

            AdNetwork::FORMAT_MIDROLL =>
                'supports_midroll',

            AdNetwork::FORMAT_POPUNDER =>
                'supports_popunder',

            AdNetwork::FORMAT_INTERSTITIAL =>
                'supports_interstitial',

            default =>
                throw new InvalidArgumentException(
                    'Unsupported ad placement format.'
                ),
        };
    }
}
