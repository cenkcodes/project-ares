<?php

namespace App\Services\Monetization;

use App\Models\MonetizationSetting;
use App\Models\Video;
use App\Models\VideoProvider;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

class AdDecisionEngine
{
    public const FORMAT_NATIVE = 'native';
    public const FORMAT_BANNER = 'banner';
    public const FORMAT_PREROLL = 'preroll';
    public const FORMAT_MIDROLL = 'midroll';
    public const FORMAT_POPUNDER = 'popunder';
    public const FORMAT_INTERSTITIAL = 'interstitial';

    /**
     * Resolve the provider policy for a video.
     */
    public function providerForVideo(
        Video $video
    ): ?VideoProvider {
        $source = trim(
            (string) $video->video_source
        );

        if ($source === '') {
            return null;
        }

        return VideoProvider::query()
            ->where('slug', $source)
            ->first();
    }

    /**
     * Native advertising does not consume the
     * disruptive-ad session budget.
     */
    public function decideNative(
        ?VideoProvider $provider,
        bool $isMobile
    ): array {
        $settings = $this->settings();

        if ($settings === null) {
            return $this->skip(
                self::FORMAT_NATIVE,
                'missing_global_settings'
            );
        }

        if (
            ! $settings->adsEnabledForDevice(
                $isMobile
            )
        ) {
            return $this->skip(
                self::FORMAT_NATIVE,
                'ads_disabled_for_device'
            );
        }

        if (
            ! $settings->nativeAdsAreEnabled()
        ) {
            return $this->skip(
                self::FORMAT_NATIVE,
                'native_ads_disabled_globally'
            );
        }

        if ($provider !== null) {
            if (! $provider->canMonetize()) {
                return $this->skip(
                    self::FORMAT_NATIVE,
                    'provider_monetization_disabled'
                );
            }

            if (! $provider->canShowNativeAds()) {
                return $this->skip(
                    self::FORMAT_NATIVE,
                    'native_ads_disabled_for_provider'
                );
            }
        }

        return $this->show(
            self::FORMAT_NATIVE,
            'eligible'
        );
    }

    /**
     * Banner advertising does not consume the
     * disruptive-ad session budget.
     */
    public function decideBanner(
        ?VideoProvider $provider,
        bool $isMobile
    ): array {
        $settings = $this->settings();

        if ($settings === null) {
            return $this->skip(
                self::FORMAT_BANNER,
                'missing_global_settings'
            );
        }

        if (
            ! $settings->adsEnabledForDevice(
                $isMobile
            )
        ) {
            return $this->skip(
                self::FORMAT_BANNER,
                'ads_disabled_for_device'
            );
        }

        if (
            ! $settings->bannerAdsAreEnabled()
        ) {
            return $this->skip(
                self::FORMAT_BANNER,
                'banner_ads_disabled_globally'
            );
        }

        if ($provider !== null) {
            if (! $provider->canMonetize()) {
                return $this->skip(
                    self::FORMAT_BANNER,
                    'provider_monetization_disabled'
                );
            }

            if (! $provider->canShowBannerAds()) {
                return $this->skip(
                    self::FORMAT_BANNER,
                    'banner_ads_disabled_for_provider'
                );
            }
        }

        return $this->show(
            self::FORMAT_BANNER,
            'eligible'
        );
    }

    /**
     * Decide whether an Xurvexa pre-roll may run.
     */
    public function decidePreroll(
        VideoProvider $provider,
        bool $isMobile,
        int $videoInteractionNumber,
        int $sessionPrerollCount,
        int $consumedInterruptionBudget,
        ?CarbonInterface $lastPrerollAt = null,
        ?CarbonInterface $now = null
    ): array {
        $settings = $this->settings();

        if ($settings === null) {
            return $this->skip(
                self::FORMAT_PREROLL,
                'missing_global_settings'
            );
        }

        if (
            ! $settings->adsEnabledForDevice(
                $isMobile
            )
        ) {
            return $this->skip(
                self::FORMAT_PREROLL,
                'ads_disabled_for_device'
            );
        }

        if (
            ! $settings->prerollIsEnabled()
        ) {
            return $this->skip(
                self::FORMAT_PREROLL,
                'preroll_disabled_globally'
            );
        }

        if (! $provider->canMonetize()) {
            return $this->skip(
                self::FORMAT_PREROLL,
                'provider_monetization_disabled'
            );
        }

        if (
            $settings
                ->shouldSkipPrerollForProviderAds(
                    $provider
                )
        ) {
            return $this->skip(
                self::FORMAT_PREROLL,
                'provider_has_own_ads'
            );
        }

        if (
            ! $provider
                ->canShowXurvexaPreroll()
        ) {
            return $this->skip(
                self::FORMAT_PREROLL,
                'preroll_disabled_for_provider'
            );
        }

        if (
            ! $settings->preroll_on_first_video
            && $videoInteractionNumber <= 1
        ) {
            return $this->skip(
                self::FORMAT_PREROLL,
                'first_video_protected'
            );
        }

        if (
            $sessionPrerollCount
            >= $settings->preroll_max_per_session
        ) {
            return $this->skip(
                self::FORMAT_PREROLL,
                'session_preroll_limit_reached'
            );
        }

        if (
            ! $settings
                ->hasInterruptionBudgetRemaining(
                    $consumedInterruptionBudget
                )
        ) {
            return $this->skip(
                self::FORMAT_PREROLL,
                'session_interruption_budget_exhausted'
            );
        }

        if (
            $this->cooldownIsActive(
                $lastPrerollAt,
                $settings
                    ->preroll_cooldown_minutes,
                $now
            )
        ) {
            return $this->skip(
                self::FORMAT_PREROLL,
                'preroll_cooldown_active'
            );
        }

        return $this->show(
            self::FORMAT_PREROLL,
            'eligible',
            [
                'skip_after_seconds' =>
                    $settings
                        ->preroll_skip_after_seconds,

                'consumes_interruption_budget' =>
                    true,
            ]
        );
    }

    /**
     * Mid-roll starts disabled in the Balanced
     * profile but remains centrally controllable.
     */
    public function decideMidroll(
        VideoProvider $provider,
        bool $isMobile
    ): array {
        $settings = $this->settings();

        if ($settings === null) {
            return $this->skip(
                self::FORMAT_MIDROLL,
                'missing_global_settings'
            );
        }

        if (
            ! $settings->adsEnabledForDevice(
                $isMobile
            )
        ) {
            return $this->skip(
                self::FORMAT_MIDROLL,
                'ads_disabled_for_device'
            );
        }

        if (
            ! $settings->midrollIsEnabled()
        ) {
            return $this->skip(
                self::FORMAT_MIDROLL,
                'midroll_disabled_globally'
            );
        }

        if (! $provider->canMonetize()) {
            return $this->skip(
                self::FORMAT_MIDROLL,
                'provider_monetization_disabled'
            );
        }

        if (
            ! $provider
                ->canShowXurvexaMidroll()
        ) {
            return $this->skip(
                self::FORMAT_MIDROLL,
                'midroll_disabled_for_provider'
            );
        }

        return $this->show(
            self::FORMAT_MIDROLL,
            'eligible'
        );
    }

    /**
     * Decide whether a popunder/clickunder may run.
     */
    public function decidePopunder(
        VideoProvider $provider,
        bool $isMobile,
        int $meaningfulInteractionCount,
        int $sessionPopunderCount,
        int $dailyPopunderCount,
        int $consumedInterruptionBudget,
        ?CarbonInterface $lastPopunderAt = null,
        ?CarbonInterface $now = null
    ): array {
        $settings = $this->settings();

        if ($settings === null) {
            return $this->skip(
                self::FORMAT_POPUNDER,
                'missing_global_settings'
            );
        }

        if (
            ! $settings
                ->popunderIsEnabledForDevice(
                    $isMobile
                )
        ) {
            return $this->skip(
                self::FORMAT_POPUNDER,
                'popunder_disabled_for_device_or_global'
            );
        }

        if (! $provider->canMonetize()) {
            return $this->skip(
                self::FORMAT_POPUNDER,
                'provider_monetization_disabled'
            );
        }

        if (! $provider->canShowPopunder()) {
            return $this->skip(
                self::FORMAT_POPUNDER,
                'popunder_disabled_for_provider'
            );
        }

        if (
            $meaningfulInteractionCount
            < $settings
                ->popunder_trigger_after_interactions
        ) {
            return $this->skip(
                self::FORMAT_POPUNDER,
                'interaction_threshold_not_reached'
            );
        }

        if (
            $sessionPopunderCount
            >= $settings
                ->popunder_max_per_session
        ) {
            return $this->skip(
                self::FORMAT_POPUNDER,
                'session_popunder_limit_reached'
            );
        }

        if (
            $dailyPopunderCount
            >= $settings
                ->popunder_max_per_day
        ) {
            return $this->skip(
                self::FORMAT_POPUNDER,
                'daily_popunder_limit_reached'
            );
        }

        if (
            ! $settings
                ->hasInterruptionBudgetRemaining(
                    $consumedInterruptionBudget
                )
        ) {
            return $this->skip(
                self::FORMAT_POPUNDER,
                'session_interruption_budget_exhausted'
            );
        }

        if (
            $this->cooldownIsActive(
                $lastPopunderAt,
                $settings
                    ->popunder_frequency_minutes,
                $now
            )
        ) {
            return $this->skip(
                self::FORMAT_POPUNDER,
                'popunder_frequency_cap_active'
            );
        }

        return $this->show(
            self::FORMAT_POPUNDER,
            'eligible',
            [
                'consumes_interruption_budget' =>
                    true,
            ]
        );
    }

    /**
     * Decide whether an interstitial may run.
     *
     * Balanced starts with interstitial disabled.
     */
    public function decideInterstitial(
        VideoProvider $provider,
        bool $isMobile,
        int $meaningfulInteractionCount,
        int $sessionInterstitialCount,
        int $consumedInterruptionBudget,
        ?CarbonInterface $lastInterstitialAt = null,
        ?CarbonInterface $now = null
    ): array {
        $settings = $this->settings();

        if ($settings === null) {
            return $this->skip(
                self::FORMAT_INTERSTITIAL,
                'missing_global_settings'
            );
        }

        if (
            ! $settings->adsEnabledForDevice(
                $isMobile
            )
        ) {
            return $this->skip(
                self::FORMAT_INTERSTITIAL,
                'ads_disabled_for_device'
            );
        }

        if (
            ! $settings
                ->interstitialIsEnabled()
        ) {
            return $this->skip(
                self::FORMAT_INTERSTITIAL,
                'interstitial_disabled_globally'
            );
        }

        if (! $provider->canMonetize()) {
            return $this->skip(
                self::FORMAT_INTERSTITIAL,
                'provider_monetization_disabled'
            );
        }

        if (
            ! $provider
                ->canShowInterstitial()
        ) {
            return $this->skip(
                self::FORMAT_INTERSTITIAL,
                'interstitial_disabled_for_provider'
            );
        }

        if (
            $meaningfulInteractionCount
            < $settings
                ->interstitial_trigger_after_interactions
        ) {
            return $this->skip(
                self::FORMAT_INTERSTITIAL,
                'interaction_threshold_not_reached'
            );
        }

        if (
            $sessionInterstitialCount
            >= $settings
                ->interstitial_max_per_session
        ) {
            return $this->skip(
                self::FORMAT_INTERSTITIAL,
                'session_interstitial_limit_reached'
            );
        }

        if (
            ! $settings
                ->hasInterruptionBudgetRemaining(
                    $consumedInterruptionBudget
                )
        ) {
            return $this->skip(
                self::FORMAT_INTERSTITIAL,
                'session_interruption_budget_exhausted'
            );
        }

        if (
            $this->cooldownIsActive(
                $lastInterstitialAt,
                $settings
                    ->interstitial_frequency_minutes,
                $now
            )
        ) {
            return $this->skip(
                self::FORMAT_INTERSTITIAL,
                'interstitial_frequency_cap_active'
            );
        }

        return $this->show(
            self::FORMAT_INTERSTITIAL,
            'eligible',
            [
                'consumes_interruption_budget' =>
                    true,
            ]
        );
    }

    /**
     * Return the current global settings record.
     *
     * Failure is intentionally handled by returning
     * null so advertising fails closed rather than
     * accidentally showing without configuration.
     */
    private function settings(): ?MonetizationSetting
    {
        return MonetizationSetting::global();
    }

    /**
     * Determine whether a frequency/cooldown
     * restriction is still active.
     */
    private function cooldownIsActive(
        ?CarbonInterface $lastShownAt,
        int $cooldownMinutes,
        ?CarbonInterface $now = null
    ): bool {
        if (
            $lastShownAt === null
            || $cooldownMinutes <= 0
        ) {
            return false;
        }

        $currentTime = $now
            ?? CarbonImmutable::now();

        $nextEligibleAt = $lastShownAt
            ->toImmutable()
            ->addMinutes(
                $cooldownMinutes
            );

        return $nextEligibleAt
            ->isAfter($currentTime);
    }

    /**
     * Standard SHOW response.
     */
    private function show(
        string $format,
        string $reason,
        array $metadata = []
    ): array {
        return [
            'show' => true,
            'format' => $format,
            'reason' => $reason,
            'metadata' => $metadata,
        ];
    }

    /**
     * Standard SKIP response.
     */
    private function skip(
        string $format,
        string $reason,
        array $metadata = []
    ): array {
        return [
            'show' => false,
            'format' => $format,
            'reason' => $reason,
            'metadata' => $metadata,
        ];
    }
}
