<?php

use App\Services\VideoHealth\EmbedHealthClassifier;

beforeEach(function () {
    $this->configuration = require __DIR__ . '/../../config/video-health.php';
    $this->classifier = new EmbedHealthClassifier($this->configuration);

    $this->evidence = [
        'provider' => 'xvideos',
        'http_status' => 200,
        'effective_url' => 'https://www.xvideos.com/embedframe/example',
        'response_complete' => true,
        'redirect_approved' => true,
        'body' => '',
    ];
});

test('classifies complete evidence with the required safety precedence', function (
    array $changes,
    string $expected,
) {
    expect($this->classifier->classify(array_replace($this->evidence, $changes)))
        ->toBe($expected);
})->with([
    '404' => [['http_status' => 404], 'BROKEN'],
    '410' => [['http_status' => 410], 'BROKEN'],
    'Eporner dead effective path' => [[
        'provider' => 'eporner',
        'effective_url' => 'https://www.eporner.com/embed404/example/',
    ], 'BROKEN'],
    'Eporner strong dead marker' => [[
        'provider' => 'eporner',
        'effective_url' => 'https://www.eporner.com/embed/example/',
        'body' => 'This video is no longer available.',
    ], 'BROKEN'],
    'XVideos deleted without player' => [[
        'body' => 'This video has been deleted',
    ], 'BROKEN'],
    'XVideos deleted with player' => [[
        'body' => 'This video has been deleted <video src="https://cdn.example/live.mp4"></video>',
    ], 'REVIEW'],
    'valid player without dead marker' => [[
        'body' => '<video src="https://cdn.example/live.mp4"></video>',
    ], 'PASS'],
    'unrecognized 200' => [['body' => '<p>Unknown response</p>'], 'REVIEW'],
    '403 with dead marker' => [[
        'http_status' => 403,
        'body' => 'This video has been deleted',
    ], 'UNVERIFIABLE'],
    '429 with dead marker' => [[
        'http_status' => 429,
        'body' => 'This video has been deleted',
    ], 'UNVERIFIABLE'],
    '500 with dead marker' => [[
        'http_status' => 500,
        'body' => 'This video has been deleted',
    ], 'UNVERIFIABLE'],
    'timeout despite 404' => [['timeout' => true, 'http_status' => 404], 'UNVERIFIABLE'],
    'network failure despite 404' => [[
        'transport_error' => 'Connection reset', 'http_status' => 404,
    ], 'UNVERIFIABLE'],
    'TLS failure despite 410' => [[
        'transport_error' => 'TLS certificate verification failed', 'http_status' => 410,
    ], 'UNVERIFIABLE'],
    'truncated body despite dead marker' => [[
        'truncated' => true, 'body' => 'This video has been deleted',
    ], 'UNVERIFIABLE'],
    'incomplete body despite 404' => [[
        'response_complete' => false, 'http_status' => 404,
    ], 'UNVERIFIABLE'],
    'unsafe redirect despite 404' => [[
        'redirect_approved' => false, 'http_status' => 404,
    ], 'UNVERIFIABLE'],
    'missing player alone' => [['has_valid_player' => false], 'REVIEW'],
    'RedTube 404' => [[
        'provider' => 'redtube', 'http_status' => 404,
        'effective_url' => 'https://embed.redtube.com/?id=190868481',
    ], 'BROKEN'],
    'RedTube lookalike embed host' => [[
        'provider' => 'redtube', 'http_status' => 404,
        'effective_url' => 'https://embed.redtube.com.example/?id=190868481',
    ], 'UNVERIFIABLE'],
    'XNXX ambiguous 200' => [[
        'provider' => 'xnxx',
        'effective_url' => 'https://www.xnxx.com/embedframe/example',
        'body' => 'Unknown response',
    ], 'REVIEW'],
    '404 with valid player conflicts' => [[
        'http_status' => 404, 'has_valid_player' => true,
    ], 'REVIEW'],
    'dead redirect with valid player conflicts' => [[
        'provider' => 'eporner',
        'effective_url' => 'https://www.eporner.com/embed404/example/',
        'has_valid_player' => true,
    ], 'REVIEW'],
    '302 with player remains ambiguous' => [[
        'http_status' => 302, 'has_valid_player' => true,
    ], 'REVIEW'],
    'unapproved effective host' => [[
        'http_status' => 404,
        'effective_url' => 'https://www.xvideos.com.example/embedframe/example',
    ], 'UNVERIFIABLE'],
    'unapproved intermediate redirect' => [[
        'http_status' => 404,
        'redirect_urls' => ['https://unapproved.example/redirect'],
    ], 'UNVERIFIABLE'],
    'unsafe URL scheme' => [[
        'http_status' => 404, 'effective_url' => 'http://www.xvideos.com/example',
    ], 'UNVERIFIABLE'],
    'unknown provider' => [['provider' => 'unknown'], 'UNVERIFIABLE'],
    'bare representative tokens' => [[
        'body' => '<p>HTML5Player .mp4 .m3u8 setVideoUrl video_url</p>',
    ], 'REVIEW'],
    'dead URL fragment only in query is ambiguous' => [[
        'provider' => 'eporner',
        'effective_url' => 'https://www.eporner.com/embed/example/?next=/embed404/',
    ], 'REVIEW'],
]);

test('all 5xx responses override dead and player evidence', function (int $status) {
    $evidence = array_replace($this->evidence, [
        'http_status' => $status,
        'body' => 'This video has been deleted',
        'has_valid_player' => true,
    ]);

    expect($this->classifier->classify($evidence))->toBe('UNVERIFIABLE');
})->with(range(500, 599));

test('recognizes configured playback-shaped XVideos indicators', function (string $body) {
    expect($this->classifier->classify(array_replace($this->evidence, ['body' => $body])))
        ->toBe('PASS');
})->with([
    'MP4' => '<source src="https://cdn.example/live.mp4?token=example">',
    'HLS' => '<source src="https://cdn.example/live.m3u8">',
    'HTML5Player setter' => 'HTML5Player.setVideoUrl("https://cdn.example/stream");',
    'existing lowercase player setter' => 'html5player.setVideoURL("/video.example");',
    'video_url' => '{"video_url":"https://cdn.example/stream"}',
    'escaped JSON media URL' => '{"source":"https:\\/\\/cdn.example\\/live.mp4"}',
]);

test('missing required evidence fails closed', function (string $key) {
    $evidence = $this->evidence;
    unset($evidence[$key]);

    expect($this->classifier->classify($evidence))->toBe('UNVERIFIABLE');
})->with(['provider', 'http_status', 'effective_url', 'response_complete', 'redirect_approved']);

test('uses supplied signatures without provider-specific classifier branches', function () {
    $configuration = $this->configuration;
    $configuration['providers']['fixture'] = [
        'approved_hosts' => ['fixture.example'],
        'dead_url_fragments' => ['/removed/'],
        'strong_dead_markers' => ['Fixture content removed'],
        'player_indicators' => ['~fixture-player-ready~'],
    ];

    $classifier = new EmbedHealthClassifier($configuration);
    $evidence = array_replace($this->evidence, [
        'provider' => 'fixture', 'effective_url' => 'https://fixture.example/embed/example',
    ]);

    expect($classifier->classify(array_replace($evidence, ['body' => 'fixture-player-ready'])))
        ->toBe('PASS')
        ->and($classifier->classify(array_replace($evidence, ['body' => 'Fixture content removed'])))
        ->toBe('BROKEN')
        ->and($classifier->classify(array_replace($evidence, [
            'body' => 'Fixture content removed fixture-player-ready',
        ])))
        ->toBe('REVIEW')
        ->and($classifier->classify(array_replace($evidence, [
            'effective_url' => 'https://fixture.example/removed/example',
        ])))
        ->toBe('BROKEN');
});

test('generic-only providers do not inherit unvalidated dead markers', function (string $provider) {
    $evidence = array_replace($this->evidence, [
        'provider' => $provider,
        'effective_url' => 'https://www.' . $provider . '.com/embed/example',
        'body' => 'This video has been deleted. This video is no longer available.',
    ]);

    expect($this->classifier->classify($evidence))->toBe('REVIEW');
})->with(['xnxx', 'redtube']);

test('classification is deterministic and does not change evidence', function () {
    $evidence = array_replace($this->evidence, ['has_valid_player' => true]);
    $original = $evidence;

    expect($this->classifier->classify($evidence))->toBe('PASS')
        ->and($this->classifier->classify($evidence))->toBe('PASS')
        ->and($evidence)->toBe($original);
});

test('automatic deactivation defaults to disabled', function () {
    expect($this->configuration['rollout']['automatic_deactivation_enabled'])->toBeFalse();
});
