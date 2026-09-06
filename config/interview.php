<?php

return [
    'service_token' => env('MAGIC_HTML_SERVICE_TOKEN'),
    'contracts_root' => base_path('vendor/yutoseta/magic-html-contracts'),
    'writes_per_minute' => (int) env('INTERVIEW_WRITES_PER_MINUTE', 120),
    'reads_per_minute' => (int) env('INTERVIEW_READS_PER_MINUTE', 300),
    'intake_share_ttl_minutes' => (int) env('INTERVIEW_INTAKE_SHARE_TTL_MINUTES', 10080),
    'idempotency' => [
        'lock_seconds' => (int) env('INTERVIEW_IDEMPOTENCY_LOCK_SECONDS', 30),
        'wait_seconds' => (int) env('INTERVIEW_IDEMPOTENCY_WAIT_SECONDS', 10),
    ],
    'route' => [
        // This service exposes only its versioned API contract.
        'enabled' => false,
    ],
];
