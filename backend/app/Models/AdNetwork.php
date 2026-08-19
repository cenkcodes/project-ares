<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdNetwork extends Model
{
    public const FORMAT_NATIVE =
        'native';

    public const FORMAT_BANNER =
        'banner';

    public const FORMAT_PREROLL =
        'preroll';

    public const FORMAT_MIDROLL =
        'midroll';

    public const FORMAT_POPUNDER =
        'popunder';

    public const FORMAT_INTERSTITIAL =
        'interstitial';

    protected $fillable = [
        'name',
        'slug',
        'driver',
        'is_active',
        'priority',
        'supports_native',
        'supports_banner',
        'supports_preroll',
        'supports_midroll',
        'supports_popunder',
        'supports_interstitial',
        'notes',
    ];

    protected $casts = [
        'is_active' =>
            'boolean',

        'priority' =>
            'integer',

        'supports_native' =>
            'boolean',

        'supports_banner' =>
            'boolean',

        'supports_preroll' =>
            'boolean',

        'supports_midroll' =>
            'boolean',

        'supports_popunder' =>
            'boolean',

        'supports_interstitial' =>
            'boolean',
    ];

    /**
     * Supported monetization formats.
     */
    public static function formats(): array
    {
        return [
            self::FORMAT_NATIVE,
            self::FORMAT_BANNER,
            self::FORMAT_PREROLL,
            self::FORMAT_MIDROLL,
            self::FORMAT_POPUNDER,
            self::FORMAT_INTERSTITIAL,
        ];
    }

    /**
     * Determine whether this network is available
     * for monetization decisions.
     */
    public function isAvailable(): bool
    {
        return $this->is_active;
    }

    /**
     * Determine whether this network declares
     * support for the requested ad format.
     */
    public function supportsFormat(
        string $format
    ): bool {
        return match ($format) {
            self::FORMAT_NATIVE =>
                $this->supports_native,

            self::FORMAT_BANNER =>
                $this->supports_banner,

            self::FORMAT_PREROLL =>
                $this->supports_preroll,

            self::FORMAT_MIDROLL =>
                $this->supports_midroll,

            self::FORMAT_POPUNDER =>
                $this->supports_popunder,

            self::FORMAT_INTERSTITIAL =>
                $this->supports_interstitial,

            default =>
                false,
        };
    }

    /**
     * Determine whether this network may be used
     * for the requested format.
     *
     * A disabled network always fails closed.
     */
    public function canServeFormat(
        string $format
    ): bool {
        return $this->isAvailable()
            && $this->supportsFormat(
                $format
            );
    }
}
