<?php

return [
    'default_version' => env('TISS_DEFAULT_VERSION', '202603'),

    'require_schema_validation' => (bool) env('TISS_REQUIRE_SCHEMA_VALIDATION', false),

    'schema_base_path' => env('TISS_SCHEMA_BASE_PATH', storage_path('app/tiss/schemas')),

    'transport' => [
        'driver' => env('TISS_TRANSPORT_DRIVER', 'mock'), // mock|http
        'environment' => env('TISS_TRANSPORT_ENVIRONMENT', 'production'), // production|sandbox

        'http' => [
            'timeout_seconds' => (int) env('TISS_HTTP_TIMEOUT_SECONDS', 30),
            'connect_timeout_seconds' => (int) env('TISS_HTTP_CONNECT_TIMEOUT_SECONDS', 10),
        ],
    ],

    'returns' => [
        'auto_process' => (bool) env('TISS_AUTO_PROCESS_RETURNS', true),
    ],

    'idempotency' => [
        'ttl_hours' => (int) env('TISS_IDEMPOTENCY_TTL_HOURS', 48),
    ],

    'queues' => [
        'xml' => env('TISS_QUEUE_XML', 'tiss-xml'),
        'send' => env('TISS_QUEUE_SEND', 'tiss-send'),
        'returns' => env('TISS_QUEUE_RETURNS', 'tiss-returns'),

        'max_attempts' => [
            'xml' => (int) env('TISS_QUEUE_XML_MAX_ATTEMPTS', 5),
            'send' => (int) env('TISS_QUEUE_SEND_MAX_ATTEMPTS', 5),
            'returns' => (int) env('TISS_QUEUE_RETURNS_MAX_ATTEMPTS', 7),
        ],
    ],
];
