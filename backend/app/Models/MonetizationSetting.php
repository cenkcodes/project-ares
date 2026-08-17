<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MonetizationSetting extends Model
{
    public const PROFILE_CONSERVATIVE = 'conservative';
    public const PROFILE_BALANCED = 'balanced';
    public const PROFILE_REVENUE_MAX = 'revenue_max';

    public const GLOBAL_SETTINGS_KEY = 'global';

    protected $fillable = [
        'settings_key',
        'master_enabled',
        'profile',
        'mobile_ads_enabled',
        'desktop_ads_enabled',
        'native_ads_enabled',
        'native_ad_interval',
        'banner_ads_enabled',
        'preroll_enabled',
        'skip_preroll_when_provider_has_ads',
        'preroll_skip_after_seconds',
        'preroll_max_per_session',
        'preroll_cooldown_minutes',
        'preroll_on_first_video',
        'midroll_enabled',
        'popunder_enabled',
        'popunder_trigger_after_interactions',
        'popunder_frequency_minutes',
        'popunder_max_per_session',
        'popunder_max_per_day',
        'popunder_mobile_enabled',
        'popunder_desktop_enabled',
        'interstitial_enabled',
        'interstitial_trigger_after_interactions',
        'interstitial_frequency_minutes',
        'interstitial_max_per_session',
        'session_interruption_budget',
        'autoplay_sound_ads_enabled',
        'ad_event_tracking_enabled',
        'notes',
    ];

    protected $casts = [
        'master_enabled' => 'boolean',
        'mobile_ads_enabled' => 'boolean',
        'desktop_ads_enabled' => 'boolean',

        'native_ads_enabled' => 'boolean',
        'native_ad_interval' => 'integer',

        'banner_ads_enabled' => 'boolean',

        'preroll_enabled' => 'boolean',
        'skip_preroll_when_provider_has_ads' => 'boolean',
        'preroll_skip_after_seconds' => 'integer',
        'preroll_max_per_session' => 'integer',
        'preroll_cooldown_minutes' => 'integer',
        'preroll_on_first_video' => 'boolean',

        'midroll_enabled' => 'boolean',

        'popunder_enabled' => 'boolean',
        'popunder_trigger_after_interactions' => 'integer',
        'popunder_frequency_minutes' => 'integer',
        'popunder_max_per_session' => 'integer',
        'popunder_max_per_day' => 'integer',
        'popunder_mobile_enabled' => 'boolean',
        'popunder_desktop_enabled' => 'boolean',

        'interstitial_enabled' => 'boolean',
        'interstitial_trigger_after_interactions' => 'integer',
        'interstitial_frequency_minutes' => 'integer',
        'interstitial_max_per_session' => 'integer',

        'session_interruption_budget' => 'integer',

        'autoplay_sound_ads_enabled' => 'boolean',
        'ad_event_tracking_enabled' => 'boolean',
    ];

    /**
     * Return the global monetization settings record.
     *
     * This method does not silently create database data.
     * The global record is created by the dedicated seeder.
     */
    public static function global(): ?self
    {
        return static::query()
            ->where(
                'settings_key',
                self::GLOBAL_SETTINGS_KEY
            )
            ->first();
    }

    /**
     * Supported monetization profiles.
     */
    public static function profiles(): array
    {
        return [
            self::PROFILE_CONSERVATIVE,
            self::PROFILE_BALANCED,
            self::PROFILE_REVENUE_MAX,
        ];
    }

    /**
     * Determine whether Xurvexa-controlled advertising
     * is globally available.
     */
    public function monetizationIsEnabled(): bool
    {
        return $this->master_enabled;
    }

    /**
     * Determine whether advertising is allowed for
     * the current device type.
     */
    public function adsEnabledForDevice(
        bool $isMobile
    ): bool {
        if (! $this->master_enabled) {
            return false;
        }

        if ($isMobile) {
            return $this->mobile_ads_enabled;
        }

        return $this->desktop_ads_enabled;
    }

    /**
     * Determine whether native advertising may run.
     */
    public function nativeAdsAreEnabled(): bool
    {
        return $this->master_enabled
            && $this->native_ads_enabled;
    }

    /**
     * Determine whether banner advertising may run.
     */
    public function bannerAdsAreEnabled(): bool
    {
        return $this->master_enabled
            && $this->banner_ads_enabled;
    }

    /**
     * Determine whether Xurvexa pre-roll may run
     * before provider-level rules are evaluated.
     */
    public function prerollIsEnabled(): bool
    {
        return $this->master_enabled
            && $this->preroll_enabled;
    }

    /**
     * Determine whether Xurvexa mid-roll may run
     * before provider-level rules are evaluated.
     */
    public function midrollIsEnabled(): bool
    {
        return $this->master_enabled
            && $this->midroll_enabled;
    }

    /**
     * Determine whether popunder advertising may run
     * for the requested device type.
     */
    public function popunderIsEnabledForDevice(
        bool $isMobile
    ): bool {
        if (
            ! $this->master_enabled
            || ! $this->popunder_enabled
        ) {
            return false;
        }

        if ($isMobile) {
            return $this->mobile_ads_enabled
                && $this->popunder_mobile_enabled;
        }

        return $this->desktop_ads_enabled
            && $this->popunder_desktop_enabled;
    }

    /**
     * Determine whether interstitial advertising
     * is globally enabled.
     */
    public function interstitialIsEnabled(): bool
    {
        return $this->master_enabled
            && $this->interstitial_enabled;
    }

    /**
     * Determine whether disruptive ad formats may
     * still consume interruption budget.
     *
     * Current disruptive formats:
     * - pre-roll
     * - popunder
     * - interstitial
     */
    public function hasInterruptionBudgetRemaining(
        int $consumedBudget
    ): bool {
        return $consumedBudget
            < $this->session_interruption_budget;
    }

    /**
     * Determine whether provider-owned advertising
     * should suppress Xurvexa pre-roll.
     */
    public function shouldSkipPrerollForProviderAds(
        VideoProvider $provider
    ): bool {
        return $this->skip_preroll_when_provider_has_ads
            && $provider->has_own_ads;
    }

    /**
     * Determine whether ad events should be recorded.
     */
    public function shouldTrackAdEvents(): bool
    {
        return $this->ad_event_tracking_enabled;
    }
}
