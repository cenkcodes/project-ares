<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Video;

class VideoController extends Controller
{
    public function index()
    {
        $videos = Video::with('category')
            ->where('is_active', true)
            ->latest()
            ->paginate(24);

        $categories = Category::where('is_active', true)
            ->orderBy('name')
            ->get();

        return view('videos.index', [
            'videos' => $videos,
            'categories' => $categories,
            'activeCategory' => null,
        ]);
    }

    public function category(string $slug)
    {
        $category = Category::where('slug', $slug)
            ->where('is_active', true)
            ->firstOrFail();

        $videos = Video::with('category')
            ->where('is_active', true)
            ->where('category_id', $category->id)
            ->latest()
            ->paginate(24)
            ->withQueryString();

        $categories = Category::where('is_active', true)
            ->orderBy('name')
            ->get();

        return view('videos.index', [
            'videos' => $videos,
            'categories' => $categories,
            'activeCategory' => $category,
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
}
