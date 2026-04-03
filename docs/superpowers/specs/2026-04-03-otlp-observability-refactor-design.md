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

**Note:** The `config()` calls used as defaults (e.g., `config('app.name')`) may not resolve correctly when config is cached. The service provider resolves these at runtime — if the env var is unset and the config value is null, it falls back to `config('app.name')` etc. at boot time.

**Header format:** `MONITORING_OTLP_HEADERS` accepts comma-separated `key=value` pairs, e.g., `Authorization=Basic abc123,X-Scope-OrgID=tenant1`. Parsed by `OtlpTransport` into an associative array.

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
- `traceFlags` (int, default `1` = sampled) — propagated via W3C `traceparent`
- `kind` (SpanKind enum: `SERVER`, `CLIENT`, `INTERNAL`, `PRODUCER`, `CONSUMER`, default `INTERNAL`)
- `name`, `startTime`, `endTime`
- `setAttribute(string $key, mixed $value): self`
- `addEvent(string $name, array $attributes = []): self`
- `setStatus(SpanStatus $status): self`
- `end(): void`
- `child(string $name, array $attributes = []): self`

### SpanKind Enum

```php
enum SpanKind: int
{
    case INTERNAL = 1;
    case SERVER   = 2;
    case CLIENT   = 3;
    case PRODUCER = 4;
    case CONSUMER = 5;
}
```

The middleware creates root spans with `SpanKind::SERVER`. Manual child spans default to `SpanKind::INTERNAL`.

### SpanStatus Enum

```php
enum SpanStatus: int
{
    case UNSET = 0;
    case OK    = 1;
    case ERROR = 2;
}
```

Maps to OTLP `status.code`. The middleware sets `ERROR` for 5xx responses, `UNSET` otherwise (per OTLP convention, `OK` is only set explicitly by application code).

### StartRequestTrace Middleware

Replaces the current `RecordMetrics` middleware.

On handle:
1. Read incoming `traceparent` header (W3C Trace Context, format: `{version}-{trace-id}-{parent-id}-{trace-flags}`) for distributed trace continuation
2. Start root span named `http.request` with `SpanKind::SERVER`
3. If incoming `traceparent` has sampled flag unset AND local sample_rate would not sample, skip tracing for this request
4. Populate RequestContext singleton (trace_id, span_id, request_id, route, method)

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
- Queue jobs: hook into Laravel's `Queue::after` and `Queue::failing` events for automatic flush
- Commands: `register_shutdown_function` as safety net, or explicit `Monitoring::flush()`
- OTLP export: `{otlp.endpoint}/v1/metrics`
- All metric types use **delta temporality** — each flush exports only observations since the last flush. Since metrics are in-memory and scoped to a request/job lifecycle, every flush is naturally a delta. Alloy/Prometheus handles aggregation into cumulative series.

**Data loss policy:** Telemetry is fire-and-forget. If Alloy is unavailable, in-memory data is lost. This is acceptable — observability data is best-effort by design. No retry, no disk buffering.

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

## Sampling

Sampling is decided per-request in the middleware:

1. If an incoming `traceparent` header has the **sampled flag set** (`01`), the request is always traced — upstream made the decision, we respect it.
2. If no incoming `traceparent` or sampled flag is unset, the local `traces.sample_rate` config determines whether to sample (probabilistic, `rand() / getrandmax() < sample_rate`).
3. When a request is **not sampled**: no spans are created, but **metrics and logs are still collected**. Observability signals are independent — you may want logs without traces.
4. The sampling decision is propagated in the `traceparent` response header and on any outgoing `traceparent` headers (future scope for HTTP client tracing).

## Composer.json Updates

- Rename provider: `LaravelMonitoringServiceProvider` → `MonitoringServiceProvider` in `extra.laravel.providers`
- No new dependencies required (Laravel Http client is part of the framework)

## OTLP JSON Payload Examples

### Traces (`/v1/traces`)

```json
{
  "resourceSpans": [{
    "resource": {
      "attributes": [
        { "key": "service.name", "value": { "stringValue": "my-app" } },
        { "key": "deployment.environment", "value": { "stringValue": "production" } },
        { "key": "service.instance.id", "value": { "stringValue": "https://my-app.com" } }
      ]
    },
    "scopeSpans": [{
      "scope": { "name": "laravel-monitoring", "version": "2.0.0" },
      "spans": [{
        "traceId": "4bf92f3577b34da6a3ce929d0e0e4736",
        "spanId": "00f067aa0ba902b7",
        "parentSpanId": "",
        "name": "http.request",
        "kind": 2,
        "startTimeUnixNano": "1712150400000000000",
        "endTimeUnixNano": "1712150400150000000",
        "attributes": [
          { "key": "http.method", "value": { "stringValue": "GET" } },
          { "key": "http.route", "value": { "stringValue": "/api/orders" } },
          { "key": "http.status_code", "value": { "intValue": "200" } },
          { "key": "http.status_group", "value": { "stringValue": "2xx" } }
        ],
        "status": { "code": 0 },
        "traceState": "",
        "flags": 1
      }]
    }]
  }]
}
```

### Logs (`/v1/logs`)

```json
{
  "resourceLogs": [{
    "resource": {
      "attributes": [
        { "key": "service.name", "value": { "stringValue": "my-app" } },
        { "key": "deployment.environment", "value": { "stringValue": "production" } },
        { "key": "service.instance.id", "value": { "stringValue": "https://my-app.com" } }
      ]
    },
    "scopeLogs": [{
      "scope": { "name": "laravel-monitoring" },
      "logRecords": [{
        "timeUnixNano": "1712150400000000000",
        "severityNumber": 9,
        "severityText": "INFO",
        "body": { "stringValue": "Order created successfully" },
        "attributes": [
          { "key": "trace_id", "value": { "stringValue": "4bf92f3577b34da6a3ce929d0e0e4736" } },
          { "key": "span_id", "value": { "stringValue": "00f067aa0ba902b7" } },
          { "key": "request_id", "value": { "stringValue": "req-abc-123" } },
          { "key": "route", "value": { "stringValue": "/api/orders" } },
          { "key": "user_id", "value": { "intValue": "42" } }
        ],
        "traceId": "4bf92f3577b34da6a3ce929d0e0e4736",
        "spanId": "00f067aa0ba902b7"
      }]
    }]
  }]
}
```

### Metrics (`/v1/metrics`)

```json
{
  "resourceMetrics": [{
    "resource": {
      "attributes": [
        { "key": "service.name", "value": { "stringValue": "my-app" } },
        { "key": "deployment.environment", "value": { "stringValue": "production" } },
        { "key": "service.instance.id", "value": { "stringValue": "https://my-app.com" } }
      ]
    },
    "scopeMetrics": [{
      "scope": { "name": "laravel-monitoring" },
      "metrics": [
        {
          "name": "orders_processed",
          "sum": {
            "dataPoints": [{
              "startTimeUnixNano": "1712150400000000000",
              "timeUnixNano": "1712150400150000000",
              "asInt": "5",
              "attributes": [
                { "key": "status", "value": { "stringValue": "completed" } }
              ]
            }],
            "aggregationTemporality": 1,
            "isMonotonic": true
          }
        },
        {
          "name": "queue_depth",
          "gauge": {
            "dataPoints": [{
              "timeUnixNano": "1712150400150000000",
              "asInt": "42",
              "attributes": [
                { "key": "queue", "value": { "stringValue": "default" } }
              ]
            }]
          }
        },
        {
          "name": "payment_duration_ms",
          "histogram": {
            "dataPoints": [{
              "startTimeUnixNano": "1712150400000000000",
              "timeUnixNano": "1712150400150000000",
              "count": "3",
              "sum": 690.0,
              "bucketCounts": ["0", "0", "0", "1", "2", "3", "3", "3", "3", "3", "3", "3"],
              "explicitBounds": [5, 10, 25, 50, 100, 250, 500, 1000, 2500, 5000, 10000],
              "attributes": [
                { "key": "provider", "value": { "stringValue": "stripe" } }
              ]
            }],
            "aggregationTemporality": 1
          }
        }
      ]
    }]
  }]
}
```

**Note:** `aggregationTemporality: 1` = DELTA. All metrics use delta temporality since they are flushed per request lifecycle.

## What's NOT In Scope

- Auto-instrumentation (DB queries, HTTP client, queue jobs) — future enhancement
- Protobuf encoding — JSON only
- Grafana dashboard provisioning
- Sampling strategies beyond simple rate-based
- Backwards compatibility with current API
