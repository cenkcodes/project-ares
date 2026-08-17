<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class VideoMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_videos_table_has_required_schema(): void
    {
        $this->assertTrue(
            Schema::hasColumns(
                'videos',
                [
                    'id',
                    'title',
                    'slug',
                    'description',
                    'embed_url',
                    'video_source',
                    'thumbnail',
                    'duration',
                    'category_id',
                    'views',
                    'is_hd',
                    'is_4k',
                    'is_featured',
                    'is_premium',
                    'is_active',
                    'created_at',
                    'updated_at',
                ],
            ),
        );
    }

    public function test_video_metadata_columns_have_expected_types(): void
    {
        $this->assertSame(
            'text',
            Schema::getColumnType(
                'videos',
                'thumbnail',
            ),
        );

        $this->assertSame(
            'integer',
            Schema::getColumnType(
                'videos',
                'duration',
            ),
        );
    }

    public function test_video_slug_is_unique(): void
    {
        DB::table('videos')->insert([
            'title' => 'First Video',
            'slug' => 'unique-video',
            'embed_url' =>
                'https://example.com/embed/first',
        ]);

        $this->expectException(
            QueryException::class
        );

        DB::table('videos')->insert([
            'title' => 'Second Video',
            'slug' => 'unique-video',
            'embed_url' =>
                'https://example.com/embed/second',
        ]);
    }

    public function test_video_defaults_match_application_schema(): void
    {
        $id = DB::table('videos')
            ->insertGetId([
                'title' => 'Default Video',
                'slug' => 'default-video',
                'embed_url' =>
                    'https://example.com/embed/default-video',
            ]);

        $video = DB::table('videos')
            ->where('id', $id)
            ->first();

        $this->assertNull(
            $video->description
        );

        $this->assertNull(
            $video->video_source
        );

        $this->assertNull(
            $video->thumbnail
        );

        $this->assertNull(
            $video->duration
        );

        $this->assertNull(
            $video->category_id
        );

        $this->assertSame(
            0,
            (int) $video->views
        );

        $this->assertFalse(
            (bool) $video->is_hd
        );

        $this->assertFalse(
            (bool) $video->is_4k
        );

        $this->assertFalse(
            (bool) $video->is_featured
        );

        $this->assertFalse(
            (bool) $video->is_premium
        );

        $this->assertTrue(
            (bool) $video->is_active
        );
    }

    public function test_duration_is_stored_as_integer_seconds(): void
    {
        $id = DB::table('videos')
            ->insertGetId([
                'title' => 'Duration Video',
                'slug' => 'duration-video',
                'embed_url' =>
                    'https://example.com/embed/duration-video',
                'duration' => 1105,
            ]);

        $duration = DB::table('videos')
            ->where('id', $id)
            ->value('duration');

        $this->assertSame(
            1105,
            (int) $duration,
        );
    }

    public function test_deleting_category_sets_video_category_to_null(): void
    {
        $categoryId = DB::table('categories')
            ->insertGetId([
                'name' => 'Temporary Category',
                'slug' => 'temporary-category',
            ]);

        $videoId = DB::table('videos')
            ->insertGetId([
                'title' => 'Temporary Video',
                'slug' => 'temporary-video',
                'embed_url' =>
                    'https://example.com/embed/temporary-video',
                'category_id' => $categoryId,
            ]);

        DB::table('categories')
            ->where('id', $categoryId)
            ->delete();

        $video = DB::table('videos')
            ->where('id', $videoId)
            ->first();

        $this->assertNull(
            $video->category_id
        );
    }
}
