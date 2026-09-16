<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class VideoImportJob extends Model
{
    public const STATUS_QUEUED = 'queued';
    public const STATUS_LAUNCHING = 'launching';
    public const STATUS_RUNNING = 'running';
    public const STATUS_PASS = 'pass';
    public const STATUS_FAIL = 'fail';

    protected $fillable = [
        'source',
        'category_slug',
        'mode',
        'minimum_count',
        'target_count',
        'max_pages',
        'status',
        'stage',
        'progress_current',
        'progress_total',
        'process_id',
        'exit_code',
        'log_path',
        'message',
        'result_summary',
        'created_by',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'minimum_count' => 'integer',
        'target_count' => 'integer',
        'max_pages' => 'integer',
        'progress_current' => 'integer',
        'progress_total' => 'integer',
        'process_id' => 'integer',
        'exit_code' => 'integer',
        'result_summary' => 'array',
        'created_by' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', [
            self::STATUS_QUEUED,
            self::STATUS_LAUNCHING,
            self::STATUS_RUNNING,
        ]);
    }

    public function isActive(): bool
    {
        return in_array($this->status, [
            self::STATUS_QUEUED,
            self::STATUS_LAUNCHING,
            self::STATUS_RUNNING,
        ], true);
    }
}
