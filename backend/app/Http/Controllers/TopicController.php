<?php

namespace App\Http\Controllers;

use App\Models\SeoTopic;
use App\Models\Video;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TopicController extends Controller
{
    private const PAGE_SIZE = 24;

    private const RELATED_TOPIC_LIMIT = 6;

    public function show(
        Request $request,
        string $slug
    ) {
        $topic =
            SeoTopic::query()
                ->publicLandingPageEligible()
                ->with(
                    'primaryCategory'
                )
                ->where(
                    'slug',
                    $slug
                )
                ->firstOrFail();

        $videos =
            Video::query()
                ->with(
                    'category'
                )
                ->join(
                    'seo_topic_videos as topic_video',
                    'topic_video.video_id',
                    '=',
                    'videos.id'
                )
                ->where(
                    'topic_video.topic_id',
                    $topic->id
                )
                ->where(
                    'videos.is_active',
                    true
                )
                ->orderByDesc(
                    'topic_video.relevance_score'
                )
                ->orderByDesc(
                    'videos.created_at'
                )
                ->orderByDesc(
                    'videos.id'
                )
                ->select(
                    'videos.*'
                )
                ->paginate(
                    self::PAGE_SIZE
                );

        $relatedTopics =
            SeoTopic::query()
                ->publicLandingPageEligible()
                ->where(
                    'primary_category_id',
                    $topic->primary_category_id
                )
                ->where(
                    'id',
                    '<>',
                    $topic->id
                )
                ->orderByDesc(
                    'semantic_score'
                )
                ->orderByDesc(
                    'support_video_count'
                )
                ->orderBy(
                    'title'
                )
                ->limit(
                    self::RELATED_TOPIC_LIMIT
                )
                ->get();

        $topicContent =
            DB::table(
                'seo_topic_contents'
            )
                ->where(
                    'topic_id',
                    $topic->id
                )
                ->whereNotNull(
                    'published_at'
                )
                ->orderByDesc(
                    'published_at'
                )
                ->orderByDesc(
                    'id'
                )
                ->first();

        return view(
            'topics.show',
            [
                'topic' =>
                    $topic,

                'videos' =>
                    $videos,

                'relatedTopics' =>
                    $relatedTopics,

                'topicContent' =>
                    $topicContent,

                /*
                 * Topic Landing Page V1 is intentionally
                 * not opened to search-engine indexing yet.
                 *
                 * Index exposure is a later controlled stage
                 * after Topic editorial content, frontend QA,
                 * internal-link QA and sitemap readiness pass.
                 */
                'topicIndexExposureEnabled' =>
                    false,

                'requestHasQueryParameters' =>
                    $request->query() !== [],
            ]
        );
    }
}
