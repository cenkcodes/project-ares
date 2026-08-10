<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Video;

class HomeController extends Controller
{
    public function index()
    {
        $featuredVideos = Video::with('category')
            ->where('is_active', true)
            ->where('is_featured', true)
            ->latest()
            ->take(8)
            ->get();

        if ($featuredVideos->isEmpty()) {
            $featuredVideos = Video::with('category')
                ->where('is_active', true)
                ->orderByDesc('views')
                ->latest()
                ->take(8)
                ->get();
        }

        $latestVideos = Video::with('category')
            ->where('is_active', true)
            ->latest()
            ->take(12)
            ->get();

        $categories = Category::where('is_active', true)
            ->withCount([
                'videos' => function ($query) {
                    $query->where('is_active', true);
                },
            ])
            ->orderBy('name')
            ->get();

        return view('home', [
            'featuredVideos' => $featuredVideos,
            'latestVideos' => $latestVideos,
            'categories' => $categories,
        ]);
    }
}
