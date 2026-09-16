<?php

namespace App\Services\Seo;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class CategoryRelationBuilder
{
    public const RELATION_VERSION =
        'category-relation-v2.1';

    public const MAX_CANDIDATES_PER_CATEGORY = 12;

    public const MAX_PUBLISHED_PER_CATEGORY = 6;

    public const ENGINE_STAGE =
        'category-relations';

    public const ENGINE_VERSION =
        'category-relation-v2.1';

    /**
     * Rebuild the complete canonical category relation graph.
     *
     * Source of truth:
     *
     *     seo_video_category_memberships
     *
     * Provider-specific taxonomy logic is intentionally not repeated here.
     * Canonical membership normalization belongs to the upstream semantic
     * video membership worker.
     */
    public function rebuild(): array
    {
        $runUuid =
            (string) Str::uuid();

        $startedAt =
            now();

        $runId =
            DB::table(
                'seo_semantic_runs'
            )->insertGetId(
                [
                    'run_uuid' =>
                        $runUuid,

                    'engine_stage' =>
                        self::ENGINE_STAGE,

                    'run_mode' =>
                        'full',

                    'status' =>
                        'running',

                    'engine_version' =>
                        self::ENGINE_VERSION,

                    'claimed_count' =>
                        0,

                    'processed_count' =>
                        0,

                    'changed_count' =>
                        0,

                    'skipped_count' =>
                        0,

                    'failed_count' =>
                        0,

                    'started_at' =>
                        $startedAt,

                    'heartbeat_at' =>
                        $startedAt,

                    'completed_at' =>
                        null,

                    'last_error' =>
                        null,

                    'metadata' =>
                        json_encode(
                            $this->buildRunMetadata(),
                            JSON_THROW_ON_ERROR
                            | JSON_UNESCAPED_SLASHES
                            | JSON_UNESCAPED_UNICODE
                        ),

                    'created_at' =>
                        $startedAt,

                    'updated_at' =>
                        $startedAt,
                ]
            );

        try {
            $result =
                DB::transaction(
                    function (): array {
                        /*
                         * Capture dirty-category revisions represented by this
                         * rebuild.
                         *
                         * At acknowledgement time we delete only queue rows
                         * whose revision is still identical. If a new catalog
                         * change arrives during the rebuild, its incremented
                         * revision survives for a later run.
                         */
                        DB::statement(
                            <<<'SQL'
CREATE TEMP TABLE seo_relation_rebuild_claimed_categories
ON COMMIT DROP
AS
SELECT
    category_id,
    dirty_revision
FROM seo_catalog_dirty_categories
SQL
                        );

                        $claimedDirtyCategoryCount =
                            (int) DB::table(
                                'seo_relation_rebuild_claimed_categories'
                            )->count();

                        /*
                         * Atomic graph rebuild.
                         *
                         * Until this transaction commits, readers continue to
                         * see the previous committed relation graph.
                         */
                        DB::table(
                            'category_relations'
                        )->delete();

                        /*
                         * Binding order follows placeholder order inside SQL:
                         *
                         * 1. MAX_CANDIDATES_PER_CATEGORY
                         * 2. RELATION_VERSION
                         * 3. MAX_PUBLISHED_PER_CATEGORY
                         */
                        DB::statement(
                            $this->buildRelationInsertSql(),
                            [
                                self::MAX_CANDIDATES_PER_CATEGORY,
                                self::RELATION_VERSION,
                                self::MAX_PUBLISHED_PER_CATEGORY,
                            ]
                        );

                        /*
                         * Revision-safe acknowledgement.
                         *
                         * A category that became dirty again while the graph
                         * was being calculated remains in the queue.
                         */
                        $acknowledgedDirtyCategoryCount =
                            DB::delete(
                                <<<'SQL'
DELETE FROM seo_catalog_dirty_categories AS queue
USING seo_relation_rebuild_claimed_categories AS claimed
WHERE
    queue.category_id =
        claimed.category_id

    AND queue.dirty_revision =
        claimed.dirty_revision

    AND queue.processing_token IS NULL
SQL
                            );

                        $activeCategoryCount =
                            (int) DB::table(
                                'categories'
                            )
                                ->where(
                                    'is_active',
                                    true
                                )
                                ->count();

                        $relationCount =
                            (int) DB::table(
                                'category_relations'
                            )->count();

                        $publishedCount =
                            (int) DB::table(
                                'category_relations'
                            )
                                ->where(
                                    'is_published',
                                    true
                                )
                                ->count();

                        $categoryCount =
                            (int) DB::table(
                                'category_relations'
                            )
                                ->distinct()
                                ->count(
                                    'category_id'
                                );

                        $publishedCategoryCount =
                            (int) DB::table(
                                'category_relations'
                            )
                                ->where(
                                    'is_published',
                                    true
                                )
                                ->distinct()
                                ->count(
                                    'category_id'
                                );

                        $strongCount =
                            (int) DB::table(
                                'category_relations'
                            )
                                ->where(
                                    'quality_status',
                                    'strong'
                                )
                                ->count();

                        $contextualCount =
                            (int) DB::table(
                                'category_relations'
                            )
                                ->where(
                                    'quality_status',
                                    'contextual'
                                )
                                ->count();

                        $focusedCount =
                            (int) DB::table(
                                'category_relations'
                            )
                                ->where(
                                    'quality_status',
                                    'focused'
                                )
                                ->count();

                        $coverageCount =
                            (int) DB::table(
                                'category_relations'
                            )
                                ->where(
                                    'quality_status',
                                    'coverage'
                                )
                                ->count();

                        $candidateCount =
                            (int) DB::table(
                                'category_relations'
                            )
                                ->where(
                                    'quality_status',
                                    'candidate'
                                )
                                ->count();

                        return [
                            'active_category_count' =>
                                $activeCategoryCount,

                            'relation_count' =>
                                $relationCount,

                            'published_count' =>
                                $publishedCount,

                            'category_count' =>
                                $categoryCount,

                            'published_category_count' =>
                                $publishedCategoryCount,

                            'strong_count' =>
                                $strongCount,

                            'contextual_count' =>
                                $contextualCount,

                            'focused_count' =>
                                $focusedCount,

                            'coverage_count' =>
                                $coverageCount,

                            'candidate_count' =>
                                $candidateCount,

                            'claimed_dirty_category_count' =>
                                $claimedDirtyCategoryCount,

                            'acknowledged_dirty_category_count' =>
                                $acknowledgedDirtyCategoryCount,

                            'relation_version' =>
                                self::RELATION_VERSION,
                        ];
                    },
                    3
                );

            $completedAt =
                now();

            DB::table(
                'seo_semantic_runs'
            )
                ->where(
                    'id',
                    $runId
                )
                ->update(
                    [
                        'status' =>
                            'completed',

                        'claimed_count' =>
                            $result[
                                'claimed_dirty_category_count'
                            ],

                        'processed_count' =>
                            $result[
                                'active_category_count'
                            ],

                        'changed_count' =>
                            $result[
                                'relation_count'
                            ],

                        'skipped_count' =>
                            0,

                        'failed_count' =>
                            0,

                        'heartbeat_at' =>
                            $completedAt,

                        'completed_at' =>
                            $completedAt,

                        'last_error' =>
                            null,

                        'metadata' =>
                            json_encode(
                                array_merge(
                                    $this->buildRunMetadata(),
                                    [
                                        'relation_count' =>
                                            $result[
                                                'relation_count'
                                            ],

                                        'published_count' =>
                                            $result[
                                                'published_count'
                                            ],

                                        'published_category_count' =>
                                            $result[
                                                'published_category_count'
                                            ],

                                        'quality_counts' =>
                                            [
                                                'strong' =>
                                                    $result[
                                                        'strong_count'
                                                    ],

                                                'contextual' =>
                                                    $result[
                                                        'contextual_count'
                                                    ],

                                                'focused' =>
                                                    $result[
                                                        'focused_count'
                                                    ],

                                                'coverage' =>
                                                    $result[
                                                        'coverage_count'
                                                    ],

                                                'candidate' =>
                                                    $result[
                                                        'candidate_count'
                                                    ],
                                            ],

                                        'acknowledged_dirty_category_count' =>
                                            $result[
                                                'acknowledged_dirty_category_count'
                                            ],
                                    ]
                                ),
                                JSON_THROW_ON_ERROR
                                | JSON_UNESCAPED_SLASHES
                                | JSON_UNESCAPED_UNICODE
                            ),

                        'updated_at' =>
                            $completedAt,
                    ]
                );

            $result[
                'run_uuid'
            ] =
                $runUuid;

            return $result;
        } catch (Throwable $exception) {
            $completedAt =
                now();

            DB::table(
                'seo_semantic_runs'
            )
                ->where(
                    'id',
                    $runId
                )
                ->update(
                    [
                        'status' =>
                            'failed',

                        'failed_count' =>
                            1,

                        'heartbeat_at' =>
                            $completedAt,

                        'completed_at' =>
                            $completedAt,

                        'last_error' =>
                            Str::limit(
                                $exception->getMessage(),
                                4000,
                                ''
                            ),

                        'updated_at' =>
                            $completedAt,
                    ]
                );

            throw $exception;
        }
    }

    /**
     * Relation V2.1 quality model.
     *
     * strong:
     *     Multi-provider, directional and statistically meaningful.
     *
     * contextual:
     *     Broad multi-provider relationship allowed with slightly softer lift
     *     only when overlap and directional confidence remain substantial.
     *
     * focused:
     *     Single-provider evidence is allowed only under materially stricter
     *     support, confidence, Jaccard, lift and semantic-score requirements.
     *
     * coverage:
     *     High-volume directional overlap may tolerate modestly sub-1 lift,
     *     but only with broad provider evidence and strong overlap metrics.
     *
     * candidate:
     *     Stored only for diagnostics and future recalibration.
     */
    private function buildRelationInsertSql(): string
    {
        return <<<'SQL'
WITH active_memberships AS (
    SELECT
        memberships.video_id,
        memberships.category_id,
        memberships.video_source,
        memberships.evidence_count

    FROM seo_video_category_memberships
        AS memberships

    INNER JOIN videos AS videos
        ON videos.id =
            memberships.video_id

    INNER JOIN categories AS categories
        ON categories.id =
            memberships.category_id

    WHERE
        videos.is_active = TRUE
        AND categories.is_active = TRUE
),

catalog_stats AS (
    SELECT
        COUNT(
            DISTINCT video_id
        )::numeric
            AS total_video_count

    FROM active_memberships
),

category_stats AS (
    SELECT
        category_id,

        COUNT(*)::bigint
            AS video_count

    FROM active_memberships

    GROUP BY
        category_id
),

pair_stats AS (
    SELECT
        source.category_id,

        related.category_id
            AS related_category_id,

        COUNT(*)::bigint
            AS shared_video_count,

        COUNT(
            DISTINCT source.video_source
        )::integer
            AS shared_provider_count,

        SUM(
            source.evidence_count
            +
            related.evidence_count
        )::bigint
            AS evidence_count

    FROM active_memberships AS source

    INNER JOIN active_memberships AS related
        ON related.video_id =
            source.video_id

        AND related.category_id <>
            source.category_id

    GROUP BY
        source.category_id,
        related.category_id
),

metrics AS (
    SELECT
        pairs.category_id,

        pairs.related_category_id,

        source_stats.video_count
            AS source_video_count,

        related_stats.video_count
            AS related_video_count,

        pairs.shared_video_count,

        pairs.shared_provider_count,

        pairs.evidence_count,

        (
            pairs.shared_video_count::numeric
            /
            NULLIF(
                source_stats.video_count,
                0
            )
        ) AS source_confidence,

        (
            pairs.shared_video_count::numeric
            /
            NULLIF(
                related_stats.video_count,
                0
            )
        ) AS related_confidence,

        (
            pairs.shared_video_count::numeric
            /
            NULLIF(
                source_stats.video_count
                +
                related_stats.video_count
                -
                pairs.shared_video_count,
                0
            )
        ) AS jaccard_score,

        (
            pairs.shared_video_count::numeric
            *
            catalog.total_video_count
            /
            NULLIF(
                source_stats.video_count::numeric
                *
                related_stats.video_count::numeric,
                0
            )
        ) AS lift_score,

        (
            pairs.shared_video_count::numeric
            /
            NULLIF(
                SQRT(
                    source_stats.video_count::numeric
                    *
                    related_stats.video_count::numeric
                ),
                0
            )
        ) AS cosine_score

    FROM pair_stats AS pairs

    INNER JOIN category_stats
        AS source_stats
        ON source_stats.category_id =
            pairs.category_id

    INNER JOIN category_stats
        AS related_stats
        ON related_stats.category_id =
            pairs.related_category_id

    CROSS JOIN catalog_stats AS catalog
),

scored AS (
    SELECT
        metrics.*,

        GREATEST(
            12,
            CEIL(
                SQRT(
                    LEAST(
                        source_video_count,
                        related_video_count
                    )::numeric
                )
            )
        )::bigint
            AS minimum_shared_required,

        GREATEST(
            10,
            CEIL(
                SQRT(
                    LEAST(
                        source_video_count,
                        related_video_count
                    )::numeric
                )
                * 0.50
            )
        )::bigint
            AS minimum_candidate_shared,

        ROUND(
            (
                35.0 * jaccard_score
                +
                25.0 * source_confidence
                +
                10.0 * related_confidence
                +
                20.0 *
                    LEAST(
                        1.0,
                        GREATEST(
                            0.0,
                            (lift_score - 1.0)
                            / 3.0
                        )
                    )
                +
                10.0 * cosine_score
            )::numeric,
            6
        ) AS semantic_score

    FROM metrics
),

classified AS (
    SELECT
        scored.*,

        CASE
            /*
             * Tier 1 - STRONG
             */
            WHEN
                shared_video_count >=
                    GREATEST(
                        100,
                        minimum_shared_required
                    )

                AND shared_provider_count >= 2

                AND source_confidence >= 0.10

                AND jaccard_score >= 0.05

                AND lift_score >= 1.10

                AND semantic_score >= 10.00

            THEN 'strong'

            /*
             * Tier 2 - CONTEXTUAL
             */
            WHEN
                shared_video_count >= 500

                AND shared_provider_count >= 3

                AND source_confidence >= 0.18

                AND jaccard_score >= 0.07

                AND lift_score >= 0.85

                AND semantic_score >= 12.00

            THEN 'contextual'

            /*
             * Tier 3 - FOCUSED
             */
            WHEN
                shared_provider_count = 1

                AND shared_video_count >= 300

                AND source_confidence >= 0.20

                AND jaccard_score >= 0.05

                AND lift_score >= 1.15

                AND semantic_score >= 10.00

            THEN 'focused'

            /*
             * Tier 4 - COVERAGE
             */
            WHEN
                shared_video_count >= 1500

                AND shared_provider_count >= 2

                AND source_confidence >= 0.30

                AND jaccard_score >= 0.10

                AND lift_score >= 0.75

                AND semantic_score >= 15.00

            THEN 'coverage'

            ELSE 'candidate'
        END AS quality_status,

        CASE
            WHEN
                shared_video_count >=
                    GREATEST(
                        100,
                        minimum_shared_required
                    )

                AND shared_provider_count >= 2

                AND source_confidence >= 0.10

                AND jaccard_score >= 0.05

                AND lift_score >= 1.10

                AND semantic_score >= 10.00

            THEN 1

            WHEN
                shared_video_count >= 500

                AND shared_provider_count >= 3

                AND source_confidence >= 0.18

                AND jaccard_score >= 0.07

                AND lift_score >= 0.85

                AND semantic_score >= 12.00

            THEN 2

            WHEN
                shared_provider_count = 1

                AND shared_video_count >= 300

                AND source_confidence >= 0.20

                AND jaccard_score >= 0.05

                AND lift_score >= 1.15

                AND semantic_score >= 10.00

            THEN 3

            WHEN
                shared_video_count >= 1500

                AND shared_provider_count >= 2

                AND source_confidence >= 0.30

                AND jaccard_score >= 0.10

                AND lift_score >= 0.75

                AND semantic_score >= 15.00

            THEN 4

            ELSE 9
        END AS quality_tier

    FROM scored
),

candidate_pool AS (
    SELECT
        classified.*

    FROM classified

    WHERE
        shared_video_count >=
            minimum_candidate_shared

        AND semantic_score >= 1.50

        AND (
            source_confidence >= 0.02
            OR jaccard_score >= 0.01
            OR lift_score >= 1.10
        )
),

ranked AS (
    SELECT
        candidate_pool.*,

        ROW_NUMBER() OVER (
            PARTITION BY category_id

            ORDER BY
                CASE
                    WHEN quality_tier <= 4
                    THEN 0
                    ELSE 1
                END ASC,

                semantic_score DESC,

                shared_video_count DESC,

                related_category_id ASC
        )::integer
            AS relation_rank

    FROM candidate_pool
),

retained AS (
    SELECT
        ranked.*

    FROM ranked

    WHERE
        relation_rank <= ?
)

INSERT INTO category_relations (
    category_id,
    related_category_id,
    shared_video_count,
    evidence_count,
    relation_score,
    relation_version,
    calculated_at,
    created_at,
    updated_at,
    source_video_count,
    related_video_count,
    shared_provider_count,
    source_confidence,
    related_confidence,
    jaccard_score,
    lift_score,
    semantic_score,
    relation_rank,
    quality_status,
    is_published
)

SELECT
    retained.category_id,

    retained.related_category_id,

    LEAST(
        retained.shared_video_count,
        2147483647
    )::integer,

    LEAST(
        retained.evidence_count,
        2147483647
    )::integer,

    LEAST(
        ROUND(
            retained.semantic_score
            * 10000
        ),
        2147483647
    )::integer,

    ?,

    CURRENT_TIMESTAMP,
    CURRENT_TIMESTAMP,
    CURRENT_TIMESTAMP,

    retained.source_video_count,

    retained.related_video_count,

    retained.shared_provider_count,

    ROUND(
        retained.source_confidence,
        8
    ),

    ROUND(
        retained.related_confidence,
        8
    ),

    ROUND(
        retained.jaccard_score,
        8
    ),

    ROUND(
        retained.lift_score,
        8
    ),

    retained.semantic_score,

    retained.relation_rank,

    retained.quality_status,

    (
        retained.quality_tier <= 4
        AND retained.relation_rank <= ?
    )

FROM retained
SQL;
    }

    /**
     * Operational metadata stored with seo_semantic_runs.
     */
    private function buildRunMetadata(): array
    {
        return [
            'relation_version' =>
                self::RELATION_VERSION,

            'source' =>
                'seo_video_category_memberships',

            'max_candidates_per_category' =>
                self::MAX_CANDIDATES_PER_CATEGORY,

            'max_published_per_category' =>
                self::MAX_PUBLISHED_PER_CATEGORY,

            'ranking_model' =>
                'publishable-first-semantic-score',

            'quality_model' =>
                [
                    'strong' =>
                        [
                            'minimum_shared' =>
                                100,

                            'minimum_provider_count' =>
                                2,

                            'minimum_source_confidence' =>
                                0.10,

                            'minimum_jaccard' =>
                                0.05,

                            'minimum_lift' =>
                                1.10,

                            'minimum_semantic_score' =>
                                10.00,
                        ],

                    'contextual' =>
                        [
                            'minimum_shared' =>
                                500,

                            'minimum_provider_count' =>
                                3,

                            'minimum_source_confidence' =>
                                0.18,

                            'minimum_jaccard' =>
                                0.07,

                            'minimum_lift' =>
                                0.85,

                            'minimum_semantic_score' =>
                                12.00,
                        ],

                    'focused' =>
                        [
                            'single_provider_allowed' =>
                                true,

                            'minimum_shared' =>
                                300,

                            'minimum_source_confidence' =>
                                0.20,

                            'minimum_jaccard' =>
                                0.05,

                            'minimum_lift' =>
                                1.15,

                            'minimum_semantic_score' =>
                                10.00,
                        ],

                    'coverage' =>
                        [
                            'minimum_shared' =>
                                1500,

                            'minimum_provider_count' =>
                                2,

                            'minimum_source_confidence' =>
                                0.30,

                            'minimum_jaccard' =>
                                0.10,

                            'minimum_lift' =>
                                0.75,

                            'minimum_semantic_score' =>
                                15.00,
                        ],
                ],
        ];
    }
}
