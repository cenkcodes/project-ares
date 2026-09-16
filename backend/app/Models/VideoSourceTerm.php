<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VideoSourceTerm extends Model
{
    protected $fillable = [
        'video_id',
        'source',
        'term_type',
        'term',
        'normalized_term',
    ];

    public function video(): BelongsTo
    {
        return $this->belongsTo(Video::class);
    }
}
