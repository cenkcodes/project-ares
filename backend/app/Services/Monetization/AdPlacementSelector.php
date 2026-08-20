<?php

namespace App\Services\Monetization;

use App\Models\AdNetwork;
use App\Models\AdPlacement;
use InvalidArgumentException;

class AdPlacementSelector
{
    /**
     * Select the highest-priority eligible placement
     * for the requested Xurvexa placement, format
     * and device.
     *
     * Selection order:
     * 1. placement priority
     * 2. network priority
     * 3. placement id
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

        return AdPlacement::query()
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
            ->first();
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
