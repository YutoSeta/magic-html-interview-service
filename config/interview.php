<?php

return [
    'service_token' => env('MAGIC_HTML_SERVICE_TOKEN'),
    'writes_per_minute' => (int) env('INTERVIEW_WRITES_PER_MINUTE', 120),
    'reads_per_minute' => (int) env('INTERVIEW_READS_PER_MINUTE', 300),
    'route' => [
        // This service exposes only its versioned API contract.
        'enabled' => false,
    ],
];
