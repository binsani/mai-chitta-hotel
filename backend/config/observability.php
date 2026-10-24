<?php

return [
    'slow_requests' => [
        'enabled'      => (bool) env('LOG_SLOW_REQUESTS', false),
        'threshold_ms' => (int) env('SLOW_REQUEST_THRESHOLD_MS', 2000),
        'channel'      => env('SLOW_REQUEST_LOG_CHANNEL', 'stack'),
    ],
    'health_secret' => env('HEALTH_CHECK_SECRET'),
];
