<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create(
            'monetization_settings',
            function (Blueprint $table) {
                $table->id();

                /*
                 * Allows us to maintain one global settings
                 * record today while keeping the schema ready
                 * for future scopes if needed.
                 */
                $table->string(
                    'settings_key',
                    50
                )
                    ->default('global')
                    ->unique();

                /*
                 * Emergency master switch.
                 *
                 * false:
                 * No Xurvexa-controlled advertising is shown.
                 */
                $table->boolean('master_enabled')
                    ->default(true);

                /*
                 * Current monetization strategy.
                 *
                 * Initial supported profiles:
                 * conservative
                 * balanced
                 * revenue_max
                 *
                 * The decision engine will interpret this value.
                 */
                $table->string(
                    'profile',
                    32
                )
                    ->default('balanced');

                /*
                 * Device-level master controls.
                 */
                $table->boolean('mobile_ads_enabled')
                    ->default(true);

                $table->boolean('desktop_ads_enabled')
                    ->default(true);

                /*
                 * Native advertising.
                 *
                 * Example:
                 * 12 = insert one sponsored/native card
                 * after every 12 organic video cards.
                 */
                $table->boolean('native_ads_enabled')
                    ->default(true);

                $table->unsignedSmallInteger(
                    'native_ad_interval'
                )
                    ->default(12);

                /*
                 * Banner advertising.
                 */
                $table->boolean('banner_ads_enabled')
                    ->default(true);

                /*
                 * Xurvexa-controlled pre-roll.
                 *
                 * Provider policy is checked separately.
                 * Providers with their own player ads can
                 * automatically suppress Xurvexa pre-roll.
                 */
                $table->boolean('preroll_enabled')
                    ->default(true);

                $table->boolean(
                    'skip_preroll_when_provider_has_ads'
                )
                    ->default(true);

                $table->unsignedSmallInteger(
                    'preroll_skip_after_seconds'
                )
                    ->default(5);

                $table->unsignedSmallInteger(
                    'preroll_max_per_session'
                )
                    ->default(2);

                $table->unsignedInteger(
                    'preroll_cooldown_minutes'
                )
                    ->default(30);

                /*
                 * Protect the first meaningful video experience.
                 *
                 * false:
                 * Xurvexa pre-roll is skipped for the first
                 * video interaction of a visitor/session.
                 */
                $table->boolean(
                    'preroll_on_first_video'
                )
                    ->default(false);

                /*
                 * Mid-roll starts disabled.
                 */
                $table->boolean('midroll_enabled')
                    ->default(false);

                /*
                 * Popunder / clickunder.
                 *
                 * Starts conservatively:
                 * - not on first interaction
                 * - eligible after 2 meaningful interactions
                 * - at most once per session
                 * - at most once per 24 hours
                 */
                $table->boolean('popunder_enabled')
                    ->default(true);

                $table->unsignedSmallInteger(
                    'popunder_trigger_after_interactions'
                )
                    ->default(2);

                $table->unsignedInteger(
                    'popunder_frequency_minutes'
                )
                    ->default(1440);

                $table->unsignedSmallInteger(
                    'popunder_max_per_session'
                )
                    ->default(1);

                $table->unsignedSmallInteger(
                    'popunder_max_per_day'
                )
                    ->default(1);

                $table->boolean(
                    'popunder_mobile_enabled'
                )
                    ->default(true);

                $table->boolean(
                    'popunder_desktop_enabled'
                )
                    ->default(true);

                /*
                 * Interstitial starts disabled.
                 *
                 * Configuration already exists so the format
                 * can later be tested without a schema change.
                 */
                $table->boolean(
                    'interstitial_enabled'
                )
                    ->default(false);

                $table->unsignedSmallInteger(
                    'interstitial_trigger_after_interactions'
                )
                    ->default(3);

                $table->unsignedInteger(
                    'interstitial_frequency_minutes'
                )
                    ->default(1440);

                $table->unsignedSmallInteger(
                    'interstitial_max_per_session'
                )
                    ->default(1);

                /*
                 * Session interruption budget.
                 *
                 * Pre-roll, popunder and interstitial will
                 * consume this shared budget.
                 *
                 * Native and banner ads do not consume it.
                 */
                $table->unsignedSmallInteger(
                    'session_interruption_budget'
                )
                    ->default(2);

                /*
                 * Never enable sound-on autoplay advertising
                 * by default.
                 */
                $table->boolean(
                    'autoplay_sound_ads_enabled'
                )
                    ->default(false);

                /*
                 * Event collection for future revenue/session,
                 * play-rate, exit-rate and A/B analysis.
                 */
                $table->boolean(
                    'ad_event_tracking_enabled'
                )
                    ->default(true);

                /*
                 * Administrator-facing notes for temporary
                 * decisions, experiments or operational context.
                 */
                $table->text('notes')
                    ->nullable();

                $table->timestamps();
            }
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists(
            'monetization_settings'
        );
    }
};
