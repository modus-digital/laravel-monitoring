# Laravel Monitoring

[![Latest Version on Packagist](https://img.shields.io/packagist/v/modus-digital/laravel-monitoring.svg?style=flat-square)](https://packagist.org/packages/modus-digital/laravel-monitoring)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/modus-digital/laravel-monitoring/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/modus-digital/laravel-monitoring/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/modus-digital/laravel-monitoring/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/modus-digital/laravel-monitoring/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/modus-digital/laravel-monitoring.svg?style=flat-square)](https://packagist.org/packages/modus-digital/laravel-monitoring)

Drop-in Prometheus + Loki monitoring for Laravel apps. Push metrics to Pushgateway and ship logs to Loki with zero extra dependencies.

- Automatic HTTP request metrics (counter + duration histogram)
- Custom counters, gauges, and histograms via a simple API
- Scheduled push to Prometheus Pushgateway
- Loki log shipping as a native Laravel log channel
- Works with any Laravel cache driver (Redis, Memcached, database, file)

## Requirements

- PHP 8.4+
- Laravel 12 or 13

## Installation

```bash
composer require modus-digital/laravel-monitoring
```

Publish the config file:

```bash
php artisan vendor:publish --tag="monitoring-config"
```

## Configuration

Add these to your `.env`:

```env
# Pushgateway
MONITORING_ENABLED=true
PROMETHEUS_PUSHGATEWAY_URL=http://pushgateway:9091
MONITORING_PUSH_SCHEDULE=everyMinute

# Loki
LOKI_ENABLED=true
LOKI_ENTRYPOINT=http://loki:3100

# Cache store for metrics (null = default cache driver)
MONITORING_CACHE_STORE=redis
```

### Full config reference

```php
// config/monitoring.php
return [
    'pushgateway' => [
        'enabled'  => env('MONITORING_ENABLED', true),
        'url'      => env('PROMETHEUS_PUSHGATEWAY_URL', '127.0.0.1:9091'),
        'auth'     => env('PROMETHEUS_PUSHGATEWAY_AUTH', ''),
        'job_name' => env('MONITORING_JOB_NAME', null),     // null = config('app.name')
        'schedule' => env('MONITORING_PUSH_SCHEDULE', 'everyMinute'), // null to disable
    ],

    'loki' => [
        'enabled' => env('LOKI_ENABLED', true),
        'url'     => env('LOKI_ENTRYPOINT'),
        'auth'    => env('LOKI_AUTH'),
        'level'   => env('LOG_LEVEL', 'debug'),
        'labels'  => [
            'app' => env('APP_NAME', 'laravel'),
            'env' => env('APP_ENV', 'production'),
        ],
    ],

    'middleware' => [
        'auto_register' => env('MONITORING_AUTO_MIDDLEWARE', true),
        'exclude' => [
            '_debugbar', '_ignition', 'telescope', 'horizon', 'livewire/update',
        ],
    ],

    'cache' => [
        'store'      => env('MONITORING_CACHE_STORE', null),
        'key_prefix' => env('MONITORING_CACHE_PREFIX', 'monitoring'),
        'ttl'        => 3600,
    ],
];
```

## Usage

### Automatic HTTP metrics

Out of the box, the package records two metrics for every HTTP request:

- `http_requests_total` (counter) — labeled by method, route, status, status_group
- `http_request_duration_ms` (histogram) — labeled by method, route

This is handled by the `RecordMetrics` middleware, which is auto-registered globally. Disable it with `MONITORING_AUTO_MIDDLEWARE=false`.

### Custom metrics

Use the `Monitoring` facade or the `monitoring()` helper anywhere in your app:

#### Counters

Counters only go up. Use them for totals (requests, orders, errors).

```php
use ModusDigital\LaravelMonitoring\Facades\Monitoring;

Monitoring::counter('orders_total', ['payment_method' => 'stripe'])->increment();
Monitoring::counter('orders_total', ['payment_method' => 'stripe'])->incrementBy(5);

// Or via the helper
monitoring()->counter('orders_total')->increment();
```

#### Gauges

Gauges go up and down. Use them for current values (queue depth, active users).

```php
Monitoring::gauge('queue_depth', ['queue' => 'emails'])->set(42);
Monitoring::gauge('queue_depth', ['queue' => 'emails'])->increment();
Monitoring::gauge('queue_depth', ['queue' => 'emails'])->decrement();
Monitoring::gauge('queue_depth', ['queue' => 'emails'])->incrementBy(5);
Monitoring::gauge('queue_depth', ['queue' => 'emails'])->decrementBy(3);
```

#### Histograms

Histograms observe values into configurable buckets. Use them for durations, sizes, etc.

```php
Monitoring::histogram('response_time_ms', ['endpoint' => '/api/users'])->observe(123.5);

// Custom buckets (default: 5, 10, 25, 50, 100, 250, 500, 1000, 2500, 5000, 10000)
Monitoring::histogram('payload_size_bytes', [], [100, 500, 1000, 5000, 10000])->observe(2048);
```

### Labels

All metric types accept an optional labels array. Labels are key-value pairs that add dimensions to your metrics:

```php
Monitoring::counter('api_errors_total', [
    'endpoint' => '/api/users',
    'error_type' => 'validation',
])->increment();
```

Label order doesn't matter — `['a' => '1', 'b' => '2']` and `['b' => '2', 'a' => '1']` resolve to the same metric.

### Pushing metrics

Metrics are pushed to Prometheus Pushgateway via the `monitoring:push` command. By default, this is auto-scheduled every minute when pushgateway is enabled.

```bash
# Manual push
php artisan monitoring:push

# Preview what would be pushed (no HTTP call)
php artisan monitoring:push --dry-run
```

To change the schedule, set `MONITORING_PUSH_SCHEDULE` to any Laravel schedule method name (`everyFiveMinutes`, `everyTenMinutes`, etc.) or `null` to disable auto-scheduling.

### Prometheus output example

```
# HELP http_requests_total http_requests_total
# TYPE http_requests_total counter
http_requests_total{method="GET",route="/api/users",status="200",status_group="2xx"} 1523

# HELP queue_depth queue_depth
# TYPE queue_depth gauge
queue_depth{queue="emails"} 17

# HELP http_request_duration_ms http_request_duration_ms
# TYPE http_request_duration_ms histogram
http_request_duration_ms_bucket{method="GET",route="/api/users",le="50"} 12
http_request_duration_ms_bucket{method="GET",route="/api/users",le="100"} 45
http_request_duration_ms_bucket{method="GET",route="/api/users",le="+Inf"} 50
http_request_duration_ms_sum{method="GET",route="/api/users"} 4523.5
http_request_duration_ms_count{method="GET",route="/api/users"} 50
```

### Grafana queries

Once metrics are in Prometheus, use these PromQL queries in Grafana:

```promql
# Request rate by status group
sum by (status_group) (rate(http_requests_total[5m]))

# Average response time
rate(http_request_duration_ms_sum[5m]) / rate(http_request_duration_ms_count[5m])

# P95 response time
histogram_quantile(0.95, rate(http_request_duration_ms_bucket[5m]))

# Error rate
sum(rate(http_requests_total{status_group="5xx"}[5m])) / sum(rate(http_requests_total[5m]))
```

## Loki log shipping

The package registers a `loki` log channel automatically. Add it to your logging stack:

```php
// config/logging.php
'channels' => [
    'stack' => [
        'driver' => 'stack',
        'channels' => ['daily', 'loki'],
    ],
],
```

Each log entry is shipped with contextual data: message, route, method, user_id, request_id, and configurable stream labels (app, env, level, channel).

## How it works

1. The `RecordMetrics` middleware records HTTP counters and duration histograms on every request
2. Your app records custom metrics via the `Monitoring` facade
3. All metrics are buffered in Laravel's cache (any driver)
4. The scheduled `monitoring:push` command reads all metrics, formats them as Prometheus text, and POSTs to Pushgateway
5. Counters and histograms are reset after each push; gauges persist (they represent current state)

## Testing

```bash
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Credits

- [Alex van Steenhoven](https://github.com/modus-digital)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
