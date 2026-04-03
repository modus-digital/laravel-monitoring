<?php

return [
    'enabled' => env('MONITORING_ENABLED', true),

    'service' => [
        'name' => env('MONITORING_SERVICE_NAME'),
        'environment' => env('MONITORING_ENVIRONMENT'),
        'instance_id' => env('MONITORING_SERVICE_INSTANCE_ID'),
    ],

    'otlp' => [
        'endpoint' => env('MONITORING_OTLP_ENDPOINT', 'http://127.0.0.1:4318'),
        'headers' => env('MONITORING_OTLP_HEADERS'),
        'timeout' => env('MONITORING_OTLP_TIMEOUT', 3),
    ],

    'traces' => [
        'enabled' => env('MONITORING_TRACES_ENABLED', true),
        'sample_rate' => env('MONITORING_TRACE_SAMPLE_RATE', 1.0),
    ],

    'logs' => [
        'enabled' => env('MONITORING_LOGS_ENABLED', true),
    ],

    'metrics' => [
        'enabled' => env('MONITORING_METRICS_ENABLED', true),
    ],

    // Routes to exclude from tracing. Matches against both route names and URL paths.
    // Example: ['health', 'horizon'] excludes /health and any route named "horizon.*"
    'middleware' => [
        'exclude' => [],
    ],
];
