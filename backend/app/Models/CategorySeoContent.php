<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CategorySeoContent extends Model
{
    protected $fillable = [
        'category_id',
        'intro',
        'body',
        'faq',
        'meta_description',
        'quality_status',
        'generator_version',
        'source_fingerprint',
        'content_hash',
        'generated_at',
        'published_at',
    ];

    protected $casts = [
        'faq' => 'array',
        'generated_at' => 'datetime',
        'published_at' => 'datetime',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
