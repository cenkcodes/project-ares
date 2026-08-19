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
            'ad_networks',
            function (Blueprint $table) {
                $table->id();

                /*
                 * Human-readable network name.
                 *
                 * Examples:
                 * TrafficStars
                 * ExoClick
                 * Adsterra
                 */
                $table->string(
                    'name',
                    100
                );

                /*
                 * Stable internal identifier.
                 *
                 * Used by application code,
                 * placements and analytics.
                 */
                $table->string(
                    'slug',
                    64
                )
                    ->unique();

                /*
                 * Frontend/server adapter identifier.
                 *
                 * This is not executable code and
                 * must never contain a script URL.
                 *
                 * Example:
                 * trafficstars
                 * exoclick
                 */
                $table->string(
                    'driver',
                    64
                );

                /*
                 * Emergency network-level switch.
                 *
                 * false:
                 * No placement belonging to this
                 * network may be used.
                 */
                $table->boolean(
                    'is_active'
                )
                    ->default(false);

                /*
                 * Lower values receive preference
                 * when more than one eligible
                 * network exists.
                 */
                $table->unsignedSmallInteger(
                    'priority'
                )
                    ->default(100);

                /*
                 * Supported ad formats.
                 *
                 * These describe network capability.
                 * Global MonetizationSetting and
                 * provider policy are evaluated
                 * separately.
                 */
                $table->boolean(
                    'supports_native'
                )
                    ->default(false);

                $table->boolean(
                    'supports_banner'
                )
                    ->default(false);

                $table->boolean(
                    'supports_preroll'
                )
                    ->default(false);

                $table->boolean(
                    'supports_midroll'
                )
                    ->default(false);

                $table->boolean(
                    'supports_popunder'
                )
                    ->default(false);

                $table->boolean(
                    'supports_interstitial'
                )
                    ->default(false);

                /*
                 * Operational/admin notes only.
                 *
                 * Do not store API secrets,
                 * passwords or private tokens here.
                 */
                $table->text(
                    'notes'
                )
                    ->nullable();

                $table->timestamps();

                $table->index([
                    'is_active',
                    'priority',
                ]);
            }
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists(
            'ad_networks'
        );
    }
};
