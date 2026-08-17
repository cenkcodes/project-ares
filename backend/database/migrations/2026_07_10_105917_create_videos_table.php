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
        Schema::create('videos', function (Blueprint $table) {
            $table->id();

            $table->text('title');
            $table->string('slug')->unique();

            $table->text('description')->nullable();
            $table->text('embed_url');

            $table->string('video_source')->nullable();
            $table->text('thumbnail')->nullable();
            $table->unsignedInteger('duration')->nullable();

            $table->foreignId('category_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->unsignedBigInteger('views')
                ->default(0);

            $table->boolean('is_hd')
                ->default(false);

            $table->boolean('is_4k')
                ->default(false);

            $table->boolean('is_featured')
                ->default(false);

            $table->boolean('is_premium')
                ->default(false);

            $table->boolean('is_active')
                ->default(true);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('videos');
    }
};
