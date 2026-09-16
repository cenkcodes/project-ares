<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Category extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'description',
        'meta_description',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function videos(): HasMany
    {
        return $this->hasMany(Video::class);
    }

    public function aliases(): HasMany
    {
        return $this->hasMany(CategoryAlias::class);
    }

    public function seoContent(): HasOne
    {
        return $this->hasOne(
            CategorySeoContent::class
        );
    }

    public function relatedRelations(): HasMany
    {
        return $this->hasMany(
            CategoryRelation::class,
            'category_id'
        );
    }

    public function incomingRelations(): HasMany
    {
        return $this->hasMany(
            CategoryRelation::class,
            'related_category_id'
        );
    }
}
