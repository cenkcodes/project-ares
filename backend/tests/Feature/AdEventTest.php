<?php

use App\Models\AdEvent;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

test('ad event automatically generates an event uuid', function () {
    $event = AdEvent::create([
        'format' => AdEvent::FORMAT_BANNER,
        'event_type' => AdEvent::EVENT_IMPRESSION,
    ]);

    expect($event->event_uuid)
        ->not->toBeEmpty();

    expect(Str::isUuid($event->event_uuid))
        ->toBeTrue();
});

test('ad event automatically sets occurred at', function () {
    $event = AdEvent::create([
        'format' => AdEvent::FORMAT_NATIVE,
        'event_type' => AdEvent::EVENT_IMPRESSION,
    ]);

    expect($event->occurred_at)
        ->not->toBeNull();

    expect($event->occurred_at)
        ->toBeInstanceOf(
            \Carbon\CarbonImmutable::class
        );
});

test('ad event metadata is cast to an array', function () {
    $event = AdEvent::create([
        'format' => AdEvent::FORMAT_PREROLL,
        'event_type' => AdEvent::EVENT_DECISION,
        'decision_outcome' => AdEvent::OUTCOME_SKIP,
        'decision_reason' => 'provider_has_own_ads',
        'metadata' => [
            'provider_has_own_ads' => true,
            'interaction_number' => 1,
        ],
    ]);

    $event->refresh();

    expect($event->metadata)
        ->toBeArray()
        ->and($event->metadata['provider_has_own_ads'])
        ->toBeTrue()
        ->and($event->metadata['interaction_number'])
        ->toBe(1);
});

test('ad event normalizes currency to uppercase', function () {
    $event = AdEvent::create([
        'format' => AdEvent::FORMAT_POPUNDER,
        'event_type' => AdEvent::EVENT_IMPRESSION,
        'revenue_micros' => 250000,
        'currency' => 'usd',
    ]);

    expect($event->currency)
        ->toBe('USD');
});

test('ad event can belong to a video', function () {
    $video = Video::create([
        'title' => 'Ad Event Relationship Test',
        'slug' => 'ad-event-relationship-test',
        'embed_url' => 'https://example.com/embed/test',
        'video_source' => 'test-provider',
        'views' => 0,
        'is_hd' => false,
        'is_4k' => false,
        'is_featured' => false,
        'is_premium' => false,
        'is_active' => true,
    ]);

    $event = AdEvent::create([
        'video_id' => $video->id,
        'provider_slug' => 'test-provider',
        'format' => AdEvent::FORMAT_BANNER,
        'event_type' => AdEvent::EVENT_IMPRESSION,
    ]);

    expect($event->video)
        ->toBeInstanceOf(Video::class)
        ->and($event->video->id)
        ->toBe($video->id);
});

test('ad event can exist without a video', function () {
    $event = AdEvent::create([
        'placement_key' => 'home_grid',
        'format' => AdEvent::FORMAT_NATIVE,
        'event_type' => AdEvent::EVENT_IMPRESSION,
    ]);

    expect($event->video_id)
        ->toBeNull()
        ->and($event->video)
        ->toBeNull();
});

test('deleting a video keeps its ad events and nulls video id', function () {
    $video = Video::create([
        'title' => 'Ad Event Delete Test',
        'slug' => 'ad-event-delete-test',
        'embed_url' => 'https://example.com/embed/delete-test',
        'video_source' => 'test-provider',
        'views' => 0,
        'is_hd' => false,
        'is_4k' => false,
        'is_featured' => false,
        'is_premium' => false,
        'is_active' => true,
    ]);

    $event = AdEvent::create([
        'video_id' => $video->id,
        'provider_slug' => 'test-provider',
        'format' => AdEvent::FORMAT_PREROLL,
        'event_type' => AdEvent::EVENT_DECISION,
        'decision_outcome' => AdEvent::OUTCOME_SHOW,
        'decision_reason' => 'eligible',
    ]);

    $eventId = $event->id;

    $video->delete();

    $event = AdEvent::findOrFail(
        $eventId
    );

    expect($event->video_id)
        ->toBeNull()
        ->and($event->video)
        ->toBeNull();
});
