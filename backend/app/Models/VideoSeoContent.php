<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VideoSeoContent extends Model
{
    protected $fillable = [
        'video_id',
        'description',
        'meta_description',
        'quality_status',
        'generator_version',
        'source_fingerprint',
        'content_hash',
        'generated_at',
        'published_at',
    ];

    protected $casts = [
        'generated_at' => 'datetime',
        'published_at' => 'datetime',
    ];

    public function video(): BelongsTo
    {
        return $this->belongsTo(Video::class);
    }
}
