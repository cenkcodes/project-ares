<?php

namespace Tests\Feature;

use App\Models\MonetizationSetting;
use App\Models\VideoProvider;
use App\Services\Monetization\AdDecisionEngine;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdDecisionEngineTest extends TestCase
{
    use RefreshDatabase;

    public function test_xvideos_preroll_is_skipped_when_provider_has_own_ads(): void
    {
        $this->createBalancedSettings();

        $provider = $this->createXVideosProvider();

        $decision = $this->engine()
            ->decidePreroll(
                provider: $provider,
                isMobile: false,
                videoInteractionNumber: 2,
                sessionPrerollCount: 0,
                consumedInterruptionBudget: 0,
            );

        $this->assertFalse(
            $decision['show']
        );

        $this->assertSame(
            AdDecisionEngine::FORMAT_PREROLL,
            $decision['format']
        );

        $this->assertSame(
            'provider_has_own_ads',
            $decision['reason']
        );
    }

    public function test_popunder_becomes_eligible_after_required_interactions(): void
    {
        $this->createBalancedSettings();

        $provider = $this->createXVideosProvider();

        $beforeThreshold = $this->engine()
            ->decidePopunder(
                provider: $provider,
                isMobile: false,
                meaningfulInteractionCount: 1,
                sessionPopunderCount: 0,
                dailyPopunderCount: 0,
                consumedInterruptionBudget: 0,
            );

        $this->assertFalse(
            $beforeThreshold['show']
        );

        $this->assertSame(
            'interaction_threshold_not_reached',
            $beforeThreshold['reason']
        );

        $atThreshold = $this->engine()
            ->decidePopunder(
                provider: $provider,
                isMobile: false,
                meaningfulInteractionCount: 2,
                sessionPopunderCount: 0,
                dailyPopunderCount: 0,
                consumedInterruptionBudget: 0,
            );

        $this->assertTrue(
            $atThreshold['show']
        );

        $this->assertSame(
            'eligible',
            $atThreshold['reason']
        );

        $this->assertTrue(
            $atThreshold['metadata']
                ['consumes_interruption_budget']
        );
    }

    public function test_popunder_frequency_cap_blocks_repeat_ad_within_24_hours(): void
    {
        $this->createBalancedSettings();

        $provider = $this->createXVideosProvider();

        $now = CarbonImmutable::parse(
            '2026-08-17 12:00:00'
        );

        $lastPopunderAt = $now
            ->subHours(2);

        $decision = $this->engine()
            ->decidePopunder(
                provider: $provider,
                isMobile: false,
                meaningfulInteractionCount: 2,
                sessionPopunderCount: 0,
                dailyPopunderCount: 0,
                consumedInterruptionBudget: 0,
                lastPopunderAt: $lastPopunderAt,
                now: $now,
            );

        $this->assertFalse(
            $decision['show']
        );

        $this->assertSame(
            'popunder_frequency_cap_active',
            $decision['reason']
        );
    }

    public function test_popunder_becomes_eligible_after_frequency_cap_expires(): void
    {
        $this->createBalancedSettings();

        $provider = $this->createXVideosProvider();

        $now = CarbonImmutable::parse(
            '2026-08-17 12:00:00'
        );

        $lastPopunderAt = $now
            ->subMinutes(1441);

        $decision = $this->engine()
            ->decidePopunder(
                provider: $provider,
                isMobile: false,
                meaningfulInteractionCount: 2,
                sessionPopunderCount: 0,
                dailyPopunderCount: 0,
                consumedInterruptionBudget: 0,
                lastPopunderAt: $lastPopunderAt,
                now: $now,
            );

        $this->assertTrue(
            $decision['show']
        );

        $this->assertSame(
            'eligible',
            $decision['reason']
        );
    }

    public function test_master_switch_disables_xurvexa_advertising(): void
    {
        $settings = $this->createBalancedSettings();

        $settings->update([
            'master_enabled' => false,
        ]);

        $provider = $this->createXVideosProvider();

        $bannerDecision = $this->engine()
            ->decideBanner(
                provider: $provider,
                isMobile: false,
            );

        $nativeDecision = $this->engine()
            ->decideNative(
                provider: $provider,
                isMobile: false,
            );

        $popunderDecision = $this->engine()
            ->decidePopunder(
                provider: $provider,
                isMobile: false,
                meaningfulInteractionCount: 10,
                sessionPopunderCount: 0,
                dailyPopunderCount: 0,
                consumedInterruptionBudget: 0,
            );

        $this->assertFalse(
            $bannerDecision['show']
        );

        $this->assertFalse(
            $nativeDecision['show']
        );

        $this->assertFalse(
            $popunderDecision['show']
        );
    }

    public function test_session_interruption_budget_blocks_disruptive_ads(): void
    {
        $this->createBalancedSettings();

        $provider = $this->createXVideosProvider();

        $decision = $this->engine()
            ->decidePopunder(
                provider: $provider,
                isMobile: false,
                meaningfulInteractionCount: 2,
                sessionPopunderCount: 0,
                dailyPopunderCount: 0,
                consumedInterruptionBudget: 2,
            );

        $this->assertFalse(
            $decision['show']
        );

        $this->assertSame(
            'session_interruption_budget_exhausted',
            $decision['reason']
        );
    }

    public function test_session_popunder_limit_blocks_second_popunder(): void
    {
        $this->createBalancedSettings();

        $provider = $this->createXVideosProvider();

        $decision = $this->engine()
            ->decidePopunder(
                provider: $provider,
                isMobile: false,
                meaningfulInteractionCount: 5,
                sessionPopunderCount: 1,
                dailyPopunderCount: 0,
                consumedInterruptionBudget: 0,
            );

        $this->assertFalse(
            $decision['show']
        );

        $this->assertSame(
            'session_popunder_limit_reached',
            $decision['reason']
        );
    }

    public function test_daily_popunder_limit_blocks_additional_popunder(): void
    {
        $this->createBalancedSettings();

        $provider = $this->createXVideosProvider();

        $decision = $this->engine()
            ->decidePopunder(
                provider: $provider,
                isMobile: false,
                meaningfulInteractionCount: 5,
                sessionPopunderCount: 0,
                dailyPopunderCount: 1,
                consumedInterruptionBudget: 0,
            );

        $this->assertFalse(
            $decision['show']
        );

        $this->assertSame(
            'daily_popunder_limit_reached',
            $decision['reason']
        );
    }

    public function test_native_and_banner_ads_remain_available_for_xvideos(): void
    {
        $this->createBalancedSettings();

        $provider = $this->createXVideosProvider();

        $nativeDecision = $this->engine()
            ->decideNative(
                provider: $provider,
                isMobile: false,
            );

        $bannerDecision = $this->engine()
            ->decideBanner(
                provider: $provider,
                isMobile: false,
            );

        $this->assertTrue(
            $nativeDecision['show']
        );

        $this->assertSame(
            'eligible',
            $nativeDecision['reason']
        );

        $this->assertTrue(
            $bannerDecision['show']
        );

        $this->assertSame(
            'eligible',
            $bannerDecision['reason']
        );
    }

    public function test_interstitial_is_disabled_in_balanced_profile(): void
    {
        $this->createBalancedSettings();

        $provider = $this->createXVideosProvider();

        $decision = $this->engine()
            ->decideInterstitial(
                provider: $provider,
                isMobile: false,
                meaningfulInteractionCount: 10,
                sessionInterstitialCount: 0,
                consumedInterruptionBudget: 0,
            );

        $this->assertFalse(
            $decision['show']
        );

        $this->assertSame(
            'interstitial_disabled_globally',
            $decision['reason']
        );
    }

    private function engine(): AdDecisionEngine
    {
        return app(
            AdDecisionEngine::class
        );
    }

    private function createBalancedSettings(): MonetizationSetting
    {
        return MonetizationSetting::create([
            'settings_key' =>
                MonetizationSetting::GLOBAL_SETTINGS_KEY,

            'master_enabled' => true,

            'profile' =>
                MonetizationSetting::PROFILE_BALANCED,

            'mobile_ads_enabled' => true,
            'desktop_ads_enabled' => true,

            'native_ads_enabled' => true,
            'native_ad_interval' => 12,

            'banner_ads_enabled' => true,

            'preroll_enabled' => true,

            'skip_preroll_when_provider_has_ads' =>
                true,

            'preroll_skip_after_seconds' => 5,
            'preroll_max_per_session' => 2,
            'preroll_cooldown_minutes' => 30,
            'preroll_on_first_video' => false,

            'midroll_enabled' => false,

            'popunder_enabled' => true,

            'popunder_trigger_after_interactions' =>
                2,

            'popunder_frequency_minutes' =>
                1440,

            'popunder_max_per_session' => 1,
            'popunder_max_per_day' => 1,

            'popunder_mobile_enabled' => true,
            'popunder_desktop_enabled' => true,

            'interstitial_enabled' => false,

            'interstitial_trigger_after_interactions' =>
                3,

            'interstitial_frequency_minutes' =>
                1440,

            'interstitial_max_per_session' => 1,

            'session_interruption_budget' => 2,

            'autoplay_sound_ads_enabled' => false,

            'ad_event_tracking_enabled' => true,
        ]);
    }

    private function createXVideosProvider(): VideoProvider
    {
        return VideoProvider::create([
            'name' => 'XVideos',
            'slug' => 'xvideos',

            'description' =>
                'Decision engine test provider.',

            'is_active' => true,

            'monetization_enabled' => true,

            'has_own_ads' => true,

            'allow_xurvexa_preroll' => false,
            'allow_xurvexa_midroll' => false,

            'allow_popunder' => true,
            'allow_native_ads' => true,
            'allow_banner_ads' => true,

            'allow_interstitial' => false,
        ]);
    }
}
