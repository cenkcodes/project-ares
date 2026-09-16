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
                 * Global monetization master switch.
                 *
                 * Production launch starts fail-closed.
                 *
                 * This must remain false until:
                 * - approved ad networks exist,
                 * - public placement IDs are configured,
                 * - frontend drivers are verified,
                 * - advertising consent gating is verified.
                 */
                'master_enabled' => false,

                /*
                 * Default strategy once monetization
                 * is intentionally enabled.
                 */
                'profile' =>
                    MonetizationSetting::PROFILE_BALANCED,

                /*
                 * Device-level advertising.
                 *
                 * These are subordinate to
                 * master_enabled.
                 */
                'mobile_ads_enabled' => true,
                'desktop_ads_enabled' => true,

                /*
                 * Native advertising.
                 */
                'native_ads_enabled' => true,
                'native_ad_interval' => 12,

                /*
                 * Banner advertising.
                 */
                'banner_ads_enabled' => true,

                /*
                 * Xurvexa-controlled pre-roll.
                 *
                 * Provider-level policy may suppress it.
                 */
                'preroll_enabled' => true,

                'skip_preroll_when_provider_has_ads' =>
                    true,

                /*
                 * User-experience protection.
                 */
                'preroll_skip_after_seconds' => 5,
                'preroll_max_per_session' => 2,
                'preroll_cooldown_minutes' => 30,
                'preroll_on_first_video' => false,

                /*
                 * Mid-roll starts disabled.
                 */
                'midroll_enabled' => false,

                /*
                 * Popunder configuration.
                 *
                 * This remains subordinate to
                 * master_enabled.
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
                 */
                'interstitial_enabled' => false,

                'interstitial_trigger_after_interactions' =>
                    3,

                'interstitial_frequency_minutes' =>
                    1440,

                'interstitial_max_per_session' => 1,

                /*
                 * Shared disruptive-ad budget.
                 */
                'session_interruption_budget' => 2,

                /*
                 * Sound-on autoplay advertising
                 * remains disabled.
                 */
                'autoplay_sound_ads_enabled' => false,

                /*
                 * Keep ad-event collection available
                 * for future performance analysis.
                 */
                'ad_event_tracking_enabled' => true,

                /*
                 * Operational note.
                 */
                'notes' =>
                    'Production fail-closed launch profile. '
                    . 'Global monetization remains disabled until '
                    . 'approved ad networks, production placement IDs, '
                    . 'frontend drivers, and advertising consent gating '
                    . 'have all been verified.',
            ],
        );
    }
}
