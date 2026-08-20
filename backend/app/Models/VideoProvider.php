<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VideoProvider extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'description',
        'is_active',
        'monetization_enabled',
        'has_own_ads',
        'allow_xurvexa_preroll',
        'allow_xurvexa_midroll',
        'allow_popunder',
        'allow_native_ads',
        'allow_banner_ads',
        'allow_interstitial',
        'monetization_notes',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'monetization_enabled' => 'boolean',
        'has_own_ads' => 'boolean',
        'allow_xurvexa_preroll' => 'boolean',
        'allow_xurvexa_midroll' => 'boolean',
        'allow_popunder' => 'boolean',
        'allow_native_ads' => 'boolean',
        'allow_banner_ads' => 'boolean',
        'allow_interstitial' => 'boolean',
    ];

    /**
     * Videos belonging to this provider.
     *
     * videos.video_source stores the provider slug,
     * so the relationship uses video_source -> slug
     * instead of a numeric provider ID.
     */
    public function videos(): HasMany
    {
        return $this->hasMany(
            Video::class,
            'video_source',
            'slug',
        );
    }

    /**
     * Determine whether Xurvexa-controlled monetization
     * is available for this provider.
     */
    public function canMonetize(): bool
    {
        return $this->is_active
            && $this->monetization_enabled;
    }

    /**
     * Determine whether Xurvexa pre-roll advertising
     * may be shown for this provider.
     *
     * Providers that already show their own advertising
     * are prevented from receiving an additional
     * Xurvexa pre-roll by default.
     */
    public function canShowXurvexaPreroll(): bool
    {
        return $this->canMonetize()
            && ! $this->has_own_ads
            && $this->allow_xurvexa_preroll;
    }

    /**
     * Determine whether Xurvexa mid-roll advertising
     * may be shown for this provider.
     */
    public function canShowXurvexaMidroll(): bool
    {
        return $this->canMonetize()
            && ! $this->has_own_ads
            && $this->allow_xurvexa_midroll;
    }

    /**
     * Determine whether popunder advertising
     * may be used for this provider.
     */
    public function canShowPopunder(): bool
    {
        return $this->canMonetize()
            && $this->allow_popunder;
    }

    /**
     * Determine whether native advertising
     * may be used for this provider.
     */
    public function canShowNativeAds(): bool
    {
        return $this->canMonetize()
            && $this->allow_native_ads;
    }

    /**
     * Determine whether banner advertising
     * may be used for this provider.
     */
    public function canShowBannerAds(): bool
    {
        return $this->canMonetize()
            && $this->allow_banner_ads;
    }

    /**
     * Determine whether interstitial advertising
     * may be used for this provider.
     */
    public function canShowInterstitial(): bool
    {
        return $this->canMonetize()
            && $this->allow_interstitial;
    }
}
