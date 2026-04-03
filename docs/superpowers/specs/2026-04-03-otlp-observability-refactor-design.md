# OTLP Observability Refactor Design

**Date:** 2026-04-03
**Status:** Approved
**Package:** modus-digital/laravel-monitoring

## Summary

Refactor the Laravel monitoring package from a Prometheus Pushgateway + direct Loki architecture to an OTLP-first observability package targeting the Grafana LGTM stack (Loki, Grafana, Tempo, Mimir/Prometheus) via Grafana Alloy.

This is a clean-slate rewrite — no backwards compatibility with the current API. The package is pre-release.

## Architecture

```
Laravel App
    |
    +-- HTTP Request --> StartRequestTrace middleware
    |       +-- Creates root span (trace_id, span_id)
    |       +-- Populates RequestContext singleton
    |       +-- On terminate: ends span, flushes all telemetry via OTLP/JSON
    |
    +-- Logs --> MonitoringLogProcessor enriches with trace_id, request_id
    |       +-- OtlpLogHandler exports via OTLP/JSON to Alloy
    |
    +-- Custom Metrics --> Monitoring::counter() / histogram() / gauge()
    |       +-- In-memory buffer, flushed via OTLP/JSON
    |
    +-- Manual Spans --> Monitoring::span('name', fn() => ...)
            +-- Child spans nested under request root span

Alloy routes:
    Traces  --> Tempo --> Prometheus (derived HTTP metrics: count, duration, error rate)
    Logs    --> Loki  (correlated via trace_id)
    Metrics --> Prometheus (custom app metrics)
```

## Key Design Decisions

| Decision | Choice | Rationale |
|----------|--------|-----------|
| OTLP implementation | Lightweight custom (bare HTTP/JSON via Laravel Http client) | No dependency on OTel PHP SDK; keeps package lightweight; OTLP over HTTP/JSON is simple |
| HTTP metrics | Derived from traces in Tempo | No need to push what Tempo already generates from spans |
| Custom metrics | In-memory buffer, exported via OTLP | Replaces cache + Pushgateway; simpler, no external state |
| Protobuf support | None (JSON only) | Payload sizes are small; JSON keeps us dependency-free |
| Backwards compatibility | None | Package is pre-release, clean slate |
| HTTP client | Laravel Http facade | Cleaner code, testable via Http::fake(), Guzzle already present in Laravel apps |
| Child spans | Manual API only | Root spans auto-created per request; users can add child spans; auto-instrumentation (DB, HTTP, queue) deferred to future work |

## Config

```php
// config/monitoring.php
return [
    'enabled' => env('MONITORING_ENABLED', true),

    'service' => [
        'name'        => env('MONITORING_SERVICE_NAME', config('app.name')),
        'environment' => env('MONITORING_ENVIRONMENT', config('app.env')),
        'instance_id' => env('MONITORING_SERVICE_INSTANCE_ID', config('app.url')),
    ],

    'otlp' => [
        'endpoint' => env('MONITORING_OTLP_ENDPOINT', 'http://127.0.0.1:4318'),
        'headers'  => env('MONITORING_OTLP_HEADERS'),
        'timeout'  => env('MONITORING_OTLP_TIMEOUT', 3),
    ],

    'traces' => [
        'enabled'     => env('MONITORING_TRACES_ENABLED', true),
        'sample_rate' => env('MONITORING_TRACE_SAMPLE_RATE', 1.0),
    ],

    'logs' => [
        'enabled' => env('MONITORING_LOGS_ENABLED', true),
    ],

    'metrics' => [
        'enabled' => env('MONITORING_METRICS_ENABLED', true),
    ],

    'middleware' => [
        'exclude' => [],
    ],
];
```

Three service identity fields: `name`, `environment`, `instance_id`. These become OTLP resource attributes on all telemetry.

## Contracts

### TracerContract

```php
interface TracerContract
{
    public function startSpan(string $name, array $attributes = []): Span;
    public function activeSpan(): ?Span;
    public function flush(): void;
}
```

### LogExporterContract

```php
interface LogExporterContract
{
    public function export(array $logRecords): void;
}
```

### MetricExporterContract

```php
interface MetricExporterContract
{
    public function export(array $metrics): void;
}
```

Each contract has an OTLP implementation and a Null implementation (for when the feature is disabled).

## Tracing

### Span

Value object representing a trace span:

- `traceId` (32-char hex) — shared across all spans in a trace
- `spanId` (16-char hex) — unique per span
- `parentSpanId` (nullable) — for child spans
- `name`, `startTime`, `endTime`
- `setAttribute(string $key, mixed $value): self`
- `addEvent(string $name, array $attributes = []): self`
- `setStatus(SpanStatus $status): self`
- `end(): void`
- `child(string $name, array $attributes = []): self`

### StartRequestTrace Middleware

Replaces the current `RecordMetrics` middleware.

On handle:
1. Read incoming `traceparent` header (W3C Trace Context) for distributed trace continuation
2. Start root span named `http.request`
3. Populate RequestContext singleton (trace_id, span_id, request_id, route, method)

On terminate:
1. Set response attributes: `http.status_code`, `http.status_group`
2. Record exceptions if any occurred
3. End root span
4. Flush tracer (exports to Alloy)

Span attributes (low cardinality):
- `http.method`
- `http.route` (route name or pattern, never raw URL)
- `http.status_code`
- `http.status_group` (1xx, 2xx, 3xx, 4xx, 5xx)
- `service.name`
- `deployment.environment`

### Manual Child Spans

```php
// Closure-based (recommended)
Monitoring::span('payment.process', function () {
    // code here
});

// Manual control
$span = Monitoring::startSpan('cache.rebuild');
// ... work ...
$span->end();
```

If no parent span exists (e.g., queue job), a new root span is created automatically.

## Request Context

Singleton populated by the middleware, consumed by log processor and anywhere needing correlation IDs:

```php
class RequestContext
{
    public readonly string $traceId;
    public readonly string $spanId;
    public readonly string $requestId;  // X-Request-ID header or generated
    public ?string $route = null;
    public ?string $method = null;
    public ?int $userId = null;
}
```

## Logging

### MonitoringLogProcessor

Monolog processor that enriches log records with context from RequestContext:
- `trace_id`
- `span_id`
- `request_id`
- `route`
- `method`
- `user_id`

This enables log-to-trace correlation in Grafana (Loki <-> Tempo).

### OtlpLogHandler

Monolog handler replacing the current LokiHandler:
- Converts Monolog LogRecord to OTLP log format
- Batches records in memory
- Flushes to `{otlp.endpoint}/v1/logs` via OtlpTransport
- Payload includes: timestamp (ns), severity, body, attributes, resource attributes

Registered as a `monitoring` log channel in Laravel's logging config.

## Metrics

### In-Memory Metric Classes

Simplified from cache-backed to plain PHP objects:

**Counter** — `increment()`, `incrementBy(float)`, `getValue()`
**Gauge** — `set(float)`, `increment()`, `decrement()`, `getValue()`
**Histogram** — `observe(float)`, `getBuckets()`, `getSum()`, `getCount()`

### MetricRegistry

In-memory registry, no cache:

```php
class MetricRegistry
{
    public function counter(string $name, array $labels = []): Counter;
    public function gauge(string $name, array $labels = []): Gauge;
    public function histogram(string $name, array $labels = [], ?array $buckets = null): Histogram;
    public function all(): array;
    public function reset(): void;
}
```

### Flush Strategy

- HTTP requests: middleware `terminate()` flushes alongside traces
- Queue jobs / commands: `Monitoring::flush()` or auto-flush on registry `__destruct`
- OTLP export: `{otlp.endpoint}/v1/metrics`
- Counters use cumulative temporality, gauges use gauge temporality, histograms use delta temporality

### Facade API

```php
Monitoring::counter('orders_processed', ['status' => 'completed'])->increment();
Monitoring::gauge('queue_depth', ['queue' => 'default'])->set(42);
Monitoring::histogram('payment_duration_ms', ['provider' => 'stripe'])->observe(230);
Monitoring::span('name', fn() => ...);
Monitoring::startSpan('name');
Monitoring::flush();
```

## OTLP Transport

Shared `OtlpTransport` class used by all three exporters:

- Uses Laravel Http facade (`Illuminate\Support\Facades\Http`)
- HTTP/JSON only (no protobuf)
- Endpoints: `/v1/traces`, `/v1/logs`, `/v1/metrics`
- Configurable timeout, custom headers
- Non-blocking where possible (called in `terminate()`)

## Package Structure

```
src/
├── Contracts/
│   ├── TracerContract.php
│   ├── LogExporterContract.php
│   └── MetricExporterContract.php
├── Context/
│   └── RequestContext.php
├── Otlp/
│   ├── OtlpTracer.php
│   ├── OtlpLogExporter.php
│   ├── OtlpMetricExporter.php
│   └── OtlpTransport.php
├── Null/
│   ├── NullTracer.php
│   ├── NullLogExporter.php
│   └── NullMetricExporter.php
├── Http/Middleware/
│   └── StartRequestTrace.php
├── Logging/
│   ├── OtlpLogHandler.php
│   └── MonitoringLogProcessor.php
├── Metrics/
│   ├── Counter.php
│   ├── Gauge.php
│   ├── Histogram.php
│   └── MetricRegistry.php
├── Tracing/
│   └── Span.php
├── Facades/
│   └── Monitoring.php
├── MonitoringServiceProvider.php
└── helpers.php
```

### Removed
- `Commands/PushMetrics.php` — no Pushgateway
- `Logging/LokiHandler.php` — replaced by OtlpLogHandler

### Renamed
- `LaravelMonitoringServiceProvider` → `MonitoringServiceProvider`
- `RecordMetrics` middleware → `StartRequestTrace`

## Testing

```
tests/
├── Otlp/
│   ├── OtlpTransportTest.php
│   ├── OtlpTracerTest.php
│   ├── OtlpLogExporterTest.php
│   └── OtlpMetricExporterTest.php
├── Metrics/
│   ├── CounterTest.php
│   ├── GaugeTest.php
│   ├── HistogramTest.php
│   └── MetricRegistryTest.php
├── Tracing/
│   └── SpanTest.php
├── Context/
│   └── RequestContextTest.php
├── Middleware/
│   └── StartRequestTraceTest.php
├── Logging/
│   ├── OtlpLogHandlerTest.php
│   └── MonitoringLogProcessorTest.php
├── ArchTest.php
├── Pest.php
└── TestCase.php
```

**Testing approach:**
- `Http::fake()` for all OTLP export tests — verify JSON payloads, endpoints, headers
- Null implementations verify no HTTP calls when disabled
- Span tests verify trace_id/span_id generation, parent-child relationships, W3C traceparent
- Middleware tests verify span creation, attribute recording, RequestContext population
- Log processor tests verify trace_id enrichment
- Metric tests are simple in-memory state checks (no cache mocking)
- Architecture tests: no dd/dump/ray
- PHPStan level 8, Laravel Pint formatting
- CI matrix: PHP 8.4/8.5 x Laravel 12/13

## What's NOT In Scope

- Auto-instrumentation (DB queries, HTTP client, queue jobs) — future enhancement
- Protobuf encoding — JSON only
- Grafana dashboard provisioning
- Sampling strategies beyond simple rate-based
- Backwards compatibility with current API
