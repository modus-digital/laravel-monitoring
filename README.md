# Laravel Monitoring

[![Latest Version on Packagist](https://img.shields.io/packagist/v/modus-digital/laravel-monitoring.svg?style=flat-square)](https://packagist.org/packages/modus-digital/laravel-monitoring)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/modus-digital/laravel-monitoring/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/modus-digital/laravel-monitoring/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/modus-digital/laravel-monitoring/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/modus-digital/laravel-monitoring/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/modus-digital/laravel-monitoring.svg?style=flat-square)](https://packagist.org/packages/modus-digital/laravel-monitoring)

OTLP-first observability for Laravel — traces, logs, and metrics via Grafana Alloy.

- Distributed tracing with W3C `traceparent` propagation
- OTLP log shipping as a native Laravel log channel
- Custom counters, gauges, and histograms
- All telemetry exported as OTLP/JSON to Grafana Alloy (or any OTLP-compatible collector)
- No scheduler, no cron — everything flushes per-request automatically

## Requirements

- PHP 8.4+
- Laravel 12 or 13
- An OTLP-compatible collector (e.g., [Grafana Alloy](https://grafana.com/docs/alloy/))

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
MONITORING_ENABLED=true
MONITORING_OTLP_ENDPOINT=http://alloy:4318
MONITORING_SERVICE_NAME=my-app
MONITORING_ENVIRONMENT=production
```

### Full config reference

```php
// config/monitoring.php
return [
    'enabled' => env('MONITORING_ENABLED', true),

    'service' => [
        'name'        => env('MONITORING_SERVICE_NAME'),        // defaults to config('app.name')
        'environment' => env('MONITORING_ENVIRONMENT'),          // defaults to config('app.env')
        'instance_id' => env('MONITORING_SERVICE_INSTANCE_ID'),  // defaults to config('app.url')
    ],

    'otlp' => [
        'endpoint' => env('MONITORING_OTLP_ENDPOINT', 'http://127.0.0.1:4318'),
        'headers'  => env('MONITORING_OTLP_HEADERS'),  // comma-separated: 'X-Scope-OrgID=tenant1,Authorization=Basic abc'
        'timeout'  => env('MONITORING_OTLP_TIMEOUT', 3),
    ],

    'traces' => [
        'enabled'     => env('MONITORING_TRACES_ENABLED', true),
        'sample_rate' => env('MONITORING_TRACE_SAMPLE_RATE', 1.0),  // 0.0 to 1.0
    ],

    'logs' => [
        'enabled' => env('MONITORING_LOGS_ENABLED', true),
    ],

    'metrics' => [
        'enabled' => env('MONITORING_METRICS_ENABLED', true),
    ],

    // Routes to exclude from tracing. Matches against both route names and URL paths.
    'middleware' => [
        'exclude' => [],
    ],
];
```

## Middleware Setup

Register the `StartRequestTrace` middleware to automatically trace HTTP requests:

```php
// bootstrap/app.php (Laravel 12+)
->withMiddleware(function (Middleware $middleware) {
    $middleware->append(\ModusDigital\LaravelMonitoring\Http\Middleware\StartRequestTrace::class);
})
```

This middleware:
- Creates a root span for each HTTP request with `SERVER` kind
- Parses incoming `traceparent` headers for distributed trace propagation
- Records `http.method`, `http.route`, `http.status_code`, and `http.status_group` attributes
- Sets `ERROR` status on 5xx responses
- Populates a `RequestContext` singleton for log correlation
- Respects the `traces.sample_rate` config and upstream sampling decisions
- Flushes all telemetry on `terminate()` (after the response is sent)

## Usage

### Tracing

Wrap operations in spans using the `Monitoring` facade:

```php
use ModusDigital\LaravelMonitoring\Facades\Monitoring;

// Automatic span — wraps a closure, records exceptions, rethrows
$result = Monitoring::span('orders.process', function () {
    return Order::process($data);
});

// Manual span — for more control
$span = Monitoring::startSpan('external.api.call');
$span->setAttribute('api.endpoint', 'https://api.example.com/v1/users');
try {
    $response = Http::get('https://api.example.com/v1/users');
    $span->setAttribute('http.status_code', $response->status());
} finally {
    $span->end();
}
```

### Custom Metrics

Use the `Monitoring` facade or the `monitoring()` helper:

#### Counters

Counters only go up. Use them for totals (requests, orders, errors).

```php
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
```

#### Histograms

Histograms observe values into configurable buckets. Use them for durations, sizes, etc.

```php
Monitoring::histogram('response_time_ms', ['endpoint' => '/api/users'])->observe(123.5);

// Custom buckets (default: 5, 10, 25, 50, 100, 250, 500, 1000, 2500, 5000, 10000)
Monitoring::histogram('payload_size_bytes', [], [100, 500, 1000, 5000, 10000])->observe(2048);
```

### Labels

All metric types accept an optional labels array. Label order doesn't matter — `['a' => '1', 'b' => '2']` and `['b' => '2', 'a' => '1']` resolve to the same metric.

### Flushing

Telemetry is flushed automatically:
- **Traces and logs**: Flushed on `terminate()` after each HTTP response
- **Metrics in queue jobs**: Flushed automatically via `Queue::after` and `Queue::failing` hooks
- **Manual flush**: Call `Monitoring::flush()` to export all pending traces and metrics

No scheduler or cron job is needed.

## Log Shipping

The package registers a `monitoring` log channel automatically. Add it to your logging stack:

```php
// config/logging.php
'channels' => [
    'stack' => [
        'driver' => 'stack',
        'channels' => ['daily', 'monitoring'],
    ],
],
```

Log records are automatically enriched with trace context (`trace_id`, `span_id`, `request_id`, `route`, `method`, `user_id`) so you can correlate logs with traces in Grafana.

## How It Works

1. The `StartRequestTrace` middleware creates a root span and `RequestContext` for each request
2. Your app records custom spans and metrics via the `Monitoring` facade
3. The `MonitoringLogProcessor` enriches log records with trace context
4. On `terminate()`, the middleware ends the root span and flushes traces via OTLP/JSON to `/v1/traces`
5. The `OtlpLogHandler` buffers log records and flushes them to `/v1/logs` on close
6. Metrics are exported to `/v1/metrics` on flush — all in-memory, no cache driver needed

All OTLP payloads include resource attributes (`service.name`, `deployment.environment`, `service.instance.id`) for identification in Grafana.

## Architecture

```
Laravel App
  ├── StartRequestTrace (middleware)
  │     ├── Creates root Span (SERVER kind)
  │     ├── Populates RequestContext
  │     └── Flushes on terminate()
  ├── Monitoring Facade
  │     ├── span() / startSpan()  → TracerContract → OtlpTracer
  │     ├── counter() / gauge() / histogram()  → MetricRegistry
  │     └── flush()  → exports traces + metrics
  ├── Log Channel ("monitoring")
  │     ├── MonitoringLogProcessor (enriches with trace context)
  │     └── OtlpLogHandler → OtlpLogExporter
  └── OtlpTransport (shared HTTP/JSON client)
        ├── POST /v1/traces   (traces)
        ├── POST /v1/logs     (logs)
        └── POST /v1/metrics  (metrics)
              ↓
        Grafana Alloy → Tempo / Loki / Mimir
```

## Testing

```bash
composer test              # Run tests (Pest)
composer test-coverage     # Tests with coverage
composer analyse           # PHPStan level 8
composer format            # Laravel Pint code style
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Credits

- [Alex van Steenhoven](https://github.com/modus-digital)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
