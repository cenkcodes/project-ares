<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'traffic_events',
            function (Blueprint $table): void {
                $table->bigIncrements('id');

                $table
                    ->uuid('event_uuid')
                    ->unique();

                $table
                    ->uuid('visitor_uuid');

                $table
                    ->uuid('session_uuid');

                $table
                    ->string(
                        'event_type',
                        32
                    );

                $table
                    ->string(
                        'page_type',
                        32
                    );

                $table
                    ->string(
                        'route_name',
                        160
                    )
                    ->nullable();

                $table
                    ->string(
                        'path',
                        2048
                    );

                $table
                    ->foreignId('video_id')
                    ->nullable()
                    ->constrained('videos')
                    ->nullOnDelete();

                $table
                    ->foreignId('category_id')
                    ->nullable()
                    ->constrained('categories')
                    ->nullOnDelete();

                $table
                    ->string(
                        'device_type',
                        16
                    );

                $table
                    ->timestampTz(
                        'occurred_at'
                    );

                $table->timestampsTz();

                $table->index(
                    [
                        'visitor_uuid',
                        'occurred_at',
                    ],
                    'traffic_events_visitor_time_idx'
                );

                $table->index(
                    [
                        'session_uuid',
                        'occurred_at',
                    ],
                    'traffic_events_session_time_idx'
                );

                $table->index(
                    [
                        'page_type',
                        'occurred_at',
                    ],
                    'traffic_events_page_time_idx'
                );

                $table->index(
                    [
                        'device_type',
                        'occurred_at',
                    ],
                    'traffic_events_device_time_idx'
                );

                $table->index(
                    [
                        'video_id',
                        'occurred_at',
                    ],
                    'traffic_events_video_time_idx'
                );

                $table->index(
                    [
                        'category_id',
                        'occurred_at',
                    ],
                    'traffic_events_category_time_idx'
                );
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'traffic_events'
        );
    }
};
