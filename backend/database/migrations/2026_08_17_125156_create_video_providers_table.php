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
        Schema::create('video_providers', function (Blueprint $table) {
            $table->id();

            /*
             * Human-readable provider name.
             *
             * Examples:
             * XVideos
             * Provider B
             * Xurvexa Hosted
             */
            $table->string('name', 100);

            /*
             * Machine-readable provider identifier.
             *
             * This will match videos.video_source.
             *
             * Example:
             * xvideos
             */
            $table->string('slug', 100)
                ->unique();

            $table->text('description')
                ->nullable();

            /*
             * Provider availability.
             *
             * This is separate from monetization so a provider
             * can remain active while all Xurvexa advertising
             * for that provider is disabled.
             */
            $table->boolean('is_active')
                ->default(true);

            /*
             * Master monetization switch for this provider.
             *
             * false:
             * All Xurvexa-controlled ad formats are skipped
             * for videos from this provider.
             */
            $table->boolean('monetization_enabled')
                ->default(true);

            /*
             * Does the provider's embedded player already
             * display its own advertising?
             *
             * Example:
             * XVideos = true
             */
            $table->boolean('has_own_ads')
                ->default(false);

            /*
             * Xurvexa-controlled ad format permissions.
             *
             * These are provider-level permissions only.
             * Global monetization settings and frequency rules
             * will be evaluated separately by the decision
             * engine.
             */
            $table->boolean('allow_xurvexa_preroll')
                ->default(true);

            $table->boolean('allow_xurvexa_midroll')
                ->default(false);

            $table->boolean('allow_popunder')
                ->default(true);

            $table->boolean('allow_native_ads')
                ->default(true);

            $table->boolean('allow_banner_ads')
                ->default(true);

            $table->boolean('allow_interstitial')
                ->default(false);

            /*
             * Internal administrator notes.
             *
             * Example:
             * "Provider currently serves its own pre-roll.
             * Keep Xurvexa pre-roll disabled."
             */
            $table->text('monetization_notes')
                ->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('video_providers');
    }
};
