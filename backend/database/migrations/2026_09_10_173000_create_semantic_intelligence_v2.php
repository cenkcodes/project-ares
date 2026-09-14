<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('videos', function (Blueprint $table): void {
            $table->index(
                ['updated_at', 'id'],
                'videos_semantic_watermark_idx'
            );

            $table->index(
                ['category_id', 'is_active'],
                'videos_category_active_idx'
            );
        });

        Schema::table('video_source_terms', function (Blueprint $table): void {
            $table->index(
                ['updated_at', 'id'],
                'source_terms_semantic_watermark_idx'
            );
        });

        Schema::create('seo_semantic_sync_states', function (Blueprint $table): void {
            $table->id();
            $table->string('engine_key', 64)->unique();
            $table->string('engine_version', 64)
                ->default('semantic-intelligence-v2');
            $table->boolean('full_rebuild_required')->default(true);
            $table->boolean('taxonomy_dirty')->default(true);
            $table->timestamp('taxonomy_dirty_at')->nullable();
            $table->timestamp('last_incremental_started_at')->nullable();
            $table->timestamp('last_incremental_completed_at')->nullable();
            $table->timestamp('last_full_reconcile_started_at')->nullable();
            $table->timestamp('last_full_reconcile_completed_at')->nullable();
            $table->bigInteger('last_video_id')->default(0);
            $table->timestamp('last_video_updated_at')->nullable();
            $table->bigInteger('last_source_term_id')->default(0);
            $table->timestamp('last_source_term_updated_at')->nullable();
            $table->text('last_error')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('seo_catalog_dirty_videos', function (Blueprint $table): void {
            $table->bigInteger('video_id')->primary();
            $table->integer('change_mask')->default(0);
            $table->bigInteger('dirty_revision')->default(1);
            $table->timestamp('first_dirty_at');
            $table->timestamp('last_dirty_at');
            $table->timestamp('available_at');
            $table->timestamp('processing_started_at')->nullable();
            $table->uuid('processing_token')->nullable();
            $table->integer('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(
                ['available_at', 'processing_started_at'],
                'seo_dirty_available_idx'
            );

            $table->index(
                ['processing_started_at', 'processing_token'],
                'seo_dirty_processing_idx'
            );

            $table->index(
                'last_dirty_at',
                'seo_dirty_last_dirty_idx'
            );
        });

        Schema::create('seo_video_category_memberships', function (Blueprint $table): void {
            $table->id();

            /*
             * Intentionally no FK on video_id.
             *
             * If a video is deleted, its previous semantic membership must
             * remain available until the incremental worker consumes the
             * deletion tombstone and recalculates affected relations/topics.
             */
            $table->bigInteger('video_id');

            $table->foreignId('category_id')
                ->constrained('categories')
                ->cascadeOnDelete();

            $table->string('video_source', 64)->nullable();
            $table->integer('evidence_count')->default(1);
            $table->boolean('has_primary')->default(false);
            $table->boolean('has_alias')->default(false);
            $table->char('source_fingerprint', 64)->nullable();
            $table->timestamp('calculated_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['video_id', 'category_id'],
                'seo_video_category_pair_uq'
            );

            $table->index(
                ['category_id', 'video_id'],
                'seo_video_category_lookup_idx'
            );

            $table->index(
                ['video_source', 'category_id'],
                'seo_video_category_source_idx'
            );
        });

        Schema::table('category_relations', function (Blueprint $table): void {
            $table->bigInteger('source_video_count')->default(0);
            $table->bigInteger('related_video_count')->default(0);
            $table->integer('shared_provider_count')->default(0);

            $table->decimal(
                'source_confidence',
                12,
                8
            )->default(0);

            $table->decimal(
                'related_confidence',
                12,
                8
            )->default(0);

            $table->decimal(
                'jaccard_score',
                12,
                8
            )->default(0);

            $table->decimal(
                'lift_score',
                18,
                8
            )->default(0);

            $table->decimal(
                'semantic_score',
                18,
                8
            )->default(0);

            $table->integer('relation_rank')->nullable();

            $table->string(
                'quality_status',
                32
            )->default('candidate');

            $table->boolean('is_published')->default(false);

            $table->index(
                [
                    'category_id',
                    'is_published',
                    'relation_rank',
                ],
                'category_relations_publish_idx'
            );

            $table->index(
                [
                    'quality_status',
                    'semantic_score',
                ],
                'category_relations_quality_idx'
            );
        });

        /*
         * Semantic concepts solve synonym/variant problems.
         *
         * Example:
         *   concept = stepmom
         *
         * aliases:
         *   stepmom
         *   step-mom
         *   stepmother
         *   step-mother
         *
         * A topic references the concept, not every spelling separately.
         */
        Schema::create('seo_term_concepts', function (Blueprint $table): void {
            $table->id();
            $table->string('concept_key', 160)->unique();
            $table->string('display_name', 255);

            $table->string(
                'concept_type',
                32
            )->default('term');

            $table->string(
                'quality_status',
                32
            )->default('candidate');

            $table->boolean('is_active')->default(true);
            $table->bigInteger('support_video_count')->default(0);
            $table->integer('provider_count')->default(0);
            $table->integer('alias_count')->default(0);

            $table->decimal(
                'semantic_score',
                18,
                8
            )->default(0);

            $table->char('source_fingerprint', 64)->nullable();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('calculated_at')->nullable();
            $table->timestamps();

            $table->index(
                [
                    'quality_status',
                    'semantic_score',
                ],
                'seo_term_concepts_quality_idx'
            );

            $table->index(
                [
                    'is_active',
                    'support_video_count',
                ],
                'seo_term_concepts_active_support_idx'
            );
        });

        Schema::create('seo_term_concept_aliases', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('concept_id')
                ->constrained('seo_term_concepts')
                ->cascadeOnDelete();

            /*
             * "*" is global.
             * A provider-specific mapping can coexist with the global mapping.
             */
            $table->string('source', 64)->default('*');
            $table->string('term_type', 64)->default('tag');
            $table->string('normalized_term', 255);
            $table->string('raw_example', 255)->nullable();
            $table->bigInteger('video_count')->default(0);
            $table->integer('provider_count')->default(0);

            $table->decimal(
                'confidence_score',
                12,
                8
            )->default(0);

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            /*
             * One provider-scoped source-term identity maps to exactly one
             * semantic concept. This prevents silent alias collisions.
             */
            $table->unique(
                [
                    'source',
                    'term_type',
                    'normalized_term',
                ],
                'seo_concept_alias_identity_uq'
            );

            $table->index(
                [
                    'concept_id',
                    'is_active',
                ],
                'seo_concept_alias_concept_idx'
            );

            $table->index(
                [
                    'normalized_term',
                    'term_type',
                ],
                'seo_concept_alias_lookup_idx'
            );
        });

        Schema::create('seo_topics', function (Blueprint $table): void {
            $table->id();
            $table->string('slug', 255)->unique();
            $table->char('signature_hash', 64)->unique();
            $table->string('topic_type', 32);

            $table->foreignId('primary_category_id')
                ->nullable()
                ->constrained('categories')
                ->nullOnDelete();

            $table->string('title', 255);

            $table->string(
                'quality_status',
                32
            )->default('candidate');

            $table->boolean('is_indexable')->default(false);
            $table->bigInteger('support_video_count')->default(0);
            $table->integer('provider_count')->default(0);
            $table->integer('component_count')->default(0);

            $table->decimal(
                'confidence_score',
                12,
                8
            )->default(0);

            $table->decimal(
                'jaccard_score',
                12,
                8
            )->default(0);

            $table->decimal(
                'lift_score',
                18,
                8
            )->default(0);

            $table->decimal(
                'semantic_score',
                18,
                8
            )->default(0);

            $table->string(
                'generator_version',
                64
            )->default('topic-intelligence-v1');

            $table->char('source_fingerprint', 64)->nullable();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('calculated_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index(
                [
                    'topic_type',
                    'quality_status',
                ],
                'seo_topics_type_quality_idx'
            );

            $table->index(
                [
                    'is_indexable',
                    'semantic_score',
                ],
                'seo_topics_indexable_score_idx'
            );

            $table->index(
                [
                    'primary_category_id',
                    'semantic_score',
                ],
                'seo_topics_category_score_idx'
            );

            $table->index(
                'last_seen_at',
                'seo_topics_last_seen_idx'
            );
        });

        /*
         * A topic is composed from semantic components.
         *
         * Example:
         *
         *   Anal + Stepmom
         *
         * component 1:
         *   type = category
         *   category_id = Anal
         *
         * component 2:
         *   type = concept
         *   concept_id = Stepmom
         *
         * This gives us true AND semantics while each concept can internally
         * contain multiple equivalent aliases using OR semantics.
         */
        Schema::create('seo_topic_components', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('topic_id')
                ->constrained('seo_topics')
                ->cascadeOnDelete();

            $table->string('component_type', 32);
            $table->string('component_key', 255);

            $table->string(
                'component_role',
                32
            )->default('required');

            $table->integer('component_order')->default(0);

            $table->foreignId('category_id')
                ->nullable()
                ->constrained('categories')
                ->cascadeOnDelete();

            $table->foreignId('concept_id')
                ->nullable()
                ->constrained('seo_term_concepts')
                ->cascadeOnDelete();

            $table->bigInteger('support_video_count')->default(0);
            $table->integer('provider_count')->default(0);
            $table->timestamps();

            $table->unique(
                [
                    'topic_id',
                    'component_key',
                ],
                'seo_topic_component_uq'
            );

            $table->index(
                [
                    'category_id',
                    'topic_id',
                ],
                'seo_topic_component_category_idx'
            );

            $table->index(
                [
                    'concept_id',
                    'topic_id',
                ],
                'seo_topic_component_concept_idx'
            );
        });

        DB::statement(
            <<<'SQL'
ALTER TABLE seo_topic_components
ADD CONSTRAINT seo_topic_component_target_check
CHECK (
    (
        component_type = 'category'
        AND category_id IS NOT NULL
        AND concept_id IS NULL
    )
    OR
    (
        component_type = 'concept'
        AND concept_id IS NOT NULL
        AND category_id IS NULL
    )
)
SQL
        );

        Schema::create('seo_topic_videos', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('topic_id')
                ->constrained('seo_topics')
                ->cascadeOnDelete();

            /*
             * Intentionally no video FK.
             *
             * Deleted videos remain as temporary tombstone memberships until
             * the incremental engine reconciles the affected topic.
             */
            $table->bigInteger('video_id');

            $table->decimal(
                'relevance_score',
                18,
                8
            )->default(0);

            $table->integer('evidence_count')->default(0);
            $table->integer('matched_component_count')->default(0);
            $table->timestamp('calculated_at')->nullable();
            $table->timestamps();

            $table->unique(
                [
                    'topic_id',
                    'video_id',
                ],
                'seo_topic_video_uq'
            );

            $table->index(
                [
                    'video_id',
                    'topic_id',
                ],
                'seo_topic_video_reverse_idx'
            );

            $table->index(
                [
                    'topic_id',
                    'relevance_score',
                ],
                'seo_topic_video_rank_idx'
            );
        });

        Schema::create('seo_topic_contents', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('topic_id')
                ->unique()
                ->constrained('seo_topics')
                ->cascadeOnDelete();

            $table->text('intro')->nullable();
            $table->text('body')->nullable();
            $table->json('faq')->nullable();

            $table->string(
                'meta_description',
                500
            )->nullable();

            $table->string(
                'quality_status',
                32
            )->default('draft');

            $table->string(
                'generator_version',
                64
            )->default('seo-content-v1');

            $table->char('source_fingerprint', 64)->nullable();
            $table->char('content_hash', 64)->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index(
                'quality_status',
                'seo_topic_content_quality_idx'
            );

            $table->index(
                'source_fingerprint',
                'seo_topic_content_source_idx'
            );
        });

        DB::table('seo_semantic_sync_states')->insert([
            'engine_key' =>
                'semantic-intelligence',

            'engine_version' =>
                'semantic-intelligence-v2',

            'full_rebuild_required' =>
                true,

            'taxonomy_dirty' =>
                true,

            'taxonomy_dirty_at' =>
                now(),

            'metadata' =>
                json_encode(
                    [
                        'relation_engine' =>
                            'category-relation-v2',

                        'topic_engine' =>
                            'topic-intelligence-v1',

                        'dirty_tracking' =>
                            'database-trigger-v2',

                        'queue_concurrency' =>
                            'revision-and-lease',

                        'topic_model' =>
                            'concept-components-v1',
                    ],
                    JSON_UNESCAPED_SLASHES
                ),

            'created_at' =>
                now(),

            'updated_at' =>
                now(),
        ]);

        /*
         |--------------------------------------------------------------------------
         | Central dirty queue function
         |--------------------------------------------------------------------------
         |
         | Every data source eventually changes videos/video_source_terms.
         | Therefore provider-specific SEO hooks are unnecessary.
         |
         | dirty_revision protects against this race:
         |
         | worker reads revision 10
         | new import changes same video -> revision becomes 11
         | worker finishes revision 10
         | worker is NOT allowed to delete revision 11
         |
         | This is the core no-lost-update guarantee.
         |
         */

        DB::unprepared(
            <<<'SQL'
CREATE OR REPLACE FUNCTION xurvexa_seo_upsert_dirty(
    p_video_id bigint,
    p_change_mask integer
)
RETURNS void
LANGUAGE plpgsql
AS $$
BEGIN
    IF p_video_id IS NULL THEN
        RETURN;
    END IF;

    INSERT INTO seo_catalog_dirty_videos (
        video_id,
        change_mask,
        dirty_revision,
        first_dirty_at,
        last_dirty_at,
        available_at,
        processing_started_at,
        processing_token,
        attempts,
        last_error,
        created_at,
        updated_at
    )
    VALUES (
        p_video_id,
        p_change_mask,
        1,
        CURRENT_TIMESTAMP,
        CURRENT_TIMESTAMP,
        CURRENT_TIMESTAMP,
        NULL,
        NULL,
        0,
        NULL,
        CURRENT_TIMESTAMP,
        CURRENT_TIMESTAMP
    )
    ON CONFLICT (video_id)
    DO UPDATE SET
        change_mask =
            seo_catalog_dirty_videos.change_mask
            |
            EXCLUDED.change_mask,

        dirty_revision =
            seo_catalog_dirty_videos.dirty_revision
            + 1,

        last_dirty_at =
            EXCLUDED.last_dirty_at,

        available_at =
            LEAST(
                seo_catalog_dirty_videos.available_at,
                EXCLUDED.available_at
            ),

        attempts =
            0,

        last_error =
            NULL,

        updated_at =
            CURRENT_TIMESTAMP;
END;
$$;

CREATE OR REPLACE FUNCTION xurvexa_seo_mark_video_dirty()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        PERFORM xurvexa_seo_upsert_dirty(
            OLD.id,
            5
        );

        RETURN OLD;
    END IF;

    PERFORM xurvexa_seo_upsert_dirty(
        NEW.id,
        1
    );

    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS seo_video_dirty_after_insert
ON videos;

CREATE TRIGGER seo_video_dirty_after_insert
AFTER INSERT
ON videos
FOR EACH ROW
EXECUTE FUNCTION xurvexa_seo_mark_video_dirty();

DROP TRIGGER IF EXISTS seo_video_dirty_after_update
ON videos;

CREATE TRIGGER seo_video_dirty_after_update
AFTER UPDATE OF
    title,
    slug,
    description,
    video_source,
    duration,
    category_id,
    is_hd,
    is_4k,
    is_active
ON videos
FOR EACH ROW
WHEN (
    OLD.title IS DISTINCT FROM NEW.title
    OR OLD.slug IS DISTINCT FROM NEW.slug
    OR OLD.description IS DISTINCT FROM NEW.description
    OR OLD.video_source IS DISTINCT FROM NEW.video_source
    OR OLD.duration IS DISTINCT FROM NEW.duration
    OR OLD.category_id IS DISTINCT FROM NEW.category_id
    OR OLD.is_hd IS DISTINCT FROM NEW.is_hd
    OR OLD.is_4k IS DISTINCT FROM NEW.is_4k
    OR OLD.is_active IS DISTINCT FROM NEW.is_active
)
EXECUTE FUNCTION xurvexa_seo_mark_video_dirty();

DROP TRIGGER IF EXISTS seo_video_dirty_after_delete
ON videos;

CREATE TRIGGER seo_video_dirty_after_delete
AFTER DELETE
ON videos
FOR EACH ROW
EXECUTE FUNCTION xurvexa_seo_mark_video_dirty();
SQL
        );

        /*
         |--------------------------------------------------------------------------
         | Source-term INSERT / DELETE / UPDATE tracking
         |--------------------------------------------------------------------------
         |
         | INSERT and DELETE use PostgreSQL transition tables. A provider may
         | import dozens of tags per video; we produce one dirty upsert per
         | affected video rather than one per source-term row.
         |
         */

        DB::unprepared(
            <<<'SQL'
CREATE OR REPLACE FUNCTION xurvexa_seo_mark_source_terms_insert_dirty()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    PERFORM xurvexa_seo_upsert_dirty(
        affected.video_id,
        2
    )
    FROM (
        SELECT DISTINCT
            video_id
        FROM new_rows
        WHERE
            video_id IS NOT NULL
    ) AS affected;

    RETURN NULL;
END;
$$;

DROP TRIGGER IF EXISTS seo_source_term_dirty_after_insert
ON video_source_terms;

CREATE TRIGGER seo_source_term_dirty_after_insert
AFTER INSERT
ON video_source_terms
REFERENCING NEW TABLE AS new_rows
FOR EACH STATEMENT
EXECUTE FUNCTION xurvexa_seo_mark_source_terms_insert_dirty();

CREATE OR REPLACE FUNCTION xurvexa_seo_mark_source_terms_delete_dirty()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    PERFORM xurvexa_seo_upsert_dirty(
        affected.video_id,
        2
    )
    FROM (
        SELECT DISTINCT
            video_id
        FROM old_rows
        WHERE
            video_id IS NOT NULL
    ) AS affected;

    RETURN NULL;
END;
$$;

DROP TRIGGER IF EXISTS seo_source_term_dirty_after_delete
ON video_source_terms;

CREATE TRIGGER seo_source_term_dirty_after_delete
AFTER DELETE
ON video_source_terms
REFERENCING OLD TABLE AS old_rows
FOR EACH STATEMENT
EXECUTE FUNCTION xurvexa_seo_mark_source_terms_delete_dirty();

CREATE OR REPLACE FUNCTION xurvexa_seo_mark_source_terms_update_dirty()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    PERFORM xurvexa_seo_upsert_dirty(
        affected.video_id,
        2
    )
    FROM (
        WITH changed_rows AS (
            SELECT
                old_rows.video_id
                    AS old_video_id,

                new_rows.video_id
                    AS new_video_id

            FROM old_rows

            FULL OUTER JOIN new_rows
                ON new_rows.id =
                    old_rows.id

            WHERE
                old_rows.id IS NULL

                OR new_rows.id IS NULL

                OR old_rows.video_id
                    IS DISTINCT FROM
                    new_rows.video_id

                OR old_rows.source
                    IS DISTINCT FROM
                    new_rows.source

                OR old_rows.term_type
                    IS DISTINCT FROM
                    new_rows.term_type

                OR old_rows.term
                    IS DISTINCT FROM
                    new_rows.term

                OR old_rows.normalized_term
                    IS DISTINCT FROM
                    new_rows.normalized_term
        )

        SELECT
            old_video_id
                AS video_id

        FROM changed_rows

        WHERE
            old_video_id IS NOT NULL

        UNION

        SELECT
            new_video_id
                AS video_id

        FROM changed_rows

        WHERE
            new_video_id IS NOT NULL
    ) AS affected;

    RETURN NULL;
END;
$$;

DROP TRIGGER IF EXISTS seo_source_term_dirty_after_update
ON video_source_terms;

CREATE TRIGGER seo_source_term_dirty_after_update
AFTER UPDATE
ON video_source_terms
REFERENCING OLD TABLE AS old_rows
NEW TABLE AS new_rows
FOR EACH STATEMENT
EXECUTE FUNCTION xurvexa_seo_mark_source_terms_update_dirty();
SQL
        );

        /*
         |--------------------------------------------------------------------------
         | Taxonomy invalidation
         |--------------------------------------------------------------------------
         |
         | A category/alias change can affect thousands of videos.
         |
         | We therefore do NOT flood the dirty-video queue.
         | We mark the whole semantic taxonomy for controlled reconciliation.
         |
         | Non-semantic category edits such as meta_description are excluded.
         |
         */

        DB::unprepared(
            <<<'SQL'
CREATE OR REPLACE FUNCTION xurvexa_seo_mark_taxonomy_dirty()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    UPDATE seo_semantic_sync_states
    SET
        taxonomy_dirty = TRUE,
        taxonomy_dirty_at = CURRENT_TIMESTAMP,
        full_rebuild_required = TRUE,
        updated_at = CURRENT_TIMESTAMP
    WHERE
        engine_key = 'semantic-intelligence';

    RETURN NULL;
END;
$$;

DROP TRIGGER IF EXISTS seo_categories_taxonomy_dirty_insert_delete
ON categories;

CREATE TRIGGER seo_categories_taxonomy_dirty_insert_delete
AFTER INSERT OR DELETE
ON categories
FOR EACH STATEMENT
EXECUTE FUNCTION xurvexa_seo_mark_taxonomy_dirty();

DROP TRIGGER IF EXISTS seo_categories_taxonomy_dirty_update
ON categories;

CREATE TRIGGER seo_categories_taxonomy_dirty_update
AFTER UPDATE OF
    name,
    slug,
    is_active
ON categories
FOR EACH STATEMENT
EXECUTE FUNCTION xurvexa_seo_mark_taxonomy_dirty();

DROP TRIGGER IF EXISTS seo_aliases_taxonomy_dirty_insert_delete
ON category_aliases;

CREATE TRIGGER seo_aliases_taxonomy_dirty_insert_delete
AFTER INSERT OR DELETE
ON category_aliases
FOR EACH STATEMENT
EXECUTE FUNCTION xurvexa_seo_mark_taxonomy_dirty();

DROP TRIGGER IF EXISTS seo_aliases_taxonomy_dirty_update
ON category_aliases;

CREATE TRIGGER seo_aliases_taxonomy_dirty_update
AFTER UPDATE OF
    category_id,
    source,
    alias_type,
    normalized_alias,
    is_active
ON category_aliases
FOR EACH STATEMENT
EXECUTE FUNCTION xurvexa_seo_mark_taxonomy_dirty();
SQL
        );
    }

    public function down(): void
    {
        DB::unprepared(
            <<<'SQL'
DROP TRIGGER IF EXISTS seo_aliases_taxonomy_dirty_update
ON category_aliases;

DROP TRIGGER IF EXISTS seo_aliases_taxonomy_dirty_insert_delete
ON category_aliases;

DROP TRIGGER IF EXISTS seo_categories_taxonomy_dirty_update
ON categories;

DROP TRIGGER IF EXISTS seo_categories_taxonomy_dirty_insert_delete
ON categories;

DROP TRIGGER IF EXISTS seo_source_term_dirty_after_update
ON video_source_terms;

DROP TRIGGER IF EXISTS seo_source_term_dirty_after_delete
ON video_source_terms;

DROP TRIGGER IF EXISTS seo_source_term_dirty_after_insert
ON video_source_terms;

DROP TRIGGER IF EXISTS seo_video_dirty_after_delete
ON videos;

DROP TRIGGER IF EXISTS seo_video_dirty_after_update
ON videos;

DROP TRIGGER IF EXISTS seo_video_dirty_after_insert
ON videos;

DROP FUNCTION IF EXISTS xurvexa_seo_mark_taxonomy_dirty();

DROP FUNCTION IF EXISTS xurvexa_seo_mark_source_terms_update_dirty();

DROP FUNCTION IF EXISTS xurvexa_seo_mark_source_terms_delete_dirty();

DROP FUNCTION IF EXISTS xurvexa_seo_mark_source_terms_insert_dirty();

DROP FUNCTION IF EXISTS xurvexa_seo_mark_video_dirty();

DROP FUNCTION IF EXISTS xurvexa_seo_upsert_dirty(
    bigint,
    integer
);
SQL
        );

        Schema::dropIfExists(
            'seo_topic_contents'
        );

        Schema::dropIfExists(
            'seo_topic_videos'
        );

        Schema::dropIfExists(
            'seo_topic_components'
        );

        Schema::dropIfExists(
            'seo_topics'
        );

        Schema::dropIfExists(
            'seo_term_concept_aliases'
        );

        Schema::dropIfExists(
            'seo_term_concepts'
        );

        Schema::table(
            'category_relations',
            function (Blueprint $table): void {
                $table->dropIndex(
                    'category_relations_publish_idx'
                );

                $table->dropIndex(
                    'category_relations_quality_idx'
                );

                $table->dropColumn(
                    [
                        'source_video_count',
                        'related_video_count',
                        'shared_provider_count',
                        'source_confidence',
                        'related_confidence',
                        'jaccard_score',
                        'lift_score',
                        'semantic_score',
                        'relation_rank',
                        'quality_status',
                        'is_published',
                    ]
                );
            }
        );

        Schema::dropIfExists(
            'seo_video_category_memberships'
        );

        Schema::dropIfExists(
            'seo_catalog_dirty_videos'
        );

        Schema::dropIfExists(
            'seo_semantic_sync_states'
        );

        Schema::table(
            'video_source_terms',
            function (Blueprint $table): void {
                $table->dropIndex(
                    'source_terms_semantic_watermark_idx'
                );
            }
        );

        Schema::table(
            'videos',
            function (Blueprint $table): void {
                $table->dropIndex(
                    'videos_semantic_watermark_idx'
                );

                $table->dropIndex(
                    'videos_category_active_idx'
                );
            }
        );
    }
};
