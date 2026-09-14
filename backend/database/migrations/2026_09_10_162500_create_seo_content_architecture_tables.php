<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'video_seo_contents',
            function (Blueprint $table): void {
                $table->id();

                $table
                    ->foreignId('video_id')
                    ->unique()
                    ->constrained('videos')
                    ->cascadeOnDelete();

                $table
                    ->text('description');

                $table
                    ->string(
                        'meta_description',
                        500
                    )
                    ->nullable();

                $table
                    ->string(
                        'quality_status',
                        32
                    )
                    ->default('draft')
                    ->index();

                $table
                    ->string(
                        'generator_version',
                        64
                    )
                    ->default('seo-content-v1');

                $table
                    ->char(
                        'source_fingerprint',
                        64
                    )
                    ->nullable()
                    ->index();

                $table
                    ->char(
                        'content_hash',
                        64
                    )
                    ->nullable()
                    ->index();

                $table
                    ->timestamp('generated_at')
                    ->nullable()
                    ->index();

                $table
                    ->timestamp('published_at')
                    ->nullable()
                    ->index();

                $table->timestamps();
            }
        );

        Schema::create(
            'category_seo_contents',
            function (Blueprint $table): void {
                $table->id();

                $table
                    ->foreignId('category_id')
                    ->unique()
                    ->constrained('categories')
                    ->cascadeOnDelete();

                $table
                    ->text('intro')
                    ->nullable();

                $table
                    ->text('body')
                    ->nullable();

                $table
                    ->json('faq')
                    ->nullable();

                $table
                    ->string(
                        'meta_description',
                        500
                    )
                    ->nullable();

                $table
                    ->string(
                        'quality_status',
                        32
                    )
                    ->default('draft')
                    ->index();

                $table
                    ->string(
                        'generator_version',
                        64
                    )
                    ->default('seo-content-v1');

                $table
                    ->char(
                        'source_fingerprint',
                        64
                    )
                    ->nullable()
                    ->index();

                $table
                    ->char(
                        'content_hash',
                        64
                    )
                    ->nullable()
                    ->index();

                $table
                    ->timestamp('generated_at')
                    ->nullable()
                    ->index();

                $table
                    ->timestamp('published_at')
                    ->nullable()
                    ->index();

                $table->timestamps();
            }
        );

        Schema::create(
            'category_relations',
            function (Blueprint $table): void {
                $table->id();

                $table
                    ->foreignId('category_id')
                    ->constrained('categories')
                    ->cascadeOnDelete();

                $table
                    ->foreignId(
                        'related_category_id'
                    )
                    ->constrained('categories')
                    ->cascadeOnDelete();

                $table
                    ->unsignedInteger(
                        'shared_video_count'
                    )
                    ->default(0);

                $table
                    ->unsignedInteger(
                        'evidence_count'
                    )
                    ->default(0);

                $table
                    ->unsignedInteger(
                        'relation_score'
                    )
                    ->default(0)
                    ->index();

                $table
                    ->string(
                        'relation_version',
                        64
                    )
                    ->default(
                        'category-relation-v1'
                    );

                $table
                    ->timestamp(
                        'calculated_at'
                    )
                    ->nullable()
                    ->index();

                $table->timestamps();

                $table->unique(
                    [
                        'category_id',
                        'related_category_id',
                    ],
                    'category_relations_pair_unique'
                );

                $table->index(
                    [
                        'category_id',
                        'relation_score',
                    ],
                    'category_relations_score_index'
                );
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'category_relations'
        );

        Schema::dropIfExists(
            'category_seo_contents'
        );

        Schema::dropIfExists(
            'video_seo_contents'
        );
    }
};
