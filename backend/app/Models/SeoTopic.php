<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeoTopic extends Model
{
    public const GENERATOR_VERSION =
        'topic-intelligence-v1';

    public const QUALITY_STRONG =
        'strong';

    public const QUALITY_FOCUSED =
        'focused';

    protected $table =
        'seo_topics';

    protected $guarded = [];

    protected $casts = [
        'is_indexable' =>
            'boolean',

        'support_video_count' =>
            'integer',

        'provider_count' =>
            'integer',

        'component_count' =>
            'integer',

        'confidence_score' =>
            'float',

        'jaccard_score' =>
            'float',

        'lift_score' =>
            'float',

        'semantic_score' =>
            'float',

        'first_seen_at' =>
            'datetime',

        'last_seen_at' =>
            'datetime',

        'calculated_at' =>
            'datetime',

        'published_at' =>
            'datetime',
    ];

    public function primaryCategory(): BelongsTo
    {
        return $this->belongsTo(
            Category::class,
            'primary_category_id'
        );
    }

    public function scopePublicLandingPageEligible(
        $query
    ) {
        return $query
            ->where(
                'generator_version',
                self::GENERATOR_VERSION
            )
            ->where(
                'is_indexable',
                true
            )
            ->whereIn(
                'quality_status',
                [
                    self::QUALITY_STRONG,
                    self::QUALITY_FOCUSED,
                ]
            )
            ->whereNotNull(
                'primary_category_id'
            )
            ->whereHas(
                'primaryCategory',
                function ($categoryQuery): void {
                    $categoryQuery->where(
                        'is_active',
                        true
                    );
                }
            );
    }
}
