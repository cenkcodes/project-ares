<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CategoryRelation extends Model
{
    public const CURRENT_VERSION =
        'category-relation-v2.1';

    public const QUALITY_STRONG =
        'strong';

    public const QUALITY_CONTEXTUAL =
        'contextual';

    public const QUALITY_FOCUSED =
        'focused';

    public const QUALITY_COVERAGE =
        'coverage';

    public const QUALITY_CANDIDATE =
        'candidate';

    protected $fillable = [
        'category_id',
        'related_category_id',
        'shared_video_count',
        'evidence_count',
        'relation_score',
        'relation_version',
        'calculated_at',

        'source_video_count',
        'related_video_count',
        'shared_provider_count',
        'source_confidence',
        'related_confidence',
        'jaccard_score',
        'lift_score',
        'semantic_score',
        'relation_rank',
        'quality_status',
        'is_published',
    ];

    protected $casts = [
        'category_id' =>
            'integer',

        'related_category_id' =>
            'integer',

        'shared_video_count' =>
            'integer',

        'evidence_count' =>
            'integer',

        'relation_score' =>
            'integer',

        'source_video_count' =>
            'integer',

        'related_video_count' =>
            'integer',

        'shared_provider_count' =>
            'integer',

        'source_confidence' =>
            'decimal:8',

        'related_confidence' =>
            'decimal:8',

        'jaccard_score' =>
            'decimal:8',

        'lift_score' =>
            'decimal:8',

        'semantic_score' =>
            'decimal:6',

        'relation_rank' =>
            'integer',

        'is_published' =>
            'boolean',

        'calculated_at' =>
            'datetime',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(
            Category::class
        );
    }

    public function relatedCategory(): BelongsTo
    {
        return $this->belongsTo(
            Category::class,
            'related_category_id'
        );
    }

    /**
     * Limit the query to the currently supported relation-engine version.
     */
    public function scopeCurrentVersion(
        Builder $query
    ): Builder {
        return $query->where(
            'relation_version',
            self::CURRENT_VERSION
        );
    }

    /**
     * Limit the query to relations approved for public/internal-link use.
     */
    public function scopePublished(
        Builder $query
    ): Builder {
        return $query
            ->where(
                'is_published',
                true
            )
            ->whereIn(
                'quality_status',
                [
                    self::QUALITY_STRONG,
                    self::QUALITY_CONTEXTUAL,
                    self::QUALITY_FOCUSED,
                    self::QUALITY_COVERAGE,
                ]
            );
    }
}
