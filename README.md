# Laravel Monitoring

[![Latest Version on Packagist](https://img.shields.io/packagist/v/modus-digital/laravel-monitoring.svg?style=flat-square)](https://packagist.org/packages/modus-digital/laravel-monitoring)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/modus-digital/laravel-monitoring/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/modus-digital/laravel-monitoring/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/modus-digital/laravel-monitoring/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/modus-digital/laravel-monitoring/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/modus-digital/laravel-monitoring.svg?style=flat-square)](https://packagist.org/packages/modus-digital/laravel-monitoring)

OTLP-first observability for Laravel — traces, logs, and metrics via Grafana Alloy.

- Distributed tracing with W3C `traceparent` propagation
- Auto-instrumentation for DB queries, HTTP client, cache, and queue jobs
- HTTP request metrics (counter + histogram) for Grafana dashboards
- OTLP log shipping as a native Laravel log channel
- Custom counters, gauges, and histograms
- Exception reporting on active spans
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

    // Auto-instrumentation creates child spans for these operations.
    'auto_instrumentation' => [
        'db'          => env('MONITORING_INSTRUMENT_DB', true),
        'http_client' => env('MONITORING_INSTRUMENT_HTTP_CLIENT', true),
        'cache'       => env('MONITORING_INSTRUMENT_CACHE', true),
        'queue'       => env('MONITORING_INSTRUMENT_QUEUE', true),
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
- Records `http_requests_total` counter and `http_request_duration_ms` histogram (even when tracing is unsampled)
- Parses incoming `traceparent` headers for distributed trace propagation
- Records `http.method`, `http.route`, `http.status_code`, and `http.status_group` attributes
- Sets `ERROR` status on 5xx responses
- Populates a `RequestContext` singleton for log correlation
- Respects the `traces.sample_rate` config and upstream sampling decisions
- Flushes all telemetry inline (after the response is sent)

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

### Auto-Instrumentation

The package automatically creates child spans for common Laravel operations. Each can be toggled via config or env vars.

**Database queries** — `db.query` spans with SQL, driver, duration, and connection name:
```env
MONITORING_INSTRUMENT_DB=true
```

**HTTP client calls** — `http.client` spans with method, URL, and status code. Sets `ERROR` status on 5xx responses:
```env
MONITORING_INSTRUMENT_HTTP_CLIENT=true
```

**Cache operations** — `cache.hit`, `cache.miss`, `cache.write`, `cache.forget` spans with key and store:
```env
MONITORING_INSTRUMENT_CACHE=true
```

**Queue jobs** — `queue.job` spans with job class, queue, connection, and attempt. Records exception events on failure:
```env
MONITORING_INSTRUMENT_QUEUE=true
```

All auto-instrumentation requires an active parent span (created by the middleware). When a request is unsampled, listeners are no-ops.

### Exception Handling

Report exceptions on the active trace span using the `Monitoring` facade:

```php
// bootstrap/app.php
->withExceptions(function (Exceptions $exceptions) {
    $exceptions->reportable(function (Throwable $e) {
        \ModusDigital\LaravelMonitoring\Facades\Monitoring::reportException($e);
    });
})
```

This records an `exception` event on the active span with `exception.type`, `exception.message`, and `exception.stacktrace`, and sets the span status to `ERROR`. Safe to call when no active span exists (no-op).

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

1. The `StartRequestTrace` middleware creates a root span, records HTTP metrics, and populates `RequestContext`
2. Auto-instrumentation listeners create child spans for DB queries, HTTP client calls, cache operations, and queue jobs
3. Your app records custom spans and metrics via the `Monitoring` facade
4. The `MonitoringLogProcessor` enriches log records with trace context
5. The middleware ends the root span, flushes traces via OTLP/JSON to `/v1/traces`, and exports metrics to `/v1/metrics`
6. The `OtlpLogHandler` buffers log records and flushes them to `/v1/logs` on close
7. All in-memory, no cache driver or external state needed

All OTLP payloads include resource attributes (`service.name`, `deployment.environment`, `service.instance.id`) for identification in Grafana.

## Architecture

```
Laravel App
  ├── StartRequestTrace (middleware)
  │     ├── Creates root Span (SERVER kind)
  │     ├── Records http_requests_total + http_request_duration_ms metrics
  │     ├── Populates RequestContext
  │     └── Flushes traces + metrics inline
  ├── Auto-Instrumentation (event listeners)
  │     ├── TraceDbQueries       → db.query child spans
  │     ├── TraceHttpClient      → http.client child spans
  │     ├── TraceCacheOperations → cache.hit/miss/write/forget child spans
  │     └── TraceQueueJobs       → queue.job root spans
  ├── Monitoring Facade
  │     ├── span() / startSpan()  → TracerContract → OtlpTracer
  │     ├── reportException()     → records exception on active span
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
