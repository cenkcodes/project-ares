<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class AdEvent extends Model
{
    public const FORMAT_NATIVE = 'native';
    public const FORMAT_BANNER = 'banner';
    public const FORMAT_PREROLL = 'preroll';
    public const FORMAT_MIDROLL = 'midroll';
    public const FORMAT_POPUNDER = 'popunder';
    public const FORMAT_INTERSTITIAL = 'interstitial';

    public const EVENT_DECISION = 'decision';
    public const EVENT_IMPRESSION = 'impression';
    public const EVENT_CLICK = 'click';
    public const EVENT_SKIP = 'skip';
    public const EVENT_CLOSE = 'close';
    public const EVENT_ERROR = 'error';

    public const OUTCOME_SHOW = 'show';
    public const OUTCOME_SKIP = 'skip';

    public const DEVICE_DESKTOP = 'desktop';
    public const DEVICE_MOBILE = 'mobile';
    public const DEVICE_TABLET = 'tablet';
    public const DEVICE_UNKNOWN = 'unknown';

    protected $fillable = [
        'event_uuid',
        'opportunity_uuid',
        'video_id',
        'provider_slug',
        'format',
        'event_type',
        'decision_outcome',
        'decision_reason',
        'placement_key',
        'ad_network',
        'campaign_key',
        'experiment_key',
        'experiment_variant',
        'session_key',
        'device_type',
        'interruption_cost',
        'revenue_micros',
        'currency',
        'metadata',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'video_id' => 'integer',
            'interruption_cost' => 'integer',
            'revenue_micros' => 'integer',
            'metadata' => 'array',
            'occurred_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (AdEvent $event): void {
            if (blank($event->event_uuid)) {
                $event->event_uuid = (string) Str::uuid();
            }

            if ($event->occurred_at === null) {
                $event->occurred_at = now();
            }

            if (
                filled($event->currency)
                && is_string($event->currency)
            ) {
                $event->currency = strtoupper(
                    $event->currency
                );
            }
        });
    }

    public static function formats(): array
    {
        return [
            self::FORMAT_NATIVE,
            self::FORMAT_BANNER,
            self::FORMAT_PREROLL,
            self::FORMAT_MIDROLL,
            self::FORMAT_POPUNDER,
            self::FORMAT_INTERSTITIAL,
        ];
    }

    public static function eventTypes(): array
    {
        return [
            self::EVENT_DECISION,
            self::EVENT_IMPRESSION,
            self::EVENT_CLICK,
            self::EVENT_SKIP,
            self::EVENT_CLOSE,
            self::EVENT_ERROR,
        ];
    }

    public static function decisionOutcomes(): array
    {
        return [
            self::OUTCOME_SHOW,
            self::OUTCOME_SKIP,
        ];
    }

    public static function deviceTypes(): array
    {
        return [
            self::DEVICE_DESKTOP,
            self::DEVICE_MOBILE,
            self::DEVICE_TABLET,
            self::DEVICE_UNKNOWN,
        ];
    }

    public function video(): BelongsTo
    {
        return $this->belongsTo(
            Video::class
        );
    }
}
