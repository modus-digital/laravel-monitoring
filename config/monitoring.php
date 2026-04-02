<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Prometheus Pushgateway
    |--------------------------------------------------------------------------
    */
    'pushgateway' => [
        'enabled' => env('MONITORING_ENABLED', true),
        'url' => env('PROMETHEUS_PUSHGATEWAY_URL', '127.0.0.1:9091'),
        'auth' => env('PROMETHEUS_PUSHGATEWAY_AUTH', ''),
        'job_name' => env('MONITORING_JOB_NAME', null), // null = config('app.name')
    ],

    /*
    |--------------------------------------------------------------------------
    | Loki Log Shipping
    |--------------------------------------------------------------------------
    */
    'loki' => [
        'enabled' => env('LOKI_ENABLED', true),
        'url' => env('LOKI_ENTRYPOINT'),
        'auth' => env('LOKI_AUTH'),
        'level' => env('LOG_LEVEL', 'debug'),

        'labels' => [
            'app' => env('APP_NAME', 'laravel'),
            'env' => env('APP_ENV', 'production'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP Metrics Middleware
    |--------------------------------------------------------------------------
    */
    'middleware' => [
        'auto_register' => env('MONITORING_AUTO_MIDDLEWARE', true),

        'exclude' => [
            '_debugbar',
            '_ignition',
            'telescope',
            'horizon',
            'livewire/update',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache
    |--------------------------------------------------------------------------
    | The cache store used to buffer metrics between pushes.
    */
    'cache' => [
        'store' => env('MONITORING_CACHE_STORE', null), // null = app's default cache store
        'key_prefix' => env('MONITORING_CACHE_PREFIX', 'monitoring'),
        'ttl' => 3600,
    ],
];
