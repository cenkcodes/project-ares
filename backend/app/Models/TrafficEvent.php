<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class TrafficEvent extends Model
{
    public const EVENT_PAGE_VIEW =
        'page_view';

    public const PAGE_HOME =
        'home';

    public const PAGE_CATALOG =
        'catalog';

    public const PAGE_SEARCH =
        'search';

    public const PAGE_CATEGORY =
        'category';

    public const PAGE_VIDEO_DETAIL =
        'video_detail';

    public const PAGE_CONTENT =
        'content';

    public const PAGE_OTHER =
        'other';

    public const DEVICE_DESKTOP =
        'desktop';

    public const DEVICE_MOBILE =
        'mobile';

    public const DEVICE_TABLET =
        'tablet';

    public const DEVICE_UNKNOWN =
        'unknown';

    protected $fillable = [
        'event_uuid',
        'visitor_uuid',
        'session_uuid',
        'event_type',
        'page_type',
        'route_name',
        'path',
        'video_id',
        'category_id',
        'device_type',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'video_id' =>
                'integer',

            'category_id' =>
                'integer',

            'occurred_at' =>
                'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(
            function (
                TrafficEvent $event
            ): void {
                if (
                    blank(
                        $event->event_uuid
                    )
                ) {
                    $event->event_uuid =
                        (string) Str::uuid();
                }

                if (
                    $event->occurred_at
                    === null
                ) {
                    $event->occurred_at =
                        now();
                }
            }
        );
    }
}
