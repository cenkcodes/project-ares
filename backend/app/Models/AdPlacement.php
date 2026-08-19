<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdPlacement extends Model
{
    public const PLACEMENT_VIDEO_BANNER =
        'video_banner';

    public const PLACEMENT_VIDEO_PREROLL =
        'video_preroll';

    public const PLACEMENT_VIDEO_POPUNDER =
        'video_popunder';

    public const PLACEMENT_HOME_NATIVE =
        'home_native';

    protected $fillable = [
        'ad_network_id',
        'placement_key',
        'format',
        'is_active',
        'priority',
        'desktop_enabled',
        'mobile_enabled',
        'public_placement_id',
        'public_config',
        'notes',
    ];

    protected $casts = [
        'ad_network_id' =>
            'integer',

        'is_active' =>
            'boolean',

        'priority' =>
            'integer',

        'desktop_enabled' =>
            'boolean',

        'mobile_enabled' =>
            'boolean',

        'public_config' =>
            'array',
    ];

    /**
     * Advertising network that owns
     * this placement configuration.
     */
    public function network(): BelongsTo
    {
        return $this->belongsTo(
            AdNetwork::class,
            'ad_network_id'
        );
    }

    /**
     * Known Xurvexa placement keys.
     *
     * Additional placement keys may be added
     * later without changing existing records.
     */
    public static function placementKeys(): array
    {
        return [
            self::PLACEMENT_VIDEO_BANNER,
            self::PLACEMENT_VIDEO_PREROLL,
            self::PLACEMENT_VIDEO_POPUNDER,
            self::PLACEMENT_HOME_NATIVE,
        ];
    }

    /**
     * Determine whether the placement itself
     * is enabled for the requested device.
     */
    public function isEnabledForDevice(
        bool $isMobile
    ): bool {
        if (! $this->is_active) {
            return false;
        }

        if ($isMobile) {
            return $this->mobile_enabled;
        }

        return $this->desktop_enabled;
    }

    /**
     * Determine whether this placement has
     * an available advertising network.
     *
     * Missing relationships fail closed.
     */
    public function hasAvailableNetwork(): bool
    {
        $network =
            $this->network;

        if (! $network) {
            return false;
        }

        return $network->isAvailable();
    }

    /**
     * Determine whether the network supports
     * the placement's declared ad format.
     */
    public function networkSupportsFormat(): bool
    {
        $network =
            $this->network;

        if (! $network) {
            return false;
        }

        return $network->supportsFormat(
            $this->format
        );
    }

    /**
     * Determine whether the placement may serve
     * for the requested device.
     *
     * This checks only network/placement capability.
     *
     * Global MonetizationSetting and video-provider
     * policy are evaluated separately by the
     * monetization decision layer.
     */
    public function canServe(
        bool $isMobile
    ): bool {
        if (
            ! $this->isEnabledForDevice(
                $isMobile
            )
        ) {
            return false;
        }

        $network =
            $this->network;

        if (! $network) {
            return false;
        }

        return $network->canServeFormat(
            $this->format
        );
    }

    /**
     * Return browser-safe public placement
     * configuration.
     *
     * This method does not include private
     * credentials or environment secrets.
     */
    public function publicRuntimeConfiguration(): array
    {
        return [
            'placement_key' =>
                $this->placement_key,

            'format' =>
                $this->format,

            'public_placement_id' =>
                $this->public_placement_id,

            'public_config' =>
                $this->public_config
                ?? [],
        ];
    }
}
