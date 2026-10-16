<?php

return [
    'classifier_version' => 'video-health-classifier-v1',

    'rollout' => [
        'automatic_deactivation_enabled' => false,
    ],

    'launcher' => [
        'php_binary' => env('VIDEO_HEALTH_PHP_BINARY'),
    ],

    'probe' => [
        'user_agent' => 'VideoHealthMonitor/1.0',
        'connect_timeout' => 5,
        'request_timeout' => 15,
        'max_redirects' => 3,
        'max_body_bytes' => 1048576,
    ],

    'providers' => [
        'eporner' => [
            'approved_hosts' => ['eporner.com', 'www.eporner.com'],
            'dead_url_fragments' => ['/embed404/'],
            'strong_dead_markers' => ['This video is no longer available.'],
            'player_indicators' => [],
        ],

        'xvideos' => [
            'approved_hosts' => ['xvideos.com', 'www.xvideos.com'],
            'dead_url_fragments' => [],
            'strong_dead_markers' => ['This video has been deleted'],

            // Require a quoted media URL or a populated player configuration.
            // Bare HTML5Player, .mp4 and .m3u8 strings are not player evidence.
            'player_indicators' => [
                '~["\'](?:https?://|//|/)[^"\'<>\s]*\.(?:mp4|m3u8)(?:[?#][^"\'<>\s]*)?["\']~i',
                '~\b(?:HTML5Player|html5player)\s*\.\s*setVideoUrl(?:High|Low|HLS)?\s*\(\s*["\'](?:https?://|//|/)[^"\'<>\s]+["\']~i',
                '~["\']video_url["\']\s*:\s*["\'](?:https?://|//|/)[^"\'<>\s]+["\']~i',
            ],
        ],

        'xnxx' => [
            'approved_hosts' => ['xnxx.com', 'www.xnxx.com'],
            'dead_url_fragments' => [],
            'strong_dead_markers' => [],
            'player_indicators' => [],
        ],

        'redtube' => [
            'approved_hosts' => ['redtube.com', 'www.redtube.com', 'embed.redtube.com'],
            'dead_url_fragments' => [],
            'strong_dead_markers' => [],
            'player_indicators' => ['~\bmediaDefinitions\b~i'],
        ],
    ],
];
