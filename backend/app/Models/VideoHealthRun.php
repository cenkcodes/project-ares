<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class VideoHealthRun extends Model
{
    public const PROVIDER_ALL = 'all';
    public const PROVIDER_EPORNER = 'eporner';
    public const PROVIDER_XVIDEOS = 'xvideos';
    public const PROVIDER_XNXX = 'xnxx';
    public const PROVIDER_REDTUBE = 'redtube';

    public const MODE_AUDIT_ONLY = 'audit-only';
    public const MODE_AUDIT_DEACTIVATE = 'audit-deactivate';
    public const TRIGGER_MANUAL = 'manual';
    public const TRIGGER_SCHEDULED = 'scheduled';

    public const STATUS_DEFERRED = 'deferred';
    public const STATUS_QUEUED = 'queued';
    public const STATUS_LAUNCHING = 'launching';
    public const STATUS_RUNNING = 'running';
    public const STATUS_DEACTIVATING = 'deactivating';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_ABORTED = 'aborted';

    private const ACTIVE_STATUSES = [self::STATUS_QUEUED, self::STATUS_LAUNCHING, self::STATUS_RUNNING, self::STATUS_DEACTIVATING];
    private const TERMINAL_STATUSES = [self::STATUS_COMPLETED, self::STATUS_FAILED, self::STATUS_ABORTED];

    protected $fillable = [
        'provider',
        'mode',
        'trigger',
        'schedule_key',
        'status',
        'stage',
        'classifier_version',
        'config_sha256',
        'selected_count',
        'scanned_count',
        'pass_count',
        'broken_count',
        'review_count',
        'unverifiable_count',
        'deactivated_count',
        'process_id',
        'exit_code',
        'artifact_directory',
        'log_path',
        'manifest_sha256',
        'message',
        'result_summary',
        'created_by',
        'confirmed_at',
        'started_at',
        'heartbeat_at',
        'finished_at',
    ];

    protected $casts = [
        'selected_count' => 'integer',
        'scanned_count' => 'integer',
        'pass_count' => 'integer',
        'broken_count' => 'integer',
        'review_count' => 'integer',
        'unverifiable_count' => 'integer',
        'deactivated_count' => 'integer',
        'process_id' => 'integer',
        'exit_code' => 'integer',
        'created_by' => 'integer',
        'result_summary' => 'array',
        'confirmed_at' => 'datetime',
        'started_at' => 'datetime',
        'heartbeat_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', self::ACTIVE_STATUSES);
    }

    public function scopeTerminal(Builder $query): Builder
    {
        return $query->whereIn('status', self::TERMINAL_STATUSES);
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE_STATUSES, true);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL_STATUSES, true);
    }
}
