<?php

namespace Database\Seeders;

use App\Models\MonetizationSetting;
use Illuminate\Database\Seeder;

class MonetizationSettingSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        MonetizationSetting::updateOrCreate(
            [
                'settings_key' =>
                    MonetizationSetting::GLOBAL_SETTINGS_KEY,
            ],
            [
                /*
                 * Global monetization.
                 */
                'master_enabled' => true,

                /*
                 * Default launch strategy.
                 */
                'profile' =>
                    MonetizationSetting::PROFILE_BALANCED,

                /*
                 * Device-level advertising.
                 */
                'mobile_ads_enabled' => true,
                'desktop_ads_enabled' => true,

                /*
                 * Native advertising.
                 *
                 * One sponsored/native placement
                 * after every 12 organic video cards.
                 */
                'native_ads_enabled' => true,
                'native_ad_interval' => 12,

                /*
                 * Banner advertising.
                 */
                'banner_ads_enabled' => true,

                /*
                 * Xurvexa pre-roll.
                 *
                 * Enabled globally, but provider-level
                 * rules may suppress it.
                 *
                 * XVideos currently has its own player
                 * advertising, so its provider policy
                 * disables Xurvexa pre-roll.
                 */
                'preroll_enabled' => true,

                'skip_preroll_when_provider_has_ads' =>
                    true,

                /*
                 * User experience protection.
                 */
                'preroll_skip_after_seconds' => 5,
                'preroll_max_per_session' => 2,
                'preroll_cooldown_minutes' => 30,

                /*
                 * Protect the user's first video
                 * interaction from Xurvexa pre-roll.
                 */
                'preroll_on_first_video' => false,

                /*
                 * Mid-roll starts disabled.
                 */
                'midroll_enabled' => false,

                /*
                 * Popunder starts conservatively.
                 *
                 * Eligible after 2 meaningful
                 * interactions.
                 *
                 * Maximum:
                 * - 1 per session
                 * - 1 per 24 hours
                 */
                'popunder_enabled' => true,

                'popunder_trigger_after_interactions' =>
                    2,

                'popunder_frequency_minutes' =>
                    1440,

                'popunder_max_per_session' => 1,
                'popunder_max_per_day' => 1,

                'popunder_mobile_enabled' => true,
                'popunder_desktop_enabled' => true,

                /*
                 * Interstitial starts disabled.
                 *
                 * Infrastructure exists so it may
                 * later be enabled through controlled
                 * A/B testing.
                 */
                'interstitial_enabled' => false,

                'interstitial_trigger_after_interactions' =>
                    3,

                'interstitial_frequency_minutes' =>
                    1440,

                'interstitial_max_per_session' => 1,

                /*
                 * Shared disruptive-ad budget.
                 *
                 * Pre-roll, popunder and interstitial
                 * consume this budget.
                 *
                 * Native and banner advertising do not.
                 */
                'session_interruption_budget' => 2,

                /*
                 * Never start sound-on autoplay
                 * advertising by default.
                 */
                'autoplay_sound_ads_enabled' => false,

                /*
                 * Keep event tracking enabled so the
                 * system can later optimize:
                 *
                 * - revenue per session
                 * - play rate
                 * - exit rate
                 * - videos per session
                 * - return rate
                 * - ad frequency experiments
                 */
                'ad_event_tracking_enabled' => true,

                'notes' =>
                    'Balanced launch profile. '
                    . 'Prioritizes native and banner revenue, '
                    . 'provider-aware pre-roll, and conservative '
                    . 'popunder frequency. Mid-roll and '
                    . 'interstitial advertising start disabled.',
            ],
        );
    }
}
