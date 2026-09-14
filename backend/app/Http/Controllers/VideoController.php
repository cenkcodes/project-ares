<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Video;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

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

        return view('videos.index', [
            'videos' => $videos,
            'categories' => $categories,
            'activeCategory' => $category,
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

        $relatedVideos = $this->getRelatedVideos($video);

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
            'publishedSeoDescription' => $publishedSeoDescription,
            'pageDescription' => $pageDescription,
            'videoStructuredDescription' => $videoStructuredDescription,
            'robotsContent' => $robotsContent,
            'relatedVideos' => $relatedVideos,
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

    private function getRelatedVideos(Video $video)
    {
        $query = Video::with('category')
            ->where('is_active', true)
            ->where('id', '!=', $video->id);

        if ($video->category_id !== null) {
            $query->where(
                'category_id',
                $video->category_id
            );
        }

        $relatedVideos = $query
            ->orderByDesc('views')
            ->latest()
            ->take(8)
            ->get();

        if ($relatedVideos->isEmpty()) {
            $relatedVideos = Video::with('category')
                ->where('is_active', true)
                ->where('id', '!=', $video->id)
                ->latest()
                ->take(8)
                ->get();
        }

        return $relatedVideos;
    }
}
