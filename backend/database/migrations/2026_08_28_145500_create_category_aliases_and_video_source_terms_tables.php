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
        Schema::create('category_aliases', function (Blueprint $table) {
            $table->id();

            $table->foreignId('category_id')
                ->constrained('categories')
                ->cascadeOnDelete();

            $table->string('source', 100)->default('*');
            $table->string('alias_type', 32)->default('tag');
            $table->string('alias', 500);
            $table->string('normalized_alias', 500);
            $table->smallInteger('priority')->default(100);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(
                ['source', 'alias_type', 'normalized_alias'],
                'category_aliases_source_type_alias_unique'
            );

            $table->index(
                ['category_id', 'is_active'],
                'category_aliases_category_active_index'
            );

            $table->index(
                ['source', 'alias_type', 'is_active'],
                'category_aliases_source_type_active_index'
            );
        });

        Schema::create('video_source_terms', function (Blueprint $table) {
            $table->id();

            $table->foreignId('video_id')
                ->constrained('videos')
                ->cascadeOnDelete();

            $table->string('source', 100);
            $table->string('term_type', 32)->default('tag');
            $table->string('term', 500);
            $table->string('normalized_term', 500);
            $table->timestamps();

            $table->unique(
                ['video_id', 'source', 'term_type', 'normalized_term'],
                'video_source_terms_video_source_type_term_unique'
            );

            $table->index(
                ['source', 'term_type', 'normalized_term'],
                'video_source_terms_lookup_index'
            );

            $table->index(
                ['video_id', 'term_type'],
                'video_source_terms_video_type_index'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('video_source_terms');
        Schema::dropIfExists('category_aliases');
    }
};
