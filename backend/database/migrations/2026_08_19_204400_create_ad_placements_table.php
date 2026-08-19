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
            'ad_placements',
            function (Blueprint $table) {
                $table->id();

                /*
                 * Advertising network that owns
                 * this placement configuration.
                 */
                $table->foreignId(
                    'ad_network_id'
                )
                    ->constrained(
                        'ad_networks'
                    )
                    ->cascadeOnDelete();

                /*
                 * Stable Xurvexa placement key.
                 *
                 * Examples:
                 * video_banner
                 * video_preroll
                 * video_popunder
                 * home_native
                 */
                $table->string(
                    'placement_key',
                    100
                );

                /*
                 * Monetization format used by
                 * this placement.
                 *
                 * Application validation will
                 * restrict this to supported
                 * AdNetwork format constants.
                 */
                $table->string(
                    'format',
                    32
                );

                /*
                 * Placement-level emergency switch.
                 *
                 * A placement cannot serve unless
                 * both its network and this record
                 * are active.
                 */
                $table->boolean(
                    'is_active'
                )
                    ->default(false);

                /*
                 * Per-placement priority.
                 *
                 * Lower values are preferred.
                 * This allows different fallback
                 * order for different placements,
                 * independently of network priority.
                 */
                $table->unsignedSmallInteger(
                    'priority'
                )
                    ->default(100);

                /*
                 * Device-level controls.
                 *
                 * Global MonetizationSetting device
                 * rules are still evaluated first.
                 */
                $table->boolean(
                    'desktop_enabled'
                )
                    ->default(true);

                $table->boolean(
                    'mobile_enabled'
                )
                    ->default(true);

                /*
                 * Public network placement identifier.
                 *
                 * Examples:
                 * zone ID
                 * placement ID
                 * site/spot identifier
                 *
                 * This value may be exposed to the
                 * browser by a trusted driver.
                 *
                 * Never store passwords, API secrets,
                 * private tokens or signing keys here.
                 */
                $table->string(
                    'public_placement_id',
                    255
                )
                    ->nullable();

                /*
                 * Additional browser-safe network
                 * configuration when a single public
                 * placement ID is insufficient.
                 *
                 * Example values may include:
                 * width
                 * height
                 * public site ID
                 * safe rendering options
                 *
                 * Drivers must whitelist the keys they
                 * consume. Arbitrary script URLs must
                 * never be executed from this field.
                 */
                $table->json(
                    'public_config'
                )
                    ->nullable();

                /*
                 * Administrator-facing operational
                 * notes only.
                 *
                 * Never store credentials or private
                 * advertising-network secrets here.
                 */
                $table->text(
                    'notes'
                )
                    ->nullable();

                $table->timestamps();

                /*
                 * A network may define a given Xurvexa
                 * placement key only once.
                 *
                 * Different networks may each define
                 * their own video_banner placement,
                 * which enables future fallback.
                 */
                $table->unique(
                    [
                        'ad_network_id',
                        'placement_key',
                    ],
                    'ad_placements_network_key_unique'
                );

                $table->index(
                    [
                        'placement_key',
                        'format',
                        'is_active',
                    ],
                    'ad_placements_lookup_index'
                );

                $table->index(
                    [
                        'is_active',
                        'priority',
                    ],
                    'ad_placements_priority_index'
                );
            }
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists(
            'ad_placements'
        );
    }
};
