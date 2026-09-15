<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
        |--------------------------------------------------------------------------
        | 1. Durable video -> raw semantic term snapshot
        |--------------------------------------------------------------------------
        |
        | video_source_terms contains the current provider state.
        |
        | This table contains the last semantic state successfully processed by
        | the Semantic Intelligence worker.
        |
        | Why no video foreign key?
        |
        | If a video or its source terms are deleted, the worker must still be
        | able to inspect the OLD semantic state and calculate exactly which
        | terms, concepts, topics and category relationships were affected.
        |
        | The snapshot is removed only after the deletion has been reconciled.
        |
        */

        Schema::create(
            'seo_video_term_memberships',
            function (Blueprint $table): void {
                $table->id();

                $table
                    ->bigInteger(
                        'video_id'
                    );

                $table
                    ->string(
                        'source',
                        64
                    );

                $table
                    ->string(
                        'term_type',
                        64
                    );

                $table
                    ->string(
                        'normalized_term',
                        255
                    );

                $table
                    ->timestamp(
                        'calculated_at'
                    )
                    ->nullable();

                $table->timestamps();

                $table->unique(
                    [
                        'video_id',
                        'source',
                        'term_type',
                        'normalized_term',
                    ],
                    'seo_video_term_membership_uq'
                );

                $table->index(
                    [
                        'source',
                        'term_type',
                        'normalized_term',
                        'video_id',
                    ],
                    'seo_video_term_lookup_idx'
                );

                $table->index(
                    [
                        'video_id',
                        'calculated_at',
                    ],
                    'seo_video_term_video_idx'
                );
            }
        );

        /*
        |--------------------------------------------------------------------------
        | 2. Dirty category queue
        |--------------------------------------------------------------------------
        |
        | The video membership worker compares the previous and current
        | category snapshot.
        |
        | Only categories whose membership changed are written here.
        |
        | The Category Relation Engine can therefore recalculate a small number
        | of categories instead of rebuilding every relation after every video
        | import.
        |
        | No category FK is intentional. A deleted category must be allowed to
        | remain as a tombstone until downstream cleanup completes.
        |
        | change_mask:
        |
        |   1 = membership changed
        |   2 = taxonomy/category removed
        |   4 = full-reconcile derived change
        |
        */

        Schema::create(
            'seo_catalog_dirty_categories',
            function (Blueprint $table): void {
                $table
                    ->bigInteger(
                        'category_id'
                    )
                    ->primary();

                $table
                    ->integer(
                        'change_mask'
                    )
                    ->default(0);

                $table
                    ->bigInteger(
                        'dirty_revision'
                    )
                    ->default(1);

                $table
                    ->timestamp(
                        'first_dirty_at'
                    );

                $table
                    ->timestamp(
                        'last_dirty_at'
                    );

                $table
                    ->timestamp(
                        'available_at'
                    );

                $table
                    ->timestamp(
                        'processing_started_at'
                    )
                    ->nullable();

                $table
                    ->uuid(
                        'processing_token'
                    )
                    ->nullable();

                $table
                    ->integer(
                        'attempts'
                    )
                    ->default(0);

                $table
                    ->text(
                        'last_error'
                    )
                    ->nullable();

                $table->timestamps();

                $table->index(
                    [
                        'available_at',
                        'processing_started_at',
                    ],
                    'seo_dirty_category_available_idx'
                );

                $table->index(
                    [
                        'processing_started_at',
                        'processing_token',
                    ],
                    'seo_dirty_category_processing_idx'
                );

                $table->index(
                    'last_dirty_at',
                    'seo_dirty_category_last_idx'
                );
            }
        );

        /*
        |--------------------------------------------------------------------------
        | 3. Dirty semantic term queue
        |--------------------------------------------------------------------------
        |
        | Unknown and known provider terms both enter the same downstream
        | intelligence pipeline.
        |
        | This is important for future providers:
        |
        | A new provider can introduce a term Xurvexa has never seen before.
        | No provider-specific SEO implementation is required. The video worker
        | notices that the normalized term set changed and places that semantic
        | identity in this queue.
        |
        | The Concept Intelligence Engine later decides whether it is:
        |
        | - an existing concept alias,
        | - a new concept candidate,
        | - low-quality/noisy metadata,
        | - or something that should remain non-publishable.
        |
        | change_mask:
        |
        |   1 = term membership added
        |   2 = term membership removed
        |   4 = support/evidence changed
        |   8 = full-reconcile derived change
        |
        */

        Schema::create(
            'seo_catalog_dirty_terms',
            function (Blueprint $table): void {
                $table->id();

                $table
                    ->string(
                        'source',
                        64
                    );

                $table
                    ->string(
                        'term_type',
                        64
                    );

                $table
                    ->string(
                        'normalized_term',
                        255
                    );

                $table
                    ->integer(
                        'change_mask'
                    )
                    ->default(0);

                $table
                    ->bigInteger(
                        'dirty_revision'
                    )
                    ->default(1);

                $table
                    ->timestamp(
                        'first_dirty_at'
                    );

                $table
                    ->timestamp(
                        'last_dirty_at'
                    );

                $table
                    ->timestamp(
                        'available_at'
                    );

                $table
                    ->timestamp(
                        'processing_started_at'
                    )
                    ->nullable();

                $table
                    ->uuid(
                        'processing_token'
                    )
                    ->nullable();

                $table
                    ->integer(
                        'attempts'
                    )
                    ->default(0);

                $table
                    ->text(
                        'last_error'
                    )
                    ->nullable();

                $table->timestamps();

                /*
                 * One provider-scoped normalized semantic identity can have
                 * only one pending work item.
                 *
                 * Repeated imports merge into the same row by increasing
                 * dirty_revision.
                 */

                $table->unique(
                    [
                        'source',
                        'term_type',
                        'normalized_term',
                    ],
                    'seo_dirty_term_identity_uq'
                );

                $table->index(
                    [
                        'available_at',
                        'processing_started_at',
                    ],
                    'seo_dirty_term_available_idx'
                );

                $table->index(
                    [
                        'processing_started_at',
                        'processing_token',
                    ],
                    'seo_dirty_term_processing_idx'
                );

                $table->index(
                    [
                        'term_type',
                        'normalized_term',
                    ],
                    'seo_dirty_term_lookup_idx'
                );

                $table->index(
                    'last_dirty_at',
                    'seo_dirty_term_last_idx'
                );
            }
        );

        /*
        |--------------------------------------------------------------------------
        | 4. Semantic engine run history
        |--------------------------------------------------------------------------
        |
        | Sync-state answers:
        |
        |     "What is the current engine state?"
        |
        | Run history answers:
        |
        |     "What actually happened?"
        |
        | Examples:
        |
        | video-membership / incremental
        | category-relation / incremental
        | category-relation / full
        | term-concept / incremental
        | topic-intelligence / incremental
        | topic-intelligence / full
        | video-content / incremental
        | category-content / incremental
        | topic-content / incremental
        |
        | This table intentionally contains no provider-specific fields.
        |
        */

        Schema::create(
            'seo_semantic_runs',
            function (Blueprint $table): void {
                $table->id();

                $table
                    ->uuid(
                        'run_uuid'
                    )
                    ->unique();

                $table
                    ->string(
                        'engine_stage',
                        64
                    );

                $table
                    ->string(
                        'run_mode',
                        32
                    );

                $table
                    ->string(
                        'status',
                        32
                    )
                    ->default(
                        'running'
                    );

                $table
                    ->string(
                        'engine_version',
                        64
                    )
                    ->nullable();

                $table
                    ->integer(
                        'claimed_count'
                    )
                    ->default(0);

                $table
                    ->integer(
                        'processed_count'
                    )
                    ->default(0);

                $table
                    ->integer(
                        'changed_count'
                    )
                    ->default(0);

                $table
                    ->integer(
                        'skipped_count'
                    )
                    ->default(0);

                $table
                    ->integer(
                        'failed_count'
                    )
                    ->default(0);

                $table
                    ->timestamp(
                        'started_at'
                    );

                $table
                    ->timestamp(
                        'heartbeat_at'
                    )
                    ->nullable();

                $table
                    ->timestamp(
                        'completed_at'
                    )
                    ->nullable();

                $table
                    ->text(
                        'last_error'
                    )
                    ->nullable();

                $table
                    ->json(
                        'metadata'
                    )
                    ->nullable();

                $table->timestamps();

                $table->index(
                    [
                        'engine_stage',
                        'status',
                        'started_at',
                    ],
                    'seo_semantic_runs_stage_status_idx'
                );

                $table->index(
                    [
                        'run_mode',
                        'started_at',
                    ],
                    'seo_semantic_runs_mode_idx'
                );

                $table->index(
                    'heartbeat_at',
                    'seo_semantic_runs_heartbeat_idx'
                );
            }
        );
    }

    public function down(): void
    {
        /*
        |--------------------------------------------------------------------------
        | Reverse dependency order
        |--------------------------------------------------------------------------
        */

        Schema::dropIfExists(
            'seo_semantic_runs'
        );

        Schema::dropIfExists(
            'seo_catalog_dirty_terms'
        );

        Schema::dropIfExists(
            'seo_catalog_dirty_categories'
        );

        Schema::dropIfExists(
            'seo_video_term_memberships'
        );
    }
};
