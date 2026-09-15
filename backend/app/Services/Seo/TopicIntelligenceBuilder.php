<?php

namespace App\Services\Seo;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class TopicIntelligenceBuilder
{
    public const ENGINE_VERSION = 'topic-intelligence-v1';

    public const QUALITY_RULESET_VERSION = 'topic-quality-v1.1';

    public const ENGINE_STAGE = 'topic-intelligence';

    private const SYNC_ENGINE_KEY = 'topic-intelligence';

    private const CONCEPT_ENGINE_KEY = 'concept-intelligence';

    private const CONCEPT_ENGINE_VERSION = 'concept-intelligence-v1';

    private const TOPIC_TYPE = 'category-concept';

    private const CANDIDATE_MIN_SHARED = 100;

    private const CANDIDATE_MIN_PROVIDERS = 2;

    private const CANDIDATE_MIN_JACCARD = 0.03;

    private const CANDIDATE_MIN_LIFT = 1.20;

    private const STRONG_MIN_SHARED = 250;

    private const STRONG_MIN_PROVIDERS = 3;

    private const STRONG_MIN_JACCARD = 0.05;

    private const STRONG_MIN_LIFT = 1.30;

    private const FOCUSED_MIN_SHARED = 150;

    private const FOCUSED_MIN_PROVIDERS = 2;

    private const FOCUSED_MIN_JACCARD = 0.04;

    private const FOCUSED_MIN_LIFT = 1.50;

    private const FOCUSED_MIN_CONCEPT_COVERAGE = 0.25;

    private const MIN_INDEXABLE_SEMANTIC_SCORE = 45.0;

    private const MAX_INDEXABLE_PER_CATEGORY = 6;

    /**
     * Broad or provider-derived terms that may remain useful Concept
     * Intelligence signals but must never create an indexable Topic page by
     * themselves.
     */
    private const GENERIC_HOLD_TERMS = [
        'ass',
        'cum',
        'free-porn',
        'free-porn-movies',
        'free-sex',
        'fuck',
        'full-porn',
        'head',
        'hot',
        'mouth',
        'naked',
        'nude',
        'porn',
        'porn-movies',
        'porno',
        'pussy',
        'sex',
        'sexy',
        'tits',
        'tube',
        'tube-porn',
        'video',
        'videos',
        'x-video',
        'x-videos',
        'xnxxx',
        'xvideos',
        'xvideos-com',
        'xxnx',
        'xxx',
    ];

    /**
     * Topic distinct-intent normalization only. These groups do not merge
     * Concept Intelligence rows; they only prevent obvious synonym duplicates
     * such as Big Tits + Big Boobs from becoming a second landing page.
     */
    private const INTENT_TOKEN_EQUIVALENTS = [
        'boob' => 'breast',
        'boobs' => 'breast',
        'breast' => 'breast',
        'breasts' => 'breast',
        'tit' => 'breast',
        'tits' => 'breast',
        'busty' => 'big-breast',

        'ass' => 'butt',
        'asses' => 'butt',
        'booty' => 'butt',
        'butt' => 'butt',
        'butts' => 'butt',

        'cock' => 'penis',
        'cocks' => 'penis',
        'dick' => 'penis',
        'dicks' => 'penis',
        'penis' => 'penis',

        'amateurs' => 'amateur',
        'massages' => 'massage',

        'ebony' => 'black',
        'fat' => 'bbw',
    ];

    /**
     * Rebuild the category + concept Topic Intelligence layer.
     *
     * The operation is intentionally a compact full reconciliation at the
     * current catalog scale. It upserts stable topic identities, retires stale
     * topics without deleting future editorial content, and materializes video
     * membership only for quality-gated indexable topics.
     */
    public function rebuildAll(bool $dryRun = false): array
    {
        $this->assertConceptIntelligenceReady();

        $runUuid = (string) Str::uuid();
        $startedAt = now();
        $inputFingerprint = $this->inputFingerprint();
        $runId = null;

        if (! $dryRun) {
            $this->ensureTopicSyncState();

            $runId = $this->createRun(
                $runUuid,
                $startedAt,
                [
                    'dry_run' => false,
                    'input_fingerprint' => $inputFingerprint,
                    'quality_ruleset_version' =>
                        self::QUALITY_RULESET_VERSION,
                    'candidate_gate' => $this->candidateGateMetadata(),
                    'strong_gate' => $this->strongGateMetadata(),
                    'focused_gate' => $this->focusedGateMetadata(),
                    'max_indexable_per_category' =>
                        self::MAX_INDEXABLE_PER_CATEGORY,
                ]
            );

            $this->markSyncStarted($startedAt);
        }

        try {
            $result = $this->runTransactional(
                function () use (
                    $startedAt,
                    $inputFingerprint,
                    $dryRun
                ): array {
                    if ($dryRun) {
                        $this->ensureTopicSyncState();
                    }

                    $pairs = $this->candidatePairs();
                    $topics = $this->classifyAndRankTopics($pairs);

                    $topicStats = $this->upsertTopics($topics);
                    $this->retireMissingTopics($topics);
                    $componentCount = $this->replaceTopicComponents($topics);
                    $topicVideoCount = $this->replaceTopicVideos($topics);

                    $completedAt = now();

                    $result = [
                        'run_mode' => 'full',
                        'engine_version' => self::ENGINE_VERSION,
                        'quality_ruleset_version' =>
                            self::QUALITY_RULESET_VERSION,
                        'candidate_pair_count' => count($pairs),
                        'topic_count' => $topicStats['topic_count'],
                        'active_topic_count' => $topicStats['active_topic_count'],
                        'strong_topic_count' => $topicStats['strong_topic_count'],
                        'focused_topic_count' => $topicStats['focused_topic_count'],
                        'candidate_topic_count' => $topicStats['candidate_topic_count'],
                        'generic_hold_topic_count' =>
                            $topicStats['generic_hold_topic_count'],
                        'redundant_hold_topic_count' =>
                            $topicStats['redundant_hold_topic_count'],
                        'indexable_topic_count' =>
                            $topicStats['indexable_topic_count'],
                        'indexable_category_count' =>
                            $topicStats['indexable_category_count'],
                        'component_count' => $componentCount,
                        'topic_video_count' => $topicVideoCount,
                        'input_fingerprint' => $inputFingerprint,
                        'started_at' => $startedAt->toDateTimeString(),
                        'completed_at' => $completedAt->toDateTimeString(),
                    ];

                    $this->markSyncCompleted(
                        $completedAt,
                        $result
                    );

                    return $result;
                },
                $dryRun
            );

            $result['run_uuid'] = $runUuid;
            $result['dry_run'] = $dryRun;

            if (! $dryRun && $runId !== null) {
                $this->completeRun(
                    $runId,
                    $result
                );
            }

            return $result;
        } catch (Throwable $exception) {
            if (! $dryRun && $runId !== null) {
                $this->failRun(
                    $runId,
                    $exception
                );

                $this->markSyncFailed($exception);
            }

            throw $exception;
        }
    }

    /**
     * Read candidate category + concept pairs using the exact metrics calibrated
     * against the current production catalog.
     */
    private function candidatePairs(): array
    {
        $sql =
            <<<'SQL'
WITH eligible_concepts AS (
    SELECT
        id,
        concept_key,
        display_name,
        quality_status,
        support_video_count,
        provider_count,
        semantic_score,
        source_fingerprint
    FROM seo_term_concepts
    WHERE
        is_active = TRUE
        AND concept_type = 'term'
        AND quality_status IN (
            'strong',
            'qualified'
        )
),
concept_video_memberships AS (
    SELECT DISTINCT
        a.concept_id,
        tvm.video_id,
        tvm.source
    FROM seo_term_concept_aliases a
    INNER JOIN eligible_concepts ec
        ON ec.id = a.concept_id
    INNER JOIN seo_video_term_memberships tvm
        ON tvm.source = a.source
        AND tvm.term_type = a.term_type
        AND tvm.normalized_term = a.normalized_term
    WHERE
        a.is_active = TRUE
),
category_sizes AS (
    SELECT
        category_id,
        COUNT(DISTINCT video_id)::bigint AS category_video_count,
        COUNT(DISTINCT video_source)::integer AS category_provider_count
    FROM seo_video_category_memberships
    GROUP BY category_id
),
pair_support AS (
    SELECT
        vcm.category_id,
        cvm.concept_id,
        COUNT(DISTINCT cvm.video_id)::bigint AS shared_video_count,
        COUNT(DISTINCT cvm.source)::integer AS shared_provider_count
    FROM seo_video_category_memberships vcm
    INNER JOIN concept_video_memberships cvm
        ON cvm.video_id = vcm.video_id
    GROUP BY
        vcm.category_id,
        cvm.concept_id
),
universe AS (
    SELECT
        COUNT(DISTINCT video_id)::numeric AS video_count
    FROM seo_video_category_memberships
),
pair_metrics AS (
    SELECT
        ps.category_id,
        c.slug AS category_slug,
        c.name AS category_name,
        cs.category_video_count,
        cs.category_provider_count,

        ec.id AS concept_id,
        ec.concept_key,
        ec.display_name AS concept_display_name,
        ec.quality_status AS concept_quality_status,
        ec.support_video_count AS concept_video_count,
        ec.provider_count AS concept_provider_count,
        ec.semantic_score AS concept_semantic_score,
        ec.source_fingerprint AS concept_source_fingerprint,

        ps.shared_video_count,
        ps.shared_provider_count,

        (
            ps.shared_video_count::numeric
            / NULLIF(cs.category_video_count, 0)
        ) AS category_coverage,

        (
            ps.shared_video_count::numeric
            / NULLIF(ec.support_video_count, 0)
        ) AS concept_coverage,

        (
            ps.shared_video_count::numeric
            / NULLIF(
                cs.category_video_count
                + ec.support_video_count
                - ps.shared_video_count,
                0
            )
        ) AS jaccard_score,

        (
            (
                ps.shared_video_count::numeric
                / NULLIF(cs.category_video_count, 0)
            )
            /
            NULLIF(
                (
                    ec.support_video_count::numeric
                    / NULLIF(universe.video_count, 0)
                ),
                0
            )
        ) AS lift_score

    FROM pair_support ps
    INNER JOIN categories c
        ON c.id = ps.category_id
        AND c.is_active = TRUE
    INNER JOIN category_sizes cs
        ON cs.category_id = ps.category_id
    INNER JOIN eligible_concepts ec
        ON ec.id = ps.concept_id
    CROSS JOIN universe
)
SELECT *
FROM pair_metrics
WHERE
    shared_video_count >= ?
    AND shared_provider_count >= ?
    AND jaccard_score >= ?
    AND lift_score >= ?
ORDER BY
    category_id,
    shared_video_count DESC,
    jaccard_score DESC,
    lift_score DESC,
    concept_id
SQL;

        return DB::select(
            $sql,
            [
                self::CANDIDATE_MIN_SHARED,
                self::CANDIDATE_MIN_PROVIDERS,
                self::CANDIDATE_MIN_JACCARD,
                self::CANDIDATE_MIN_LIFT,
            ]
        );
    }

    private function classifyAndRankTopics(array $pairs): array
    {
        $topics = [];

        foreach ($pairs as $pair) {
            $conceptKey = (string) $pair->concept_key;
            $normalizedTerm = $this->normalizedTermFromConceptKey($conceptKey);
            $categorySlug = (string) $pair->category_slug;

            $sharedVideoCount = (int) $pair->shared_video_count;
            $sharedProviderCount = (int) $pair->shared_provider_count;
            $categoryCoverage = (float) $pair->category_coverage;
            $conceptCoverage = (float) $pair->concept_coverage;
            $jaccardScore = (float) $pair->jaccard_score;
            $liftScore = (float) $pair->lift_score;
            $conceptSemanticScore = (float) $pair->concept_semantic_score;

            $semanticScore = $this->topicSemanticScore(
                $sharedProviderCount,
                $categoryCoverage,
                $conceptCoverage,
                $jaccardScore,
                $liftScore,
                $conceptSemanticScore
            );

            $genericHold = $this->isGenericHold($normalizedTerm);
            $redundantHold = $this->isRedundantIntent(
                $categorySlug,
                $normalizedTerm
            );

            $qualityStatus = 'candidate';

            if ($genericHold) {
                $qualityStatus = 'generic_hold';
            } elseif ($redundantHold) {
                $qualityStatus = 'redundant_hold';
            } elseif (
                $this->passesStrongGate(
                    $sharedVideoCount,
                    $sharedProviderCount,
                    $jaccardScore,
                    $liftScore,
                    $semanticScore
                )
            ) {
                $qualityStatus = 'strong';
            } elseif (
                $this->passesFocusedGate(
                    $sharedVideoCount,
                    $sharedProviderCount,
                    $conceptCoverage,
                    $jaccardScore,
                    $liftScore,
                    $semanticScore
                )
            ) {
                $qualityStatus = 'focused';
            }

            $signatureHash = hash(
                'sha256',
                implode(
                    '|',
                    [
                        self::ENGINE_VERSION,
                        self::TOPIC_TYPE,
                        (string) $pair->category_id,
                        (string) $pair->concept_id,
                    ]
                )
            );

            $slug = Str::slug(
                $categorySlug
                . '-'
                . $normalizedTerm
            );

            $title = $this->buildTopicTitle(
                (string) $pair->category_name,
                $categorySlug,
                (string) $pair->concept_display_name,
                $normalizedTerm
            );

            $sourceFingerprint = hash(
                'sha256',
                implode(
                    '|',
                    [
                        self::ENGINE_VERSION,
                        self::QUALITY_RULESET_VERSION,
                        (string) $pair->category_id,
                        $categorySlug,
                        (string) $pair->concept_id,
                        $conceptKey,
                        (string) ($pair->concept_source_fingerprint ?? ''),
                        (string) $sharedVideoCount,
                        (string) $sharedProviderCount,
                        number_format($categoryCoverage, 8, '.', ''),
                        number_format($conceptCoverage, 8, '.', ''),
                        number_format($jaccardScore, 8, '.', ''),
                        number_format($liftScore, 8, '.', ''),
                        number_format($semanticScore, 8, '.', ''),
                        $qualityStatus,
                    ]
                )
            );

            $topics[] = [
                'signature_hash' => $signatureHash,
                'slug' => $slug,
                'title' => $title,
                'topic_type' => self::TOPIC_TYPE,
                'category_id' => (int) $pair->category_id,
                'category_slug' => $categorySlug,
                'category_name' => (string) $pair->category_name,
                'category_video_count' => (int) $pair->category_video_count,
                'category_provider_count' => (int) $pair->category_provider_count,
                'concept_id' => (int) $pair->concept_id,
                'concept_key' => $conceptKey,
                'concept_display_name' => (string) $pair->concept_display_name,
                'concept_quality_status' =>
                    (string) $pair->concept_quality_status,
                'concept_video_count' => (int) $pair->concept_video_count,
                'concept_provider_count' => (int) $pair->concept_provider_count,
                'shared_video_count' => $sharedVideoCount,
                'shared_provider_count' => $sharedProviderCount,
                'category_coverage' => $categoryCoverage,
                'concept_coverage' => $conceptCoverage,
                'jaccard_score' => $jaccardScore,
                'lift_score' => $liftScore,
                'semantic_score' => $semanticScore,
                'confidence_score' => min(1.0, $semanticScore / 100.0),
                'quality_status' => $qualityStatus,
                'is_indexable' => false,
                'source_fingerprint' => $sourceFingerprint,
            ];
        }

        $eligibleByCategory = [];

        foreach ($topics as $index => $topic) {
            if (
                ! in_array(
                    $topic['quality_status'],
                    ['strong', 'focused'],
                    true
                )
            ) {
                continue;
            }

            $eligibleByCategory[$topic['category_id']][] = $index;
        }

        foreach ($eligibleByCategory as $indices) {
            usort(
                $indices,
                function (int $left, int $right) use ($topics): int {
                    $a = $topics[$left];
                    $b = $topics[$right];

                    return
                        $b['semantic_score'] <=> $a['semantic_score']
                        ?: $b['shared_video_count'] <=> $a['shared_video_count']
                        ?: $b['jaccard_score'] <=> $a['jaccard_score']
                        ?: strcmp(
                            $a['concept_key'],
                            $b['concept_key']
                        );
                }
            );

            foreach (
                array_slice(
                    $indices,
                    0,
                    self::MAX_INDEXABLE_PER_CATEGORY
                )
                as $index
            ) {
                $topics[$index]['is_indexable'] = true;
            }
        }

        return $topics;
    }

    private function upsertTopics(array $topics): array
    {
        $timestamp = now();
        $rows = [];
        $indexableCategories = [];

        $stats = [
            'topic_count' => count($topics),
            'active_topic_count' => count($topics),
            'strong_topic_count' => 0,
            'focused_topic_count' => 0,
            'candidate_topic_count' => 0,
            'generic_hold_topic_count' => 0,
            'redundant_hold_topic_count' => 0,
            'indexable_topic_count' => 0,
            'indexable_category_count' => 0,
        ];

        foreach ($topics as $topic) {
            $qualityStatus = $topic['quality_status'];

            if ($qualityStatus === 'strong') {
                $stats['strong_topic_count']++;
            } elseif ($qualityStatus === 'focused') {
                $stats['focused_topic_count']++;
            } elseif ($qualityStatus === 'generic_hold') {
                $stats['generic_hold_topic_count']++;
            } elseif ($qualityStatus === 'redundant_hold') {
                $stats['redundant_hold_topic_count']++;
            } else {
                $stats['candidate_topic_count']++;
            }

            if ($topic['is_indexable']) {
                $stats['indexable_topic_count']++;
                $indexableCategories[$topic['category_id']] = true;
            }

            $rows[] = [
                'slug' => $topic['slug'],
                'signature_hash' => $topic['signature_hash'],
                'topic_type' => self::TOPIC_TYPE,
                'primary_category_id' => $topic['category_id'],
                'title' => $topic['title'],
                'quality_status' => $qualityStatus,
                'is_indexable' => $topic['is_indexable'],
                'support_video_count' => $topic['shared_video_count'],
                'provider_count' => $topic['shared_provider_count'],
                'component_count' => 2,
                'confidence_score' => $topic['confidence_score'],
                'jaccard_score' => $topic['jaccard_score'],
                'lift_score' => $topic['lift_score'],
                'semantic_score' => $topic['semantic_score'],
                'generator_version' => self::ENGINE_VERSION,
                'source_fingerprint' => $topic['source_fingerprint'],
                'first_seen_at' => $timestamp,
                'last_seen_at' => $timestamp,
                'calculated_at' => $timestamp,
                'published_at' => null,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];

            if (count($rows) >= 250) {
                $this->upsertTopicChunk($rows);
                $rows = [];
            }
        }

        if ($rows !== []) {
            $this->upsertTopicChunk($rows);
        }

        $stats['indexable_category_count'] = count($indexableCategories);

        return $stats;
    }

    private function upsertTopicChunk(array $rows): void
    {
        DB::table('seo_topics')->upsert(
            $rows,
            ['signature_hash'],
            [
                'slug',
                'topic_type',
                'primary_category_id',
                'title',
                'quality_status',
                'is_indexable',
                'support_video_count',
                'provider_count',
                'component_count',
                'confidence_score',
                'jaccard_score',
                'lift_score',
                'semantic_score',
                'generator_version',
                'source_fingerprint',
                'last_seen_at',
                'calculated_at',
                'updated_at',
            ]
        );
    }

    private function retireMissingTopics(array $topics): void
    {
        $signatures = array_column(
            $topics,
            'signature_hash'
        );

        $query = DB::table('seo_topics')
            ->where(
                'generator_version',
                self::ENGINE_VERSION
            )
            ->where(
                'topic_type',
                self::TOPIC_TYPE
            );

        if ($signatures !== []) {
            $query->whereNotIn(
                'signature_hash',
                $signatures
            );
        }

        $query->update(
            [
                'quality_status' => 'retired',
                'is_indexable' => false,
                'support_video_count' => 0,
                'provider_count' => 0,
                'component_count' => 0,
                'confidence_score' => 0,
                'jaccard_score' => 0,
                'lift_score' => 0,
                'semantic_score' => 0,
                'last_seen_at' => null,
                'calculated_at' => now(),
                'published_at' => null,
                'updated_at' => now(),
            ]
        );
    }

    private function replaceTopicComponents(array $topics): int
    {
        $topicMap = $this->topicIdMap();
        $engineTopicIds = array_values($topicMap);

        if ($engineTopicIds !== []) {
            foreach (
                array_chunk(
                    $engineTopicIds,
                    500
                )
                as $chunk
            ) {
                DB::table('seo_topic_components')
                    ->whereIn('topic_id', $chunk)
                    ->delete();
            }
        }

        $timestamp = now();
        $rows = [];
        $count = 0;

        foreach ($topics as $topic) {
            $topicId = $topicMap[$topic['signature_hash']] ?? null;

            if ($topicId === null) {
                throw new RuntimeException(
                    'Topic ID missing after upsert for signature '
                    . $topic['signature_hash']
                );
            }

            $rows[] = [
                'topic_id' => $topicId,
                'component_type' => 'category',
                'component_key' =>
                    'category:' . $topic['category_slug'],
                'component_role' => 'primary',
                'component_order' => 0,
                'category_id' => $topic['category_id'],
                'concept_id' => null,
                'support_video_count' => $topic['category_video_count'],
                'provider_count' => $topic['category_provider_count'],
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];

            $rows[] = [
                'topic_id' => $topicId,
                'component_type' => 'concept',
                'component_key' => $topic['concept_key'],
                'component_role' => 'required',
                'component_order' => 1,
                'category_id' => null,
                'concept_id' => $topic['concept_id'],
                'support_video_count' => $topic['concept_video_count'],
                'provider_count' => $topic['concept_provider_count'],
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];

            $count += 2;

            if (count($rows) >= 500) {
                DB::table('seo_topic_components')->insert($rows);
                $rows = [];
            }
        }

        if ($rows !== []) {
            DB::table('seo_topic_components')->insert($rows);
        }

        return $count;
    }

    private function replaceTopicVideos(array $topics): int
    {
        $topicMap = $this->topicIdMap();
        $engineTopicIds = array_values($topicMap);

        if ($engineTopicIds !== []) {
            foreach (
                array_chunk(
                    $engineTopicIds,
                    500
                )
                as $chunk
            ) {
                DB::table('seo_topic_videos')
                    ->whereIn('topic_id', $chunk)
                    ->delete();
            }
        }

        DB::statement(
            <<<'SQL'
CREATE TEMP TABLE tmp_ti_indexable_topics (
    topic_id bigint PRIMARY KEY,
    category_id bigint NOT NULL,
    concept_id bigint NOT NULL,
    semantic_score numeric(18, 8) NOT NULL
)
ON COMMIT DROP
SQL
        );

        $rows = [];

        foreach ($topics as $topic) {
            if (! $topic['is_indexable']) {
                continue;
            }

            $topicId = $topicMap[$topic['signature_hash']] ?? null;

            if ($topicId === null) {
                throw new RuntimeException(
                    'Indexable topic ID missing after upsert.'
                );
            }

            $rows[] = [
                'topic_id' => $topicId,
                'category_id' => $topic['category_id'],
                'concept_id' => $topic['concept_id'],
                'semantic_score' => $topic['semantic_score'],
            ];
        }

        foreach (array_chunk($rows, 250) as $chunk) {
            DB::table('tmp_ti_indexable_topics')->insert($chunk);
        }

        if ($rows === []) {
            return 0;
        }

        DB::statement(
            <<<'SQL'
INSERT INTO seo_topic_videos (
    topic_id,
    video_id,
    relevance_score,
    evidence_count,
    matched_component_count,
    calculated_at,
    created_at,
    updated_at
)
SELECT
    stage.topic_id,
    vcm.video_id,
    stage.semantic_score,
    GREATEST(
        2,
        MAX(vcm.evidence_count)
        + COUNT(DISTINCT a.id)
    )::integer AS evidence_count,
    2 AS matched_component_count,
    CURRENT_TIMESTAMP,
    CURRENT_TIMESTAMP,
    CURRENT_TIMESTAMP
FROM tmp_ti_indexable_topics stage
INNER JOIN seo_video_category_memberships vcm
    ON vcm.category_id = stage.category_id
INNER JOIN videos v
    ON v.id = vcm.video_id
    AND v.is_active = TRUE
INNER JOIN seo_term_concept_aliases a
    ON a.concept_id = stage.concept_id
    AND a.is_active = TRUE
INNER JOIN seo_video_term_memberships tvm
    ON tvm.video_id = vcm.video_id
    AND tvm.source = a.source
    AND tvm.term_type = a.term_type
    AND tvm.normalized_term = a.normalized_term
GROUP BY
    stage.topic_id,
    vcm.video_id,
    stage.semantic_score
SQL
        );

        return (int) DB::table('seo_topic_videos as tv')
            ->join(
                'seo_topics as t',
                't.id',
                '=',
                'tv.topic_id'
            )
            ->where(
                't.generator_version',
                self::ENGINE_VERSION
            )
            ->count();
    }

    private function topicIdMap(): array
    {
        return DB::table('seo_topics')
            ->where(
                'generator_version',
                self::ENGINE_VERSION
            )
            ->where(
                'topic_type',
                self::TOPIC_TYPE
            )
            ->pluck(
                'id',
                'signature_hash'
            )
            ->map(
                static fn ($id): int => (int) $id
            )
            ->all();
    }

    private function passesStrongGate(
        int $sharedVideoCount,
        int $sharedProviderCount,
        float $jaccardScore,
        float $liftScore,
        float $semanticScore
    ): bool {
        return
            $sharedVideoCount >= self::STRONG_MIN_SHARED
            && $sharedProviderCount >= self::STRONG_MIN_PROVIDERS
            && $jaccardScore >= self::STRONG_MIN_JACCARD
            && $liftScore >= self::STRONG_MIN_LIFT
            && $semanticScore >= self::MIN_INDEXABLE_SEMANTIC_SCORE;
    }

    private function passesFocusedGate(
        int $sharedVideoCount,
        int $sharedProviderCount,
        float $conceptCoverage,
        float $jaccardScore,
        float $liftScore,
        float $semanticScore
    ): bool {
        return
            $sharedVideoCount >= self::FOCUSED_MIN_SHARED
            && $sharedProviderCount >= self::FOCUSED_MIN_PROVIDERS
            && $conceptCoverage >= self::FOCUSED_MIN_CONCEPT_COVERAGE
            && $jaccardScore >= self::FOCUSED_MIN_JACCARD
            && $liftScore >= self::FOCUSED_MIN_LIFT
            && $semanticScore >= self::MIN_INDEXABLE_SEMANTIC_SCORE;
    }

    private function topicSemanticScore(
        int $sharedProviderCount,
        float $categoryCoverage,
        float $conceptCoverage,
        float $jaccardScore,
        float $liftScore,
        float $conceptSemanticScore
    ): float {
        $jaccardComponent =
            25.0 * min(1.0, max(0.0, $jaccardScore / 0.20));

        $categoryCoverageComponent =
            20.0 * min(1.0, max(0.0, $categoryCoverage / 0.25));

        $conceptCoverageComponent =
            20.0 * min(1.0, max(0.0, $conceptCoverage / 0.50));

        $liftComponent =
            15.0 * min(
                1.0,
                max(
                    0.0,
                    ($liftScore - 1.0) / 2.0
                )
            );

        $providerComponent =
            10.0 * min(
                1.0,
                max(
                    0.0,
                    $sharedProviderCount / 4.0
                )
            );

        $conceptComponent =
            10.0 * min(
                1.0,
                max(
                    0.0,
                    $conceptSemanticScore / 100.0
                )
            );

        return round(
            $jaccardComponent
            + $categoryCoverageComponent
            + $conceptCoverageComponent
            + $liftComponent
            + $providerComponent
            + $conceptComponent,
            8
        );
    }

    private function isGenericHold(string $normalizedTerm): bool
    {
        return in_array(
            $normalizedTerm,
            self::GENERIC_HOLD_TERMS,
            true
        );
    }

    private function isRedundantIntent(
        string $categorySlug,
        string $normalizedTerm
    ): bool {
        return
            $this->intentSignature($categorySlug)
            === $this->intentSignature($normalizedTerm);
    }

    private function intentSignature(string $value): string
    {
        $tokens = array_values(
            array_filter(
                explode('-', strtolower($value)),
                static fn (string $token): bool => $token !== ''
            )
        );

        $normalized = [];

        foreach ($tokens as $token) {
            $normalized[] =
                self::INTENT_TOKEN_EQUIVALENTS[$token]
                ?? $token;
        }

        return implode('-', $normalized);
    }

    private function buildTopicTitle(
        string $categoryName,
        string $categorySlug,
        string $conceptDisplayName,
        string $normalizedTerm
    ): string {
        $categorySignature =
            $this->intentSignature($categorySlug);

        $conceptSignature =
            $this->intentSignature($normalizedTerm);

        $conceptAlreadyCarriesCategory =
            $conceptSignature === $categorySignature
            || str_starts_with(
                $conceptSignature,
                $categorySignature . '-'
            )
            || str_ends_with(
                $conceptSignature,
                '-' . $categorySignature
            )
            || str_contains(
                $conceptSignature,
                '-' . $categorySignature . '-'
            );

        $baseTitle =
            $conceptAlreadyCarriesCategory
                ? $conceptDisplayName
                : trim(
                    $categoryName
                    . ' '
                    . $conceptDisplayName
                );

        return trim(
            $baseTitle
            . ' Videos'
        );
    }

    private function normalizedTermFromConceptKey(string $conceptKey): string
    {
        if (! str_starts_with($conceptKey, 'term:')) {
            throw new RuntimeException(
                'Unsupported Concept Intelligence key: '
                . $conceptKey
            );
        }

        $term = substr($conceptKey, 5);

        if ($term === '') {
            throw new RuntimeException(
                'Concept Intelligence term key is empty.'
            );
        }

        return $term;
    }

    private function assertConceptIntelligenceReady(): void
    {
        $state = DB::table('seo_semantic_sync_states')
            ->where(
                'engine_key',
                self::CONCEPT_ENGINE_KEY
            )
            ->first();

        if ($state === null) {
            throw new RuntimeException(
                'Concept Intelligence sync state is missing.'
            );
        }

        if (
            (string) $state->engine_version
            !== self::CONCEPT_ENGINE_VERSION
        ) {
            throw new RuntimeException(
                'Concept Intelligence engine version mismatch.'
            );
        }

        if (
            (bool) $state->full_rebuild_required
            || (bool) $state->taxonomy_dirty
        ) {
            throw new RuntimeException(
                'Concept Intelligence requires reconciliation before '
                . 'Topic Intelligence can run.'
            );
        }

        if (
            DB::table('seo_catalog_dirty_terms')->exists()
        ) {
            throw new RuntimeException(
                'Dirty Concept Intelligence terms remain. Process them '
                . 'before rebuilding Topic Intelligence.'
            );
        }

        if (
            ! DB::table('seo_term_concepts')
                ->where('is_active', true)
                ->exists()
        ) {
            throw new RuntimeException(
                'Concept Intelligence contains no active concepts.'
            );
        }
    }

    private function inputFingerprint(): string
    {
        $conceptRows = DB::table('seo_term_concepts')
            ->where('is_active', true)
            ->whereIn(
                'quality_status',
                ['strong', 'qualified']
            )
            ->orderBy('id')
            ->get([
                'id',
                'concept_key',
                'quality_status',
                'support_video_count',
                'provider_count',
                'semantic_score',
                'source_fingerprint',
            ]);

        $conceptParts = [];

        foreach ($conceptRows as $row) {
            $conceptParts[] = implode(
                '|',
                [
                    (string) $row->id,
                    (string) $row->concept_key,
                    (string) $row->quality_status,
                    (string) $row->support_video_count,
                    (string) $row->provider_count,
                    (string) $row->semantic_score,
                    (string) ($row->source_fingerprint ?? ''),
                ]
            );
        }

        $categoryRows = DB::table('categories')
            ->orderBy('id')
            ->get([
                'id',
                'name',
                'slug',
                'is_active',
            ]);

        $categoryParts = [];

        foreach ($categoryRows as $row) {
            $categoryParts[] = implode(
                '|',
                [
                    (string) $row->id,
                    (string) $row->name,
                    (string) $row->slug,
                    $row->is_active ? '1' : '0',
                ]
            );
        }

        $membership = DB::selectOne(
            <<<'SQL'
SELECT
    COUNT(*)::bigint AS row_count,
    COALESCE(MAX(calculated_at)::text, '') AS max_calculated_at,
    COALESCE(SUM(video_id::numeric), 0)::text AS video_sum,
    COALESCE(SUM(category_id::numeric), 0)::text AS category_sum,
    COALESCE(
        SUM(video_id::numeric * category_id::numeric),
        0
    )::text AS pair_sum
FROM seo_video_category_memberships
SQL
        );

        return hash(
            'sha256',
            json_encode(
                [
                    'engine' => self::ENGINE_VERSION,
                    'quality_ruleset' => self::QUALITY_RULESET_VERSION,
                    'concepts' => hash(
                        'sha256',
                        implode("\n", $conceptParts)
                    ),
                    'categories' => hash(
                        'sha256',
                        implode("\n", $categoryParts)
                    ),
                    'membership' => [
                        'row_count' => (string) $membership->row_count,
                        'max_calculated_at' =>
                            (string) $membership->max_calculated_at,
                        'video_sum' => (string) $membership->video_sum,
                        'category_sum' => (string) $membership->category_sum,
                        'pair_sum' => (string) $membership->pair_sum,
                    ],
                ],
                JSON_UNESCAPED_SLASHES
            )
        );
    }

    private function ensureTopicSyncState(): void
    {
        $timestamp = now();

        DB::table('seo_semantic_sync_states')->insertOrIgnore(
            [
                'engine_key' => self::SYNC_ENGINE_KEY,
                'engine_version' => self::ENGINE_VERSION,
                'full_rebuild_required' => true,
                'taxonomy_dirty' => false,
                'taxonomy_dirty_at' => null,
                'last_incremental_started_at' => null,
                'last_incremental_completed_at' => null,
                'last_full_reconcile_started_at' => null,
                'last_full_reconcile_completed_at' => null,
                'last_video_id' => 0,
                'last_video_updated_at' => null,
                'last_source_term_id' => 0,
                'last_source_term_updated_at' => null,
                'last_error' => null,
                'metadata' => json_encode(
                    [
                        'topic_engine' => self::ENGINE_VERSION,
                        'quality_ruleset_version' =>
                            self::QUALITY_RULESET_VERSION,
                        'input_fingerprint' => null,
                    ],
                    JSON_UNESCAPED_SLASHES
                ),
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]
        );
    }

    private function createRun(
        string $runUuid,
        $startedAt,
        array $metadata
    ): int {
        return DB::table('seo_semantic_runs')
            ->insertGetId(
                [
                    'run_uuid' => $runUuid,
                    'engine_stage' => self::ENGINE_STAGE,
                    'run_mode' => 'full',
                    'status' => 'running',
                    'engine_version' => self::ENGINE_VERSION,
                    'claimed_count' => 0,
                    'processed_count' => 0,
                    'changed_count' => 0,
                    'skipped_count' => 0,
                    'failed_count' => 0,
                    'started_at' => $startedAt,
                    'heartbeat_at' => $startedAt,
                    'completed_at' => null,
                    'last_error' => null,
                    'metadata' => json_encode(
                        $metadata,
                        JSON_UNESCAPED_SLASHES
                    ),
                    'created_at' => $startedAt,
                    'updated_at' => $startedAt,
                ]
            );
    }

    private function completeRun(
        int $runId,
        array $result
    ): void {
        $completedAt = now();

        DB::table('seo_semantic_runs')
            ->where('id', $runId)
            ->update(
                [
                    'status' => 'completed',
                    'claimed_count' =>
                        (int) ($result['candidate_pair_count'] ?? 0),
                    'processed_count' =>
                        (int) ($result['topic_count'] ?? 0),
                    'changed_count' =>
                        (int) ($result['indexable_topic_count'] ?? 0),
                    'skipped_count' =>
                        (int) (
                            ($result['generic_hold_topic_count'] ?? 0)
                            + ($result['redundant_hold_topic_count'] ?? 0)
                        ),
                    'failed_count' => 0,
                    'heartbeat_at' => $completedAt,
                    'completed_at' => $completedAt,
                    'last_error' => null,
                    'metadata' => json_encode(
                        $result,
                        JSON_UNESCAPED_SLASHES
                    ),
                    'updated_at' => $completedAt,
                ]
            );
    }

    private function failRun(
        int $runId,
        Throwable $exception
    ): void {
        $completedAt = now();

        DB::table('seo_semantic_runs')
            ->where('id', $runId)
            ->update(
                [
                    'status' => 'failed',
                    'failed_count' => 1,
                    'heartbeat_at' => $completedAt,
                    'completed_at' => $completedAt,
                    'last_error' => Str::limit(
                        $exception->getMessage(),
                        4000,
                        ''
                    ),
                    'updated_at' => $completedAt,
                ]
            );
    }

    private function markSyncStarted($startedAt): void
    {
        DB::table('seo_semantic_sync_states')
            ->where(
                'engine_key',
                self::SYNC_ENGINE_KEY
            )
            ->update(
                [
                    'engine_version' => self::ENGINE_VERSION,
                    'last_full_reconcile_started_at' => $startedAt,
                    'last_error' => null,
                    'updated_at' => $startedAt,
                ]
            );
    }

    private function markSyncCompleted(
        $completedAt,
        array $result
    ): void {
        DB::table('seo_semantic_sync_states')
            ->where(
                'engine_key',
                self::SYNC_ENGINE_KEY
            )
            ->update(
                [
                    'engine_version' => self::ENGINE_VERSION,
                    'full_rebuild_required' => false,
                    'taxonomy_dirty' => false,
                    'taxonomy_dirty_at' => null,
                    'last_full_reconcile_completed_at' => $completedAt,
                    'last_error' => null,
                    'metadata' => json_encode(
                        [
                            'topic_engine' => self::ENGINE_VERSION,
                            'quality_ruleset_version' =>
                                self::QUALITY_RULESET_VERSION,
                            'input_fingerprint' =>
                                $result['input_fingerprint'],
                            'candidate_gate' =>
                                $this->candidateGateMetadata(),
                            'strong_gate' =>
                                $this->strongGateMetadata(),
                            'focused_gate' =>
                                $this->focusedGateMetadata(),
                            'max_indexable_per_category' =>
                                self::MAX_INDEXABLE_PER_CATEGORY,
                            'topic_count' =>
                                $result['topic_count'],
                            'indexable_topic_count' =>
                                $result['indexable_topic_count'],
                            'indexable_category_count' =>
                                $result['indexable_category_count'],
                            'component_count' =>
                                $result['component_count'],
                            'topic_video_count' =>
                                $result['topic_video_count'],
                        ],
                        JSON_UNESCAPED_SLASHES
                    ),
                    'updated_at' => $completedAt,
                ]
            );
    }

    private function markSyncFailed(Throwable $exception): void
    {
        DB::table('seo_semantic_sync_states')
            ->where(
                'engine_key',
                self::SYNC_ENGINE_KEY
            )
            ->update(
                [
                    'full_rebuild_required' => true,
                    'last_error' => Str::limit(
                        $exception->getMessage(),
                        4000,
                        ''
                    ),
                    'updated_at' => now(),
                ]
            );
    }

    private function runTransactional(
        callable $callback,
        bool $dryRun
    ): array {
        DB::beginTransaction();

        try {
            $result = $callback();

            if ($dryRun) {
                DB::rollBack();
            } else {
                DB::commit();
            }

            return $result;
        } catch (Throwable $exception) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            throw $exception;
        }
    }

    private function candidateGateMetadata(): array
    {
        return [
            'shared_video_count' => self::CANDIDATE_MIN_SHARED,
            'provider_count' => self::CANDIDATE_MIN_PROVIDERS,
            'jaccard_score' => self::CANDIDATE_MIN_JACCARD,
            'lift_score' => self::CANDIDATE_MIN_LIFT,
        ];
    }

    private function strongGateMetadata(): array
    {
        return [
            'shared_video_count' => self::STRONG_MIN_SHARED,
            'provider_count' => self::STRONG_MIN_PROVIDERS,
            'jaccard_score' => self::STRONG_MIN_JACCARD,
            'lift_score' => self::STRONG_MIN_LIFT,
            'semantic_score' => self::MIN_INDEXABLE_SEMANTIC_SCORE,
        ];
    }

    private function focusedGateMetadata(): array
    {
        return [
            'shared_video_count' => self::FOCUSED_MIN_SHARED,
            'provider_count' => self::FOCUSED_MIN_PROVIDERS,
            'concept_coverage' => self::FOCUSED_MIN_CONCEPT_COVERAGE,
            'jaccard_score' => self::FOCUSED_MIN_JACCARD,
            'lift_score' => self::FOCUSED_MIN_LIFT,
            'semantic_score' => self::MIN_INDEXABLE_SEMANTIC_SCORE,
        ];
    }
}
