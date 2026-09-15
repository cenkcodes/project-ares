<?php

return [
    'topic_index_exposure_enabled' => filter_var(
        env('TOPIC_INDEX_EXPOSURE_ENABLED', false),
        FILTER_VALIDATE_BOOLEAN
    ),
];
