<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Video extends Model
{
    public const MAX_TITLE_LENGTH = 1000;
    public const MAX_SLUG_LENGTH = 255;
    public const MAX_DESCRIPTION_LENGTH = 100000;
    public const MAX_URL_LENGTH = 8192;
    public const MAX_VIDEO_SOURCE_LENGTH = 255;

    // 365 days in seconds.
    public const MAX_DURATION_SECONDS = 31536000;

    protected $fillable = [
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
    ];

    protected $casts = [
        'is_hd' => 'boolean',
        'is_4k' => 'boolean',
        'is_featured' => 'boolean',
        'is_premium' => 'boolean',
        'is_active' => 'boolean',
        'views' => 'integer',
        'duration' => 'integer',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
