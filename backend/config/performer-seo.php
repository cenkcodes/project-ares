<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Performer Search Exposure
    |--------------------------------------------------------------------------
    |
    | Keep disabled until the first performer landing page has passed local,
    | production and SEO smoke validation. This is intentionally independent
    | from Topic and Guide exposure.
    |
    */
    'index_exposure_enabled' => false,

    /*
    |--------------------------------------------------------------------------
    | Minimum Active Inventory
    |--------------------------------------------------------------------------
    |
    | A performer reference page must retain enough currently active videos
    | to justify search exposure.
    |
    */
    'minimum_active_videos_for_index' => 5,

    /*
    |--------------------------------------------------------------------------
    | Explicit Performer Reference Pages
    |--------------------------------------------------------------------------
    |
    | Performer pages are intentionally curated. They are not generated from
    | fuzzy title searches at request time and they do not alter taxonomy,
    | Topic Intelligence or provider source terms.
    |
    */
    'performers' => [
        'madison-morgan' => [
            'is_active' => true,
            'is_indexable' => true,

            'name' => 'Madison Morgan',

            'title' => 'Madison Morgan Videos | Xurvexa',

            'h1' => 'Madison Morgan Videos',

            'meta_description' =>
                'Browse active Madison Morgan videos currently available on Xurvexa in one focused reference page.',

            'intro' =>
                'This reference page groups active Xurvexa catalog entries whose current video titles identify Madison Morgan. Availability can change when an upstream video source removes an item.',

            'published_at' => '2026-10-21',
            'updated_at' => '2026-10-21',

            'video_slugs' => [
                'eporner-ea7vy3z33cd',
                'eporner-jmtdwo76biy',
                'eporner-rfqqivfnmck',
                'eporner-dpmlht9tynp',
                'eporner-rsi0cxhoszu',
                'eporner-rdnrwi6fhad',
                'eporner-2sfyteulkpy',
            ],
        ],
    ],
];
