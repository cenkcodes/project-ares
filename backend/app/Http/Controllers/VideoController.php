<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\CategoryRelation;
use App\Models\Video;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class VideoController extends Controller
{
    public function index(Request $request)
    {
        $videos = $this->buildVideoQuery($request)
            ->paginate(24)
            ->withQueryString();

        $categories = Category::where('is_active', true)
            ->orderBy('name')
            ->get();

        return view('videos.index', [
            'videos' => $videos,
            'categories' => $categories,
            'activeCategory' => null,
            'relatedCategories' => collect(),
            'search' => $request->string('q')->trim()->toString(),
            'sort' => $request->string('sort')->toString() ?: 'latest',
        ]);
    }

    public function category(Request $request, string $slug)
    {
        $category = Category::where('slug', $slug)
            ->where('is_active', true)
            ->firstOrFail();

        $videos = $this->buildVideoQuery(
            $request,
            $category
        )
            ->paginate(24)
            ->withQueryString();

        $categories = Category::where('is_active', true)
            ->orderBy('name')
            ->get();

        $relatedCategories =
            $this->getRelatedCategories(
                $category
            );

        return view('videos.index', [
            'videos' => $videos,
            'categories' => $categories,
            'activeCategory' => $category,
            'relatedCategories' => $relatedCategories,
            'search' => $request->string('q')->trim()->toString(),
            'sort' => $request->string('sort')->toString() ?: 'latest',
        ]);
    }

    public function show(string $slug)
    {
        $video = Video::with('category')
            ->where('slug', $slug)
            ->where('is_active', true)
            ->firstOrFail();

        $video->increment('views');

        $video = $video->fresh(['category', 'seoContent']);

        $taxonomyCategoryIds =
            $this->getCanonicalCategoryIdsForVideo(
                $video
            );

        $topicCategories =
            $this->getTopicCategories(
                $taxonomyCategoryIds
            );

        $relatedVideos =
            $this->getRelatedVideos(
                $video,
                $taxonomyCategoryIds
            );

        $durationLabel =
            $this->formatDuration(
                (int) $video->duration
            );

        $sourceLabel =
            $this->formatSourceLabel(
                $video->video_source
            );

        $seoContent = $video->seoContent;
        $publishedSeoDescription = null;
        $publishedSeoMetaDescription = null;

        if ($seoContent !== null && $seoContent->quality_status === 'published') {
            $publishedSeoDescription = trim((string) $seoContent->description) ?: null;
            $publishedSeoMetaDescription = trim((string) $seoContent->meta_description) ?: null;
        }

        $seoIndexEligible = $seoContent !== null
            && $seoContent->quality_status === 'published'
            && $seoContent->published_at !== null
            && trim((string) $seoContent->description) !== ''
            && trim((string) $seoContent->meta_description) !== '';
        $robotsContent = app()->environment('production')
            ? ($seoIndexEligible ? 'index,follow' : 'noindex,follow')
            : 'noindex,nofollow';

        $categoryName = $video->category?->is_active
            ? trim((string) $video->category->name)
            : '';
        $descriptionFallback = $categoryName !== ''
            ? $categoryName . ' video.'
            : 'Video.';
        $pageDescription = $publishedSeoMetaDescription ?? $descriptionFallback;
        $videoStructuredDescription = $publishedSeoDescription ?? $descriptionFallback;

        return view('videos.show', [
            'video' => $video,
            'relatedVideos' => $relatedVideos,
            'topicCategories' => $topicCategories,
            'publishedSeoDescription' => $publishedSeoDescription,
            'pageDescription' => $pageDescription,
            'robotsContent' => $robotsContent,
            'videoStructuredDescription' => $videoStructuredDescription,
            'durationLabel' => $durationLabel,
            'sourceLabel' => $sourceLabel,
        ]);
    }

    private function buildVideoQuery(
        Request $request,
        ?Category $category = null
    ): Builder {
        $query = Video::with('category')
            ->where('is_active', true);

        if ($category) {
            $query->where(
                'category_id',
                $category->id
            );
        }

        $search = $request
            ->string('q')
            ->trim()
            ->toString();

        if ($search !== '') {
            $query->where(function (Builder $query) use ($search) {
                $query
                    ->where(
                        'title',
                        'ilike',
                        '%' . $search . '%'
                    )
                    ->orWhere(
                        'description',
                        'ilike',
                        '%' . $search . '%'
                    );
            });
        }

        $sort = $request
            ->string('sort')
            ->toString();

        match ($sort) {
            'views' => $query
                ->orderByDesc('views')
                ->orderByDesc('id'),

            'oldest' => $query
                ->oldest(),

            default => $query
                ->latest(),
        };

        return $query;
    }

    /**
     * Resolve related categories from the current canonical relation graph.
     *
     * The database graph is the primary source. The legacy config is used
     * only when the current relation-engine version has no graph at all,
     * such as a deployment window before the first successful rebuild.
     *
     * An empty relation set for one category is therefore treated as an
     * intentional quality decision and does not trigger config fallback.
     */
    private function getRelatedCategories(
        Category $category
    ): Collection {
        $relations =
            CategoryRelation::query()
                ->currentVersion()
                ->published()
                ->where(
                    'category_id',
                    $category->id
                )
                ->with(
                    [
                        'relatedCategory' =>
                            function ($query): void {
                                $query->where(
                                    'is_active',
                                    true
                                );
                            },
                    ]
                )
                ->orderBy(
                    'relation_rank'
                )
                ->orderByDesc(
                    'semantic_score'
                )
                ->get();

        if ($relations->isNotEmpty()) {
            return $relations
                ->pluck(
                    'relatedCategory'
                )
                ->filter()
                ->unique(
                    'id'
                )
                ->values();
        }

        $currentGraphExists =
            CategoryRelation::query()
                ->currentVersion()
                ->exists();

        if ($currentGraphExists) {
            return collect();
        }

        $fallbackSlugs =
            collect(
                config(
                    'category-seo.related_categories.' .
                    $category->slug,
                    []
                )
            )
                ->filter(
                    fn ($slug) =>
                        is_string($slug)
                        && trim($slug) !== ''
                )
                ->map(
                    fn ($slug) =>
                        trim($slug)
                )
                ->unique()
                ->values();

        if ($fallbackSlugs->isEmpty()) {
            return collect();
        }

        $fallbackCategories =
            Category::query()
                ->where(
                    'is_active',
                    true
                )
                ->whereIn(
                    'slug',
                    $fallbackSlugs->all()
                )
                ->get()
                ->keyBy(
                    'slug'
                );

        return $fallbackSlugs
            ->map(
                fn ($slug) =>
                    $fallbackCategories->get(
                        $slug
                    )
            )
            ->filter()
            ->values();
    }

    private function getCanonicalCategoryIdsForVideo(
        Video $video
    ): array {
        $categoryIds = [];

        if (
            $video->category !== null &&
            $video->category->is_active
        ) {
            $categoryIds[] =
                (int) $video->category->id;
        }

        $source =
            trim(
                (string) $video->video_source
            );

        if ($source === '') {
            return $categoryIds;
        }

        $matchedCategoryIds =
            DB::table(
                'video_source_terms as vst'
            )
                ->join(
                    'category_aliases as ca',
                    function ($join) {
                        $join
                            ->on(
                                'ca.normalized_alias',
                                '=',
                                'vst.normalized_term'
                            )
                            ->on(
                                'ca.alias_type',
                                '=',
                                'vst.term_type'
                            );
                    }
                )
                ->join(
                    'categories as c',
                    'c.id',
                    '=',
                    'ca.category_id'
                )
                ->where(
                    'vst.video_id',
                    $video->id
                )
                ->where(
                    'vst.source',
                    $source
                )
                ->where(
                    'ca.is_active',
                    1
                )
                ->where(
                    'c.is_active',
                    true
                )
                ->where(
                    function ($query) use (
                        $source
                    ) {
                        $query
                            ->where(
                                'ca.source',
                                $source
                            )
                            ->orWhere(
                                'ca.source',
                                '*'
                            );
                    }
                )
                ->groupBy(
                    'ca.category_id'
                )
                ->selectRaw(
                    'ca.category_id, ' .
                    'COUNT(DISTINCT ' .
                    'vst.normalized_term) ' .
                    'as evidence_count, ' .
                    'MAX(ca.priority) ' .
                    'as max_priority, ' .
                    'MIN(CASE ' .
                    'WHEN ca.source = ? ' .
                    'THEN 0 ELSE 1 END) ' .
                    'as source_rank',
                    [$source]
                )
                ->orderByDesc(
                    'evidence_count'
                )
                ->orderBy(
                    'source_rank'
                )
                ->orderByDesc(
                    'max_priority'
                )
                ->pluck(
                    'ca.category_id'
                )
                ->map(
                    fn ($categoryId) =>
                        (int) $categoryId
                )
                ->all();

        foreach (
            $matchedCategoryIds
            as $categoryId
        ) {
            if (
                ! in_array(
                    $categoryId,
                    $categoryIds,
                    true
                )
            ) {
                $categoryIds[] =
                    $categoryId;
            }
        }

        return $categoryIds;
    }

    private function getTopicCategories(
        array $categoryIds
    ): Collection {
        if ($categoryIds === []) {
            return collect();
        }

        $categories =
            Category::query()
                ->where(
                    'is_active',
                    true
                )
                ->whereIn(
                    'id',
                    $categoryIds
                )
                ->get()
                ->keyBy('id');

        return collect($categoryIds)
            ->map(
                fn ($categoryId) =>
                    $categories->get(
                        $categoryId
                    )
            )
            ->filter()
            ->take(4)
            ->values();
    }

    private function getRelatedVideos(
        Video $video,
        array $taxonomyCategoryIds
    ): Collection {
        $scores = [];

        if ($taxonomyCategoryIds !== []) {
            $taxonomyCandidates =
                DB::table(
                    'video_source_terms as vst'
                )
                    ->join(
                        'videos as v',
                        'v.id',
                        '=',
                        'vst.video_id'
                    )
                    ->join(
                        'category_aliases as ca',
                        function ($join) {
                            $join
                                ->on(
                                    'ca.normalized_alias',
                                    '=',
                                    'vst.normalized_term'
                                )
                                ->on(
                                    'ca.alias_type',
                                    '=',
                                    'vst.term_type'
                                );
                        }
                    )
                    ->join(
                        'categories as c',
                        'c.id',
                        '=',
                        'ca.category_id'
                    )
                    ->where(
                        'v.is_active',
                        true
                    )
                    ->where(
                        'v.id',
                        '!=',
                        $video->id
                    )
                    ->whereColumn(
                        'vst.source',
                        'v.video_source'
                    )
                    ->where(
                        'ca.is_active',
                        1
                    )
                    ->where(
                        'c.is_active',
                        true
                    )
                    ->whereIn(
                        'ca.category_id',
                        $taxonomyCategoryIds
                    )
                    ->where(
                        function ($query) {
                            $query
                                ->whereColumn(
                                    'ca.source',
                                    'vst.source'
                                )
                                ->orWhere(
                                    'ca.source',
                                    '*'
                                );
                        }
                    )
                    ->groupBy(
                        'v.id',
                        'v.views',
                        'v.created_at'
                    )
                    ->selectRaw(
                        'v.id, ' .
                        'COUNT(DISTINCT ' .
                        'ca.category_id) ' .
                        'as shared_category_count'
                    )
                    ->orderByDesc(
                        'shared_category_count'
                    )
                    ->orderByDesc(
                        'v.views'
                    )
                    ->orderByDesc(
                        'v.created_at'
                    )
                    ->limit(80)
                    ->get();

            foreach (
                $taxonomyCandidates
                as $candidate
            ) {
                $candidateId =
                    (int) $candidate->id;

                $scores[$candidateId] =
                    (
                        (int)
                        $candidate
                            ->shared_category_count
                    ) * 100;
            }
        }

        if ($video->category_id !== null) {
            $samePrimaryIds =
                Video::query()
                    ->where(
                        'is_active',
                        true
                    )
                    ->where(
                        'id',
                        '!=',
                        $video->id
                    )
                    ->where(
                        'category_id',
                        $video->category_id
                    )
                    ->orderByDesc(
                        'views'
                    )
                    ->latest()
                    ->limit(60)
                    ->pluck('id');

            foreach (
                $samePrimaryIds
                as $candidateId
            ) {
                $candidateId =
                    (int) $candidateId;

                $scores[$candidateId] =
                    (
                        $scores[$candidateId]
                        ?? 0
                    ) + 40;
            }
        }

        if ($scores === []) {
            return Video::with('category')
                ->where(
                    'is_active',
                    true
                )
                ->where(
                    'id',
                    '!=',
                    $video->id
                )
                ->latest()
                ->take(8)
                ->get();
        }

        $candidateVideos =
            Video::with('category')
                ->whereIn(
                    'id',
                    array_keys($scores)
                )
                ->get()
                ->sort(
                    function (
                        Video $left,
                        Video $right
                    ) use ($scores) {
                        $scoreComparison =
                            (
                                $scores[
                                    $right->id
                                ] ?? 0
                            )
                            <=>
                            (
                                $scores[
                                    $left->id
                                ] ?? 0
                            );

                        if (
                            $scoreComparison !== 0
                        ) {
                            return $scoreComparison;
                        }

                        $viewsComparison =
                            (int) $right->views
                            <=>
                            (int) $left->views;

                        if (
                            $viewsComparison !== 0
                        ) {
                            return $viewsComparison;
                        }

                        $rightTimestamp =
                            $right->created_at
                                ?->getTimestamp()
                            ?? 0;

                        $leftTimestamp =
                            $left->created_at
                                ?->getTimestamp()
                            ?? 0;

                        return $rightTimestamp
                            <=>
                            $leftTimestamp;
                    }
                )
                ->take(8)
                ->values();

        if (
            $candidateVideos->count()
            < 8
        ) {
            $missingCount =
                8 -
                $candidateVideos->count();

            $excludedIds =
                $candidateVideos
                    ->pluck('id')
                    ->push(
                        $video->id
                    )
                    ->all();

            $fallbackVideos =
                Video::with('category')
                    ->where(
                        'is_active',
                        true
                    )
                    ->whereNotIn(
                        'id',
                        $excludedIds
                    )
                    ->latest()
                    ->take(
                        $missingCount
                    )
                    ->get();

            $candidateVideos =
                $candidateVideos
                    ->concat(
                        $fallbackVideos
                    )
                    ->values();
        }

        return $candidateVideos;
    }

    private function formatDuration(
        int $durationSeconds
    ): ?string {
        if ($durationSeconds <= 0) {
            return null;
        }

        if ($durationSeconds >= 3600) {
            return gmdate(
                'H:i:s',
                $durationSeconds
            );
        }

        return gmdate(
            'i:s',
            $durationSeconds
        );
    }

    private function formatSourceLabel(
        ?string $source
    ): ?string {
        $source =
            Str::lower(
                trim(
                    (string) $source
                )
            );

        if ($source === '') {
            return null;
        }

        return match ($source) {
            'xvideos' => 'XVideos',
            'eporner' => 'Eporner',
            'xnxx' => 'XNXX',
            'redtube' => 'RedTube',
            default => Str::headline(
                $source
            ),
        };
    }
}
