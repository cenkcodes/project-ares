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

        return view('videos.show', [
            'video' => $video->fresh('category'),
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
}
