<?php

namespace App\Http\Controllers;

use App\Models\Video;

class VideoController extends Controller
{
    public function index()
    {
        $videos = Video::where('is_active', true)
            ->latest()
            ->paginate(24);

        return view('videos.index', [
            'videos' => $videos,
        ]);
    }

    public function show(string $slug)
    {
        $video = Video::where('slug', $slug)
            ->where('is_active', true)
            ->firstOrFail();

        $video->increment('views');

        return view('videos.show', [
            'video' => $video->fresh(),
        ]);
    }
}
