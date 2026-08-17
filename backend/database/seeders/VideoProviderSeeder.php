<?php

namespace Database\Seeders;

use App\Models\VideoProvider;
use Illuminate\Database\Seeder;

class VideoProviderSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        VideoProvider::updateOrCreate(
            [
                'slug' => 'xvideos',
            ],
            [
                'name' => 'XVideos',

                'description' =>
                    'External embed provider used by Xurvexa. '
                    . 'The provider currently serves advertising '
                    . 'inside its own embedded player.',

                'is_active' => true,

                /*
                 * Xurvexa monetization remains enabled for
                 * non-player ad formats such as native,
                 * banner and controlled popunder advertising.
                 */
                'monetization_enabled' => true,

                /*
                 * XVideos currently displays its own advertising
                 * inside the embedded player.
                 */
                'has_own_ads' => true,

                /*
                 * Prevent double pre-roll / mid-roll advertising.
                 */
                'allow_xurvexa_preroll' => false,
                'allow_xurvexa_midroll' => false,

                /*
                 * Non-player monetization formats.
                 */
                'allow_popunder' => true,
                'allow_native_ads' => true,
                'allow_banner_ads' => true,

                /*
                 * Start conservatively.
                 * Interstitial can be enabled later through
                 * controlled testing if needed.
                 */
                'allow_interstitial' => false,

                'monetization_notes' =>
                    'Provider currently shows its own player ads. '
                    . 'Keep Xurvexa pre-roll and mid-roll disabled '
                    . 'to avoid double-advertising. Native, banner '
                    . 'and low-frequency popunder formats may be used.',
            ],
        );
    }
}
