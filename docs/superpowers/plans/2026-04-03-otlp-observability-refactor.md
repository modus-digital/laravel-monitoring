# OTLP Observability Refactor Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rewrite the Laravel monitoring package from Prometheus Pushgateway + direct Loki to an OTLP-first observability package targeting the Grafana LGTM stack via Alloy.

**Architecture:** All telemetry (traces, logs, metrics) flows through a shared `OtlpTransport` using Laravel's Http facade to POST JSON to Alloy's OTLP endpoints (`/v1/traces`, `/v1/logs`, `/v1/metrics`). A `StartRequestTrace` middleware creates root spans and flushes all signals on `terminate()`. Metrics are in-memory (no cache), logs enriched with trace context via Monolog processor.

**Tech Stack:** PHP 8.4+, Laravel 12/13, Pest 4, PHPStan level 8, Laravel Pint, Orchestra Testbench

**Spec:** `docs/superpowers/specs/2026-04-03-otlp-observability-refactor-design.md`

---

## File Map

### Files to Create

| File | Responsibility |
|------|---------------|
| `src/Tracing/SpanKind.php` | Enum: INTERNAL, SERVER, CLIENT, PRODUCER, CONSUMER |
| `src/Tracing/SpanStatus.php` | Enum: UNSET, OK, ERROR |
| `src/Tracing/Span.php` | Value object: trace/span IDs, attributes, events, parent-child |
| `src/Context/RequestContext.php` | Singleton: trace_id, span_id, request_id, route, method, user_id |
| `src/Contracts/TracerContract.php` | Interface: startSpan, activeSpan, flush |
| `src/Contracts/LogExporterContract.php` | Interface: export(array $logRecords) |
| `src/Contracts/MetricExporterContract.php` | Interface: export(array $metrics) |
| `src/Otlp/OtlpTransport.php` | Shared HTTP/JSON client for all OTLP endpoints |
| `src/Otlp/OtlpTracer.php` | TracerContract impl: manages spans, exports via OtlpTransport |
| `src/Otlp/OtlpLogExporter.php` | LogExporterContract impl: exports logs via OtlpTransport |
| `src/Otlp/OtlpMetricExporter.php` | MetricExporterContract impl: exports metrics via OtlpTransport |
| `src/Null/NullTracer.php` | No-op TracerContract impl |
| `src/Null/NullLogExporter.php` | No-op LogExporterContract impl |
| `src/Null/NullMetricExporter.php` | No-op MetricExporterContract impl |
| `src/Http/Middleware/StartRequestTrace.php` | Middleware: root span, RequestContext, flush on terminate |
| `src/Logging/OtlpLogHandler.php` | Monolog handler: buffers logs, flushes via OTLP |
| `src/Logging/MonitoringLogProcessor.php` | Monolog processor: enriches logs with trace context |
| `src/Otlp/ResourceAttributes.php` | Shared OTLP resource attributes builder |
| `tests/Tracing/SpanTest.php` | Tests for Span value object |
| `tests/Context/RequestContextTest.php` | Tests for RequestContext |
| `tests/Otlp/OtlpTransportTest.php` | Tests for shared transport (Http::fake) |
| `tests/Otlp/OtlpTracerTest.php` | Tests for OtlpTracer |
| `tests/Otlp/OtlpLogExporterTest.php` | Tests for log exporter |
| `tests/Otlp/OtlpMetricExporterTest.php` | Tests for metric exporter |
| `tests/Middleware/StartRequestTraceTest.php` | Tests for middleware |
| `tests/Logging/OtlpLogHandlerTest.php` | Tests for OTLP log handler |
| `tests/Logging/MonitoringLogProcessorTest.php` | Tests for log processor |
| `tests/Facades/MonitoringTest.php` | Tests for Facade span/flush methods |

### Files to Rewrite

| File | Change |
|------|--------|
| `config/monitoring.php` | Replace entirely with new OTLP config structure |
| `src/Metrics/Metric.php` | Remove cache logic, pure in-memory value object |
| `src/Metrics/Counter.php` | Remove cache logic, simple in-memory counter |
| `src/Metrics/Gauge.php` | Remove cache logic, simple in-memory gauge |
| `src/Metrics/Histogram.php` | Remove cache logic, simple in-memory histogram |
| `src/Metrics/MetricRegistry.php` | Remove cache, pure in-memory registry with reset() |
| `src/Facades/Monitoring.php` | Add span/startSpan/flush method stubs, change accessor |
| `src/helpers.php` | Update to return new facade accessor |
| `src/MonitoringServiceProvider.php` | New file (renamed from LaravelMonitoringServiceProvider) — register contracts, config, middleware, log channel |
| `composer.json` | Update description, provider reference |
| `tests/TestCase.php` | Update provider reference |
| `tests/Pest.php` | No change needed |
| `tests/ArchTest.php` | No change needed |
| `tests/Metrics/CounterTest.php` | Rewrite for in-memory (no cache setup) |
| `tests/Metrics/GaugeTest.php` | Rewrite for in-memory |
| `tests/Metrics/HistogramTest.php` | Rewrite for in-memory |
| `tests/Metrics/MetricRegistryTest.php` | Rewrite for in-memory, add flush test |
| `tests/Metrics/MetricTest.php` | Simplify for new base class |

### Files to Delete

| File | Reason |
|------|--------|
| `src/LaravelMonitoringServiceProvider.php` | Renamed to MonitoringServiceProvider |
| `src/Commands/PushMetrics.php` | No Pushgateway |
| `src/Logging/LokiHandler.php` | Replaced by OtlpLogHandler |
| `src/Http/Middleware/RecordMetrics.php` | Replaced by StartRequestTrace |
| `tests/Commands/PushMetricsTest.php` | No Pushgateway |
| `tests/Middleware/RecordMetricsTest.php` | Replaced by StartRequestTraceTest |

---

## Task 1: Clean Slate — Delete Old Files and Update Scaffolding

This task removes all files being replaced and updates the project scaffolding so subsequent tasks build on a clean foundation.

**Files:**
- Delete: `src/LaravelMonitoringServiceProvider.php`
- Delete: `src/Commands/PushMetrics.php`
- Delete: `src/Logging/LokiHandler.php`
- Delete: `src/Http/Middleware/RecordMetrics.php`
- Delete: `tests/Commands/PushMetricsTest.php`
- Delete: `tests/Middleware/RecordMetricsTest.php`
- Rewrite: `config/monitoring.php`
- Rewrite: `composer.json` (lines 3, 37-42, 67-68)
- Create: `src/MonitoringServiceProvider.php` (minimal skeleton)
- Modify: `tests/TestCase.php` (line 6, 23)

- [ ] **Step 1: Delete old files**

```bash
cd E:/packages/laravel-monitoring
rm src/LaravelMonitoringServiceProvider.php
rm src/Commands/PushMetrics.php
rm src/Logging/LokiHandler.php
rm src/Http/Middleware/RecordMetrics.php
rm tests/Commands/PushMetricsTest.php
rm tests/Middleware/RecordMetricsTest.php
```

- [ ] **Step 2: Rewrite config/monitoring.php**

Replace the entire file with the new config structure from the spec:

```php
<?php

return [
    'enabled' => env('MONITORING_ENABLED', true),

    'service' => [
        'name'        => env('MONITORING_SERVICE_NAME'),
        'environment' => env('MONITORING_ENVIRONMENT'),
        'instance_id' => env('MONITORING_SERVICE_INSTANCE_ID'),
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

Note: `service.name`, `service.environment`, `service.instance_id` default to `null` in config. The service provider resolves them at runtime to `config('app.name')`, `config('app.env')`, `config('app.url')` respectively.

- [ ] **Step 3: Create minimal MonitoringServiceProvider**

Create `src/MonitoringServiceProvider.php` — a minimal skeleton that just merges config and publishes it. We'll add bindings in later tasks as the contracts/classes are created.

```php
<?php

namespace ModusDigital\LaravelMonitoring;

use Illuminate\Support\ServiceProvider;

class MonitoringServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/monitoring.php',
            'monitoring'
        );
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/monitoring.php' => config_path('monitoring.php'),
        ], 'monitoring-config');
    }
}
```

- [ ] **Step 4: Update composer.json**

Change the description on line 3:
```
"description": "OTLP-first observability for Laravel — traces, logs, and metrics via Grafana Alloy.",
```

Change the provider on line 68:
```
"ModusDigital\\LaravelMonitoring\\MonitoringServiceProvider"
```

Remove the database factories autoload entry (line 38-39) since they're unused:
```json
"psr-4": {
    "ModusDigital\\LaravelMonitoring\\": "src/"
}
```

- [ ] **Step 5: Update tests/TestCase.php**

Rewrite the full file — update provider import and remove unused factory guesser and database config:

```php
<?php

namespace ModusDigital\LaravelMonitoring\Tests;

use ModusDigital\LaravelMonitoring\MonitoringServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app)
    {
        return [
            MonitoringServiceProvider::class,
        ];
    }
}
```

- [ ] **Step 6: Update src/Facades/Monitoring.php**

Temporarily keep it pointing at MetricRegistry (we'll update the accessor later when the full facade API is ready):

```php
<?php

namespace ModusDigital\LaravelMonitoring\Facades;

use Illuminate\Support\Facades\Facade;
use ModusDigital\LaravelMonitoring\Metrics\MetricRegistry;

/**
 * @method static \ModusDigital\LaravelMonitoring\Metrics\Counter counter(string $name, array<string, string> $labels = [])
 * @method static \ModusDigital\LaravelMonitoring\Metrics\Gauge gauge(string $name, array<string, string> $labels = [])
 * @method static \ModusDigital\LaravelMonitoring\Metrics\Histogram histogram(string $name, array<string, string> $labels = [], ?array<int> $buckets = null)
 *
 * @see MetricRegistry
 */
class Monitoring extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return MetricRegistry::class;
    }
}
```

- [ ] **Step 7: Verify tests still load**

Run: `composer analyse 2>&1 | head -20` — PHPStan will error because old references are gone, but it should at least parse.

Run: `vendor/bin/pest tests/ArchTest.php` — architecture test should still pass.

- [ ] **Step 8: Commit**

```bash
git add -A
git commit -m "refactor: clean slate — remove Pushgateway, LokiHandler, old middleware, update config"
```

---

## Task 2: Tracing Enums and Span Value Object

Build the core tracing primitives that everything else depends on.

**Files:**
- Create: `src/Tracing/SpanKind.php`
- Create: `src/Tracing/SpanStatus.php`
- Create: `src/Tracing/Span.php`
- Create: `tests/Tracing/SpanTest.php`

- [ ] **Step 1: Write SpanKind enum**

Create `src/Tracing/SpanKind.php`:

```php
<?php

namespace ModusDigital\LaravelMonitoring\Tracing;

enum SpanKind: int
{
    case INTERNAL = 1;
    case SERVER   = 2;
    case CLIENT   = 3;
    case PRODUCER = 4;
    case CONSUMER = 5;
}
```

- [ ] **Step 2: Write SpanStatus enum**

Create `src/Tracing/SpanStatus.php`:

```php
<?php

namespace ModusDigital\LaravelMonitoring\Tracing;

enum SpanStatus: int
{
    case UNSET = 0;
    case OK    = 1;
    case ERROR = 2;
}
```

- [ ] **Step 3: Write failing Span tests**

Create `tests/Tracing/SpanTest.php`:

```php
<?php

use ModusDigital\LaravelMonitoring\Tracing\Span;
use ModusDigital\LaravelMonitoring\Tracing\SpanKind;
use ModusDigital\LaravelMonitoring\Tracing\SpanStatus;

it('generates valid trace and span IDs', function () {
    $span = new Span('test.span');

    expect($span->traceId)->toMatch('/^[a-f0-9]{32}$/');
    expect($span->spanId)->toMatch('/^[a-f0-9]{16}$/');
    expect($span->parentSpanId)->toBeNull();
});

it('accepts an existing trace ID for continuation', function () {
    $traceId = str_repeat('ab', 16);
    $span = new Span('test.span', traceId: $traceId);

    expect($span->traceId)->toBe($traceId);
});

it('defaults to SpanKind::INTERNAL', function () {
    $span = new Span('test.span');

    expect($span->kind)->toBe(SpanKind::INTERNAL);
});

it('can be created with a specific SpanKind', function () {
    $span = new Span('test.span', kind: SpanKind::SERVER);

    expect($span->kind)->toBe(SpanKind::SERVER);
});

it('defaults to traceFlags 1 (sampled)', function () {
    $span = new Span('test.span');

    expect($span->traceFlags)->toBe(1);
});

it('records start time on creation', function () {
    $before = hrtime(true);
    $span = new Span('test.span');
    $after = hrtime(true);

    expect($span->startTimeNano)->toBeGreaterThanOrEqual($before);
    expect($span->startTimeNano)->toBeLessThanOrEqual($after);
});

it('can set and get attributes', function () {
    $span = new Span('test.span');
    $span->setAttribute('http.method', 'GET');
    $span->setAttribute('http.status_code', 200);

    expect($span->getAttributes())->toBe([
        'http.method' => 'GET',
        'http.status_code' => 200,
    ]);
});

it('can add events', function () {
    $span = new Span('test.span');
    $span->addEvent('exception', ['message' => 'Something broke']);

    $events = $span->getEvents();
    expect($events)->toHaveCount(1);
    expect($events[0]['name'])->toBe('exception');
    expect($events[0]['attributes'])->toBe(['message' => 'Something broke']);
    expect($events[0]['timeNano'])->toBeInt();
});

it('can set status', function () {
    $span = new Span('test.span');
    $span->setStatus(SpanStatus::ERROR);

    expect($span->getStatus())->toBe(SpanStatus::ERROR);
});

it('defaults to SpanStatus::UNSET', function () {
    $span = new Span('test.span');

    expect($span->getStatus())->toBe(SpanStatus::UNSET);
});

it('records end time when ended', function () {
    $span = new Span('test.span');
    expect($span->endTimeNano)->toBeNull();

    $span->end();

    expect($span->endTimeNano)->toBeInt();
    expect($span->endTimeNano)->toBeGreaterThanOrEqual($span->startTimeNano);
});

it('creates child spans with same trace ID', function () {
    $parent = new Span('parent');
    $child = $parent->child('child.span');

    expect($child->traceId)->toBe($parent->traceId);
    expect($child->parentSpanId)->toBe($parent->spanId);
    expect($child->spanId)->not->toBe($parent->spanId);
    expect($child->kind)->toBe(SpanKind::INTERNAL);
});

it('serializes to OTLP-compatible array', function () {
    $span = new Span('http.request', kind: SpanKind::SERVER);
    $span->setAttribute('http.method', 'GET');
    $span->setStatus(SpanStatus::OK);
    $span->end();

    $data = $span->toOtlp();

    expect($data)->toHaveKeys([
        'traceId', 'spanId', 'name', 'kind',
        'startTimeUnixNano', 'endTimeUnixNano',
        'attributes', 'status', 'flags',
    ]);
    expect($data['name'])->toBe('http.request');
    expect($data['kind'])->toBe(2); // SERVER
    expect($data['status'])->toBe(['code' => 1]); // OK
    expect($data['flags'])->toBe(1);
    expect($data['attributes'])->toContain([
        'key' => 'http.method',
        'value' => ['stringValue' => 'GET'],
    ]);
});
```

- [ ] **Step 4: Run tests to verify they fail**

Run: `vendor/bin/pest tests/Tracing/SpanTest.php`
Expected: FAIL — `Span` class does not exist yet.

- [ ] **Step 5: Implement Span**

Create `src/Tracing/Span.php`:

```php
<?php

namespace ModusDigital\LaravelMonitoring\Tracing;

class Span
{
    public readonly string $traceId;
    public readonly string $spanId;
    public readonly ?string $parentSpanId;
    public readonly int $traceFlags;
    public readonly SpanKind $kind;
    public readonly string $name;
    public readonly int $startTimeNano;
    public ?int $endTimeNano = null;

    /** @var array<string, mixed> */
    private array $attributes = [];

    /** @var list<array{name: string, attributes: array<string, mixed>, timeNano: int}> */
    private array $events = [];

    private SpanStatus $status = SpanStatus::UNSET;

    public function __construct(
        string $name,
        ?string $traceId = null,
        ?string $parentSpanId = null,
        int $traceFlags = 1,
        SpanKind $kind = SpanKind::INTERNAL,
    ) {
        $this->name = $name;
        $this->traceId = $traceId ?? bin2hex(random_bytes(16));
        $this->spanId = bin2hex(random_bytes(8));
        $this->parentSpanId = $parentSpanId;
        $this->traceFlags = $traceFlags;
        $this->kind = $kind;
        $this->startTimeNano = hrtime(true);
    }

    public function setAttribute(string $key, mixed $value): self
    {
        $this->attributes[$key] = $value;

        return $this;
    }

    /** @return array<string, mixed> */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    /** @param array<string, mixed> $attributes */
    public function addEvent(string $name, array $attributes = []): self
    {
        $this->events[] = [
            'name' => $name,
            'attributes' => $attributes,
            'timeNano' => hrtime(true),
        ];

        return $this;
    }

    /** @return list<array{name: string, attributes: array<string, mixed>, timeNano: int}> */
    public function getEvents(): array
    {
        return $this->events;
    }

    public function setStatus(SpanStatus $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getStatus(): SpanStatus
    {
        return $this->status;
    }

    public function end(): void
    {
        if ($this->endTimeNano === null) {
            $this->endTimeNano = hrtime(true);
        }
    }

    public function child(string $name, SpanKind $kind = SpanKind::INTERNAL): self
    {
        return new self(
            name: $name,
            traceId: $this->traceId,
            parentSpanId: $this->spanId,
            traceFlags: $this->traceFlags,
            kind: $kind,
        );
    }

    /** @return array<string, mixed> */
    public function toOtlp(): array
    {
        return [
            'traceId' => $this->traceId,
            'spanId' => $this->spanId,
            'parentSpanId' => $this->parentSpanId ?? '',
            'name' => $this->name,
            'kind' => $this->kind->value,
            'startTimeUnixNano' => (string) $this->startTimeNano,
            'endTimeUnixNano' => (string) ($this->endTimeNano ?? hrtime(true)),
            'attributes' => array_map(
                fn (string $key, mixed $value) => [
                    'key' => $key,
                    'value' => self::encodeAttributeValue($value),
                ],
                array_keys($this->attributes),
                array_values($this->attributes),
            ),
            'events' => array_map(
                fn (array $event) => [
                    'timeUnixNano' => (string) $event['timeNano'],
                    'name' => $event['name'],
                    'attributes' => array_map(
                        fn (string $k, mixed $v) => ['key' => $k, 'value' => self::encodeAttributeValue($v)],
                        array_keys($event['attributes']),
                        array_values($event['attributes']),
                    ),
                ],
                $this->events,
            ),
            'status' => ['code' => $this->status->value],
            'traceState' => '',
            'flags' => $this->traceFlags,
        ];
    }

    /** @return array{stringValue?: string, intValue?: string, doubleValue?: float, boolValue?: bool} */
    private static function encodeAttributeValue(mixed $value): array
    {
        return match (true) {
            is_int($value) => ['intValue' => (string) $value],
            is_float($value) => ['doubleValue' => $value],
            is_bool($value) => ['boolValue' => $value],
            default => ['stringValue' => (string) $value],
        };
    }
}
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `vendor/bin/pest tests/Tracing/SpanTest.php`
Expected: All 14 tests PASS.

- [ ] **Step 7: Run PHPStan**

Run: `composer analyse`
Expected: No errors at level 8.

- [ ] **Step 8: Commit**

```bash
git add -A
git commit -m "feat: add Span value object with SpanKind and SpanStatus enums"
```

---

## Task 3: RequestContext Singleton

**Files:**
- Create: `src/Context/RequestContext.php`
- Create: `tests/Context/RequestContextTest.php`

- [ ] **Step 1: Write failing tests**

Create `tests/Context/RequestContextTest.php`:

```php
<?php

use ModusDigital\LaravelMonitoring\Context\RequestContext;

it('stores trace and span IDs', function () {
    $ctx = new RequestContext(
        traceId: 'abc123',
        spanId: 'def456',
        requestId: 'req-001',
    );

    expect($ctx->traceId)->toBe('abc123');
    expect($ctx->spanId)->toBe('def456');
    expect($ctx->requestId)->toBe('req-001');
});

it('has nullable optional fields', function () {
    $ctx = new RequestContext(
        traceId: 'abc',
        spanId: 'def',
        requestId: 'req',
    );

    expect($ctx->route)->toBeNull();
    expect($ctx->method)->toBeNull();
    expect($ctx->userId)->toBeNull();
});

it('allows setting mutable fields', function () {
    $ctx = new RequestContext(
        traceId: 'abc',
        spanId: 'def',
        requestId: 'req',
    );

    $ctx->route = '/api/orders';
    $ctx->method = 'GET';
    $ctx->userId = 42;

    expect($ctx->route)->toBe('/api/orders');
    expect($ctx->method)->toBe('GET');
    expect($ctx->userId)->toBe(42);
});

it('can be resolved from the container', function () {
    $this->app->instance(RequestContext::class, new RequestContext(
        traceId: 'trace-1',
        spanId: 'span-1',
        requestId: 'req-1',
    ));

    $ctx = app(RequestContext::class);

    expect($ctx->traceId)->toBe('trace-1');
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/pest tests/Context/RequestContextTest.php`
Expected: FAIL — class does not exist.

- [ ] **Step 3: Implement RequestContext**

Create `src/Context/RequestContext.php`:

```php
<?php

namespace ModusDigital\LaravelMonitoring\Context;

class RequestContext
{
    public ?string $route = null;
    public ?string $method = null;
    public ?int $userId = null;

    public function __construct(
        public readonly string $traceId,
        public readonly string $spanId,
        public readonly string $requestId,
    ) {}
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/pest tests/Context/RequestContextTest.php`
Expected: All 4 tests PASS.

- [ ] **Step 5: Run PHPStan**

Run: `composer analyse`

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "feat: add RequestContext singleton for trace correlation"
```

---

## Task 4: Contracts (Tracer, LogExporter, MetricExporter)

**Files:**
- Create: `src/Contracts/TracerContract.php`
- Create: `src/Contracts/LogExporterContract.php`
- Create: `src/Contracts/MetricExporterContract.php`

- [ ] **Step 1: Create TracerContract**

```php
<?php

namespace ModusDigital\LaravelMonitoring\Contracts;

use ModusDigital\LaravelMonitoring\Tracing\Span;
use ModusDigital\LaravelMonitoring\Tracing\SpanKind;

interface TracerContract
{
    /** @param array<string, mixed> $attributes */
    public function startSpan(
        string $name,
        array $attributes = [],
        SpanKind $kind = SpanKind::INTERNAL,
        ?string $traceId = null,
        ?string $parentSpanId = null,
    ): Span;

    public function activeSpan(): ?Span;

    public function flush(): void;
}
```

- [ ] **Step 2: Create LogExporterContract**

```php
<?php

namespace ModusDigital\LaravelMonitoring\Contracts;

interface LogExporterContract
{
    /** @param list<array<string, mixed>> $logRecords */
    public function export(array $logRecords): void;
}
```

- [ ] **Step 3: Create MetricExporterContract**

```php
<?php

namespace ModusDigital\LaravelMonitoring\Contracts;

use ModusDigital\LaravelMonitoring\Metrics\Metric;

interface MetricExporterContract
{
    /** @param list<Metric> $metrics */
    public function export(array $metrics): void;
}
```

- [ ] **Step 4: Run PHPStan**

Run: `composer analyse`
Expected: No errors — interfaces have no implementation yet but should parse cleanly.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "feat: add TracerContract, LogExporterContract, MetricExporterContract interfaces"
```

---

## Task 5: OtlpTransport — Shared HTTP Client

The shared transport class all exporters use to POST JSON to Alloy.

**Files:**
- Create: `src/Otlp/OtlpTransport.php`
- Create: `tests/Otlp/OtlpTransportTest.php`

- [ ] **Step 1: Write failing tests**

Create `tests/Otlp/OtlpTransportTest.php`:

```php
<?php

use Illuminate\Support\Facades\Http;
use ModusDigital\LaravelMonitoring\Otlp\OtlpTransport;

beforeEach(function () {
    Http::fake(['*' => Http::response('', 200)]);
    config()->set('monitoring.otlp.endpoint', 'http://alloy:4318');
    config()->set('monitoring.otlp.timeout', 5);
    config()->set('monitoring.otlp.headers', null);
});

it('posts JSON to the correct endpoint', function () {
    $transport = new OtlpTransport;
    $transport->send('/v1/traces', ['resourceSpans' => []]);

    Http::assertSent(function ($request) {
        return $request->url() === 'http://alloy:4318/v1/traces'
            && $request->hasHeader('Content-Type', 'application/json');
    });
});

it('includes custom headers from config', function () {
    config()->set('monitoring.otlp.headers', 'X-Scope-OrgID=tenant1,Authorization=Basic abc');

    $transport = new OtlpTransport;
    $transport->send('/v1/logs', ['resourceLogs' => []]);

    Http::assertSent(function ($request) {
        return $request->hasHeader('X-Scope-OrgID', 'tenant1')
            && $request->hasHeader('Authorization', 'Basic abc');
    });
});

it('sends correct JSON payload', function () {
    $transport = new OtlpTransport;
    $payload = ['resourceSpans' => [['resource' => ['attributes' => []]]]];

    $transport->send('/v1/traces', $payload);

    Http::assertSent(function ($request) use ($payload) {
        return $request->data() === $payload;
    });
});

it('does not throw on transport failure', function () {
    Http::fake(['*' => Http::response('', 500)]);

    $transport = new OtlpTransport;
    $transport->send('/v1/traces', ['resourceSpans' => []]);

    // Should not throw — fire and forget
    expect(true)->toBeTrue();
});

it('does not throw on connection error', function () {
    Http::fake(function () {
        throw new \Illuminate\Http\Client\ConnectionException('Connection refused');
    });

    $transport = new OtlpTransport;
    $transport->send('/v1/traces', ['resourceSpans' => []]);

    expect(true)->toBeTrue();
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/pest tests/Otlp/OtlpTransportTest.php`
Expected: FAIL — class does not exist.

- [ ] **Step 3: Implement OtlpTransport**

Create `src/Otlp/OtlpTransport.php`:

```php
<?php

namespace ModusDigital\LaravelMonitoring\Otlp;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OtlpTransport
{
    /**
     * @param string $path OTLP endpoint path (e.g., /v1/traces)
     * @param array<string, mixed> $payload JSON payload
     */
    public function send(string $path, array $payload): void
    {
        $endpoint = rtrim((string) config('monitoring.otlp.endpoint'), '/');
        $timeout = (int) config('monitoring.otlp.timeout', 3);

        try {
            Http::timeout($timeout)
                ->withHeaders($this->parseHeaders())
                ->asJson()
                ->post($endpoint.$path, $payload);
        } catch (ConnectionException) {
            // Fire and forget — telemetry loss is acceptable
        } catch (\Throwable $e) {
            // Log transport errors but don't crash the app
            Log::debug('OTLP transport error: '.$e->getMessage());
        }
    }

    /** @return array<string, string> */
    private function parseHeaders(): array
    {
        $raw = (string) config('monitoring.otlp.headers', '');
        if ($raw === '') {
            return [];
        }

        $headers = [];
        foreach (explode(',', $raw) as $pair) {
            $parts = explode('=', $pair, 2);
            if (count($parts) === 2) {
                $headers[trim($parts[0])] = trim($parts[1]);
            }
        }

        return $headers;
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/pest tests/Otlp/OtlpTransportTest.php`
Expected: All 5 tests PASS.

- [ ] **Step 5: Run PHPStan**

Run: `composer analyse`

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "feat: add OtlpTransport — shared HTTP/JSON client for OTLP endpoints"
```

---

## Task 6: Null Implementations

Simple no-op classes for when features are disabled.

**Files:**
- Create: `src/Null/NullTracer.php`
- Create: `src/Null/NullLogExporter.php`
- Create: `src/Null/NullMetricExporter.php`

- [ ] **Step 1: Create NullTracer**

```php
<?php

namespace ModusDigital\LaravelMonitoring\Null;

use ModusDigital\LaravelMonitoring\Contracts\TracerContract;
use ModusDigital\LaravelMonitoring\Tracing\Span;
use ModusDigital\LaravelMonitoring\Tracing\SpanKind;

class NullTracer implements TracerContract
{
    /** @param array<string, mixed> $attributes */
    public function startSpan(
        string $name,
        array $attributes = [],
        SpanKind $kind = SpanKind::INTERNAL,
        ?string $traceId = null,
        ?string $parentSpanId = null,
    ): Span {
        return new Span($name, kind: $kind);
    }

    public function activeSpan(): ?Span
    {
        return null;
    }

    public function flush(): void
    {
        // no-op
    }
}
```

- [ ] **Step 2: Create NullLogExporter**

```php
<?php

namespace ModusDigital\LaravelMonitoring\Null;

use ModusDigital\LaravelMonitoring\Contracts\LogExporterContract;

class NullLogExporter implements LogExporterContract
{
    /** @param list<array<string, mixed>> $logRecords */
    public function export(array $logRecords): void
    {
        // no-op
    }
}
```

- [ ] **Step 3: Create NullMetricExporter**

```php
<?php

namespace ModusDigital\LaravelMonitoring\Null;

use ModusDigital\LaravelMonitoring\Contracts\MetricExporterContract;
use ModusDigital\LaravelMonitoring\Metrics\Metric;

class NullMetricExporter implements MetricExporterContract
{
    /** @param list<Metric> $metrics */
    public function export(array $metrics): void
    {
        // no-op
    }
}
```

- [ ] **Step 4: Run PHPStan**

Run: `composer analyse`

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "feat: add NullTracer, NullLogExporter, NullMetricExporter for disabled state"
```

---

## Task 7: Rewrite Metrics — In-Memory Counter, Gauge, Histogram

Rewrite the metric classes to be pure in-memory (no cache).

**Files:**
- Rewrite: `src/Metrics/Metric.php`
- Rewrite: `src/Metrics/Counter.php`
- Rewrite: `src/Metrics/Gauge.php`
- Rewrite: `src/Metrics/Histogram.php`
- Rewrite: `tests/Metrics/MetricTest.php`
- Rewrite: `tests/Metrics/CounterTest.php`
- Rewrite: `tests/Metrics/GaugeTest.php`
- Rewrite: `tests/Metrics/HistogramTest.php`

- [ ] **Step 1: Write failing metric tests**

Rewrite `tests/Metrics/MetricTest.php`:

```php
<?php

use ModusDigital\LaravelMonitoring\Metrics\Counter;

it('stores name and labels', function () {
    $counter = new Counter('requests_total', ['method' => 'GET']);

    expect($counter->getName())->toBe('requests_total');
    expect($counter->getLabels())->toBe(['method' => 'GET']);
});

it('sorts labels by key for consistency', function () {
    $counter = new Counter('test', ['z' => '1', 'a' => '2']);

    expect(array_keys($counter->getLabels()))->toBe(['a', 'z']);
});

it('returns its metric type', function () {
    $counter = new Counter('test');

    expect($counter->getType())->toBe('counter');
});
```

Rewrite `tests/Metrics/CounterTest.php`:

```php
<?php

use ModusDigital\LaravelMonitoring\Metrics\Counter;

it('starts at zero', function () {
    $counter = new Counter('test');

    expect($counter->getValue())->toBe(0.0);
});

it('increments by one', function () {
    $counter = new Counter('test');
    $counter->increment();

    expect($counter->getValue())->toBe(1.0);
});

it('increments by a specific amount', function () {
    $counter = new Counter('test');
    $counter->incrementBy(5.5);

    expect($counter->getValue())->toBe(5.5);
});

it('accumulates multiple increments', function () {
    $counter = new Counter('test');
    $counter->increment();
    $counter->incrementBy(2.5);
    $counter->increment();

    expect($counter->getValue())->toBe(4.5);
});

it('resets to zero', function () {
    $counter = new Counter('test');
    $counter->incrementBy(10);
    $counter->reset();

    expect($counter->getValue())->toBe(0.0);
});
```

Rewrite `tests/Metrics/GaugeTest.php`:

```php
<?php

use ModusDigital\LaravelMonitoring\Metrics\Gauge;

it('starts at zero', function () {
    $gauge = new Gauge('test');

    expect($gauge->getValue())->toBe(0.0);
});

it('sets an absolute value', function () {
    $gauge = new Gauge('test');
    $gauge->set(42.5);

    expect($gauge->getValue())->toBe(42.5);
});

it('increments by one', function () {
    $gauge = new Gauge('test');
    $gauge->set(10);
    $gauge->increment();

    expect($gauge->getValue())->toBe(11.0);
});

it('decrements by one', function () {
    $gauge = new Gauge('test');
    $gauge->set(10);
    $gauge->decrement();

    expect($gauge->getValue())->toBe(9.0);
});

it('increments by a specific amount', function () {
    $gauge = new Gauge('test');
    $gauge->incrementBy(3.5);

    expect($gauge->getValue())->toBe(3.5);
});

it('decrements by a specific amount', function () {
    $gauge = new Gauge('test');
    $gauge->set(10);
    $gauge->decrementBy(3.5);

    expect($gauge->getValue())->toBe(6.5);
});

it('resets to zero', function () {
    $gauge = new Gauge('test');
    $gauge->set(100);
    $gauge->reset();

    expect($gauge->getValue())->toBe(0.0);
});

it('returns type gauge', function () {
    $gauge = new Gauge('test');

    expect($gauge->getType())->toBe('gauge');
});
```

Rewrite `tests/Metrics/HistogramTest.php`:

```php
<?php

use ModusDigital\LaravelMonitoring\Metrics\Histogram;

it('has default buckets', function () {
    $histogram = new Histogram('test');

    expect($histogram->getBucketBoundaries())->toBe([5, 10, 25, 50, 100, 250, 500, 1000, 2500, 5000, 10000]);
});

it('accepts custom buckets', function () {
    $histogram = new Histogram('test', buckets: [10, 50, 100]);

    expect($histogram->getBucketBoundaries())->toBe([10, 50, 100]);
});

it('observes values into correct buckets', function () {
    $histogram = new Histogram('test', buckets: [10, 50, 100]);
    $histogram->observe(25);

    $buckets = $histogram->getBuckets();
    expect($buckets[10])->toBe(0);   // 25 > 10
    expect($buckets[50])->toBe(1);   // 25 <= 50
    expect($buckets[100])->toBe(1);  // 25 <= 100
    expect($buckets['+Inf'])->toBe(1);
});

it('tracks sum and count', function () {
    $histogram = new Histogram('test', buckets: [100]);
    $histogram->observe(30);
    $histogram->observe(70);

    expect($histogram->getSum())->toBe(100.0);
    expect($histogram->getCount())->toBe(2);
});

it('resets all data', function () {
    $histogram = new Histogram('test', buckets: [100]);
    $histogram->observe(50);
    $histogram->reset();

    expect($histogram->getSum())->toBe(0.0);
    expect($histogram->getCount())->toBe(0);
    expect($histogram->getBuckets()[100])->toBe(0);
});

it('returns type histogram', function () {
    $histogram = new Histogram('test');

    expect($histogram->getType())->toBe('histogram');
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/pest tests/Metrics/`
Expected: FAIL — old classes have different constructors and use cache.

- [ ] **Step 3: Rewrite Metric base class**

Rewrite `src/Metrics/Metric.php`:

```php
<?php

namespace ModusDigital\LaravelMonitoring\Metrics;

abstract class Metric
{
    protected string $name;

    /** @var array<string, string> */
    protected array $labels;

    /** @param array<string, string> $labels */
    public function __construct(string $name, array $labels = [])
    {
        $this->name = $name;
        $this->labels = $labels;
        ksort($this->labels);
    }

    public function getName(): string
    {
        return $this->name;
    }

    /** @return array<string, string> */
    public function getLabels(): array
    {
        return $this->labels;
    }

    abstract public function getType(): string;

    abstract public function reset(): void;
}
```

- [ ] **Step 4: Rewrite Counter**

Rewrite `src/Metrics/Counter.php`:

```php
<?php

namespace ModusDigital\LaravelMonitoring\Metrics;

class Counter extends Metric
{
    private float $value = 0.0;

    public function increment(): void
    {
        $this->value += 1.0;
    }

    public function incrementBy(float $value): void
    {
        $this->value += $value;
    }

    public function getValue(): float
    {
        return $this->value;
    }

    public function reset(): void
    {
        $this->value = 0.0;
    }

    public function getType(): string
    {
        return 'counter';
    }
}
```

- [ ] **Step 5: Rewrite Gauge**

Rewrite `src/Metrics/Gauge.php`:

```php
<?php

namespace ModusDigital\LaravelMonitoring\Metrics;

class Gauge extends Metric
{
    private float $value = 0.0;

    public function set(float $value): void
    {
        $this->value = $value;
    }

    public function increment(): void
    {
        $this->value += 1.0;
    }

    public function incrementBy(float $value): void
    {
        $this->value += $value;
    }

    public function decrement(): void
    {
        $this->value -= 1.0;
    }

    public function decrementBy(float $value): void
    {
        $this->value -= $value;
    }

    public function getValue(): float
    {
        return $this->value;
    }

    public function reset(): void
    {
        $this->value = 0.0;
    }

    public function getType(): string
    {
        return 'gauge';
    }
}
```

- [ ] **Step 6: Rewrite Histogram**

Rewrite `src/Metrics/Histogram.php`:

```php
<?php

namespace ModusDigital\LaravelMonitoring\Metrics;

class Histogram extends Metric
{
    private const DEFAULT_BUCKETS = [5, 10, 25, 50, 100, 250, 500, 1000, 2500, 5000, 10000];

    /** @var array<int|string, int> */
    private array $buckets = [];

    private float $sum = 0.0;

    /** @var list<int> */
    private array $boundaries;

    /**
     * @param array<string, string> $labels
     * @param list<int>|null $buckets
     */
    public function __construct(string $name, array $labels = [], ?array $buckets = null)
    {
        parent::__construct($name, $labels);
        $this->boundaries = $buckets ?? self::DEFAULT_BUCKETS;
        $this->initBuckets();
    }

    public function observe(float $value): void
    {
        $this->sum += $value;

        foreach ($this->boundaries as $bound) {
            if ($value <= $bound) {
                $this->buckets[$bound]++;
            }
        }

        $this->buckets['+Inf']++;
    }

    /** @return array<int|string, int> */
    public function getBuckets(): array
    {
        return $this->buckets;
    }

    /** @return list<int> */
    public function getBucketBoundaries(): array
    {
        return $this->boundaries;
    }

    public function getSum(): float
    {
        return $this->sum;
    }

    public function getCount(): int
    {
        return $this->buckets['+Inf'];
    }

    public function reset(): void
    {
        $this->sum = 0.0;
        $this->initBuckets();
    }

    public function getType(): string
    {
        return 'histogram';
    }

    private function initBuckets(): void
    {
        $this->buckets = [];
        foreach ($this->boundaries as $bound) {
            $this->buckets[$bound] = 0;
        }
        $this->buckets['+Inf'] = 0;
    }
}
```

- [ ] **Step 7: Run tests to verify they pass**

Run: `vendor/bin/pest tests/Metrics/`
Expected: All metric tests PASS.

- [ ] **Step 8: Run PHPStan**

Run: `composer analyse`

- [ ] **Step 9: Commit**

```bash
git add -A
git commit -m "refactor: rewrite metrics as pure in-memory objects (no cache)"
```

---

## Task 8: MetricRegistry Rewrite and OTLP Metric Exporter

**Files:**
- Rewrite: `src/Metrics/MetricRegistry.php`
- Create: `src/Otlp/OtlpMetricExporter.php`
- Rewrite: `tests/Metrics/MetricRegistryTest.php`
- Create: `tests/Otlp/OtlpMetricExporterTest.php`

- [ ] **Step 1: Write failing MetricRegistry tests**

Rewrite `tests/Metrics/MetricRegistryTest.php`:

```php
<?php

use ModusDigital\LaravelMonitoring\Metrics\Counter;
use ModusDigital\LaravelMonitoring\Metrics\Gauge;
use ModusDigital\LaravelMonitoring\Metrics\Histogram;
use ModusDigital\LaravelMonitoring\Metrics\MetricRegistry;

it('creates a counter', function () {
    $registry = new MetricRegistry;
    $counter = $registry->counter('test_counter');

    expect($counter)->toBeInstanceOf(Counter::class);
    expect($counter->getName())->toBe('test_counter');
});

it('creates a gauge', function () {
    $registry = new MetricRegistry;
    $gauge = $registry->gauge('test_gauge');

    expect($gauge)->toBeInstanceOf(Gauge::class);
});

it('creates a histogram', function () {
    $registry = new MetricRegistry;
    $histogram = $registry->histogram('test_histogram');

    expect($histogram)->toBeInstanceOf(Histogram::class);
});

it('returns same instance for same name and labels', function () {
    $registry = new MetricRegistry;
    $a = $registry->counter('test', ['method' => 'GET']);
    $b = $registry->counter('test', ['method' => 'GET']);

    expect($a)->toBe($b);
});

it('returns different instances for different labels', function () {
    $registry = new MetricRegistry;
    $a = $registry->counter('test', ['method' => 'GET']);
    $b = $registry->counter('test', ['method' => 'POST']);

    expect($a)->not->toBe($b);
});

it('returns all registered metrics', function () {
    $registry = new MetricRegistry;
    $registry->counter('counter_a');
    $registry->gauge('gauge_a');
    $registry->histogram('hist_a');

    $all = $registry->all();

    expect($all)->toHaveCount(3);
});

it('resets all metrics', function () {
    $registry = new MetricRegistry;
    $counter = $registry->counter('test');
    $counter->incrementBy(10);

    $registry->reset();

    expect($counter->getValue())->toBe(0.0);
});

it('resolves via the monitoring() helper', function () {
    $registry = app(MetricRegistry::class);

    expect(monitoring())->toBe($registry);
});
```

- [ ] **Step 2: Write failing OtlpMetricExporter tests**

Create `tests/Otlp/OtlpMetricExporterTest.php`:

```php
<?php

use Illuminate\Support\Facades\Http;
use ModusDigital\LaravelMonitoring\Metrics\Counter;
use ModusDigital\LaravelMonitoring\Metrics\Gauge;
use ModusDigital\LaravelMonitoring\Metrics\Histogram;
use ModusDigital\LaravelMonitoring\Otlp\OtlpMetricExporter;
use ModusDigital\LaravelMonitoring\Otlp\OtlpTransport;

beforeEach(function () {
    Http::fake(['*' => Http::response('', 200)]);
    config()->set('monitoring.otlp.endpoint', 'http://alloy:4318');
    config()->set('monitoring.service.name', 'test-app');
    config()->set('monitoring.service.environment', 'testing');
    config()->set('monitoring.service.instance_id', 'http://localhost');
});

it('exports counter as OTLP sum metric', function () {
    $counter = new Counter('orders_total', ['status' => 'ok']);
    $counter->incrementBy(5);

    $exporter = new OtlpMetricExporter(new OtlpTransport);
    $exporter->export([$counter]);

    Http::assertSent(function ($request) {
        $body = $request->data();
        $metric = $body['resourceMetrics'][0]['scopeMetrics'][0]['metrics'][0];

        return $request->url() === 'http://alloy:4318/v1/metrics'
            && $metric['name'] === 'orders_total'
            && isset($metric['sum'])
            && $metric['sum']['aggregationTemporality'] === 1 // DELTA
            && $metric['sum']['isMonotonic'] === true;
    });
});

it('exports gauge as OTLP gauge metric', function () {
    $gauge = new Gauge('queue_depth', ['queue' => 'default']);
    $gauge->set(42);

    $exporter = new OtlpMetricExporter(new OtlpTransport);
    $exporter->export([$gauge]);

    Http::assertSent(function ($request) {
        $metric = $request->data()['resourceMetrics'][0]['scopeMetrics'][0]['metrics'][0];

        return isset($metric['gauge'])
            && $metric['name'] === 'queue_depth';
    });
});

it('exports histogram as OTLP histogram metric', function () {
    $histogram = new Histogram('duration_ms', buckets: [100, 500]);
    $histogram->observe(250);

    $exporter = new OtlpMetricExporter(new OtlpTransport);
    $exporter->export([$histogram]);

    Http::assertSent(function ($request) {
        $metric = $request->data()['resourceMetrics'][0]['scopeMetrics'][0]['metrics'][0];

        return isset($metric['histogram'])
            && $metric['histogram']['aggregationTemporality'] === 1
            && $metric['histogram']['dataPoints'][0]['count'] === '1';
    });
});

it('includes resource attributes', function () {
    $counter = new Counter('test');
    $counter->increment();

    $exporter = new OtlpMetricExporter(new OtlpTransport);
    $exporter->export([$counter]);

    Http::assertSent(function ($request) {
        $resource = $request->data()['resourceMetrics'][0]['resource'];
        $attrs = collect($resource['attributes'])->pluck('value.stringValue', 'key');

        return $attrs['service.name'] === 'test-app'
            && $attrs['deployment.environment'] === 'testing';
    });
});

it('does not send when metrics array is empty', function () {
    $exporter = new OtlpMetricExporter(new OtlpTransport);
    $exporter->export([]);

    Http::assertNothingSent();
});
```

- [ ] **Step 3: Run tests to verify they fail**

Run: `vendor/bin/pest tests/Metrics/MetricRegistryTest.php tests/Otlp/OtlpMetricExporterTest.php`
Expected: FAIL.

- [ ] **Step 4: Rewrite MetricRegistry**

Rewrite `src/Metrics/MetricRegistry.php`:

```php
<?php

namespace ModusDigital\LaravelMonitoring\Metrics;

class MetricRegistry
{
    /** @var array<string, Metric> */
    private array $metrics = [];

    /** @param array<string, string> $labels */
    public function counter(string $name, array $labels = []): Counter
    {
        $key = $this->key('counter', $name, $labels);

        if (! isset($this->metrics[$key])) {
            $this->metrics[$key] = new Counter($name, $labels);
        }

        /** @var Counter */
        return $this->metrics[$key];
    }

    /** @param array<string, string> $labels */
    public function gauge(string $name, array $labels = []): Gauge
    {
        $key = $this->key('gauge', $name, $labels);

        if (! isset($this->metrics[$key])) {
            $this->metrics[$key] = new Gauge($name, $labels);
        }

        /** @var Gauge */
        return $this->metrics[$key];
    }

    /**
     * @param array<string, string> $labels
     * @param list<int>|null $buckets
     */
    public function histogram(string $name, array $labels = [], ?array $buckets = null): Histogram
    {
        $key = $this->key('histogram', $name, $labels);

        if (! isset($this->metrics[$key])) {
            $this->metrics[$key] = new Histogram($name, $labels, $buckets);
        }

        /** @var Histogram */
        return $this->metrics[$key];
    }

    /** @return list<Metric> */
    public function all(): array
    {
        return array_values($this->metrics);
    }

    public function reset(): void
    {
        foreach ($this->metrics as $metric) {
            $metric->reset();
        }
    }

    /** @param array<string, string> $labels */
    private function key(string $type, string $name, array $labels): string
    {
        ksort($labels);

        return $type.':'.$name.':'.md5(serialize($labels));
    }
}
```

- [ ] **Step 5: Implement OtlpMetricExporter**

Create `src/Otlp/OtlpMetricExporter.php`:

```php
<?php

namespace ModusDigital\LaravelMonitoring\Otlp;

use ModusDigital\LaravelMonitoring\Contracts\MetricExporterContract;
use ModusDigital\LaravelMonitoring\Metrics\Counter;
use ModusDigital\LaravelMonitoring\Metrics\Gauge;
use ModusDigital\LaravelMonitoring\Metrics\Histogram;
use ModusDigital\LaravelMonitoring\Metrics\Metric;

class OtlpMetricExporter implements MetricExporterContract
{
    public function __construct(
        private readonly OtlpTransport $transport,
    ) {}

    /** @param list<Metric> $metrics */
    public function export(array $metrics): void
    {
        if ($metrics === []) {
            return;
        }

        $now = (string) hrtime(true);

        $this->transport->send('/v1/metrics', [
            'resourceMetrics' => [
                [
                    'resource' => ResourceAttributes::build(),
                    'scopeMetrics' => [
                        [
                            'scope' => ['name' => 'laravel-monitoring'],
                            'metrics' => array_map(
                                fn (Metric $metric) => $this->formatMetric($metric, $now),
                                $metrics,
                            ),
                        ],
                    ],
                ],
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function formatMetric(Metric $metric, string $now): array
    {
        return match (true) {
            $metric instanceof Counter => $this->formatCounter($metric, $now),
            $metric instanceof Gauge => $this->formatGauge($metric, $now),
            $metric instanceof Histogram => $this->formatHistogram($metric, $now),
            default => throw new \InvalidArgumentException('Unknown metric type: '.get_class($metric)),
        };
    }

    /** @return array<string, mixed> */
    private function formatCounter(Counter $counter, string $now): array
    {
        return [
            'name' => $counter->getName(),
            'sum' => [
                'dataPoints' => [
                    [
                        'startTimeUnixNano' => $now,
                        'timeUnixNano' => $now,
                        'asDouble' => $counter->getValue(),
                        'attributes' => self::formatLabels($counter->getLabels()),
                    ],
                ],
                'aggregationTemporality' => 1, // DELTA
                'isMonotonic' => true,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function formatGauge(Gauge $gauge, string $now): array
    {
        return [
            'name' => $gauge->getName(),
            'gauge' => [
                'dataPoints' => [
                    [
                        'timeUnixNano' => $now,
                        'asDouble' => $gauge->getValue(),
                        'attributes' => self::formatLabels($gauge->getLabels()),
                    ],
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function formatHistogram(Histogram $histogram, string $now): array
    {
        $bucketCounts = [];
        foreach ($histogram->getBucketBoundaries() as $bound) {
            $bucketCounts[] = (string) $histogram->getBuckets()[$bound];
        }
        $bucketCounts[] = (string) $histogram->getBuckets()['+Inf'];

        return [
            'name' => $histogram->getName(),
            'histogram' => [
                'dataPoints' => [
                    [
                        'startTimeUnixNano' => $now,
                        'timeUnixNano' => $now,
                        'count' => (string) $histogram->getCount(),
                        'sum' => $histogram->getSum(),
                        'bucketCounts' => $bucketCounts,
                        'explicitBounds' => $histogram->getBucketBoundaries(),
                        'attributes' => self::formatLabels($histogram->getLabels()),
                    ],
                ],
                'aggregationTemporality' => 1, // DELTA
            ],
        ];
    }

    /**
     * @param array<string, string> $labels
     * @return list<array{key: string, value: array{stringValue: string}}>
     */
    private static function formatLabels(array $labels): array
    {
        return array_map(
            fn (string $key, string $value) => [
                'key' => $key,
                'value' => ['stringValue' => $value],
            ],
            array_keys($labels),
            array_values($labels),
        );
    }
}
```

- [ ] **Step 6: Create ResourceAttributes helper**

This helper is shared across all three exporters. Create `src/Otlp/ResourceAttributes.php`:

```php
<?php

namespace ModusDigital\LaravelMonitoring\Otlp;

class ResourceAttributes
{
    /** @return array{attributes: list<array{key: string, value: array{stringValue: string}}>} */
    public static function build(): array
    {
        return [
            'attributes' => [
                [
                    'key' => 'service.name',
                    'value' => ['stringValue' => (string) (config('monitoring.service.name') ?? config('app.name', 'laravel'))],
                ],
                [
                    'key' => 'deployment.environment',
                    'value' => ['stringValue' => (string) (config('monitoring.service.environment') ?? config('app.env', 'production'))],
                ],
                [
                    'key' => 'service.instance.id',
                    'value' => ['stringValue' => (string) (config('monitoring.service.instance_id') ?? config('app.url', 'http://localhost'))],
                ],
            ],
        ];
    }
}
```

- [ ] **Step 7: Update MonitoringServiceProvider to register MetricRegistry singleton**

Add to the `register()` method:

```php
$this->app->singleton(MetricRegistry::class);
```

Add the import:
```php
use ModusDigital\LaravelMonitoring\Metrics\MetricRegistry;
```

- [ ] **Step 8: Run tests to verify they pass**

Run: `vendor/bin/pest tests/Metrics/ tests/Otlp/OtlpMetricExporterTest.php`
Expected: All tests PASS.

- [ ] **Step 9: Run PHPStan and format**

Run: `composer analyse && composer format`

- [ ] **Step 10: Commit**

```bash
git add -A
git commit -m "feat: rewrite MetricRegistry (in-memory) and add OtlpMetricExporter"
```

---

## Task 9: OtlpTracer — Span Management and Export

**Files:**
- Create: `src/Otlp/OtlpTracer.php`
- Create: `tests/Otlp/OtlpTracerTest.php`

- [ ] **Step 1: Write failing tests**

Create `tests/Otlp/OtlpTracerTest.php`:

```php
<?php

use Illuminate\Support\Facades\Http;
use ModusDigital\LaravelMonitoring\Otlp\OtlpTracer;
use ModusDigital\LaravelMonitoring\Otlp\OtlpTransport;
use ModusDigital\LaravelMonitoring\Tracing\SpanKind;
use ModusDigital\LaravelMonitoring\Tracing\SpanStatus;

beforeEach(function () {
    Http::fake(['*' => Http::response('', 200)]);
    config()->set('monitoring.otlp.endpoint', 'http://alloy:4318');
    config()->set('monitoring.service.name', 'test-app');
    config()->set('monitoring.service.environment', 'testing');
    config()->set('monitoring.service.instance_id', 'http://localhost');
});

it('starts a span and tracks it as active', function () {
    $tracer = new OtlpTracer(new OtlpTransport);
    $span = $tracer->startSpan('test.operation');

    expect($tracer->activeSpan())->toBe($span);
});

it('starts a span with specific kind', function () {
    $tracer = new OtlpTracer(new OtlpTransport);
    $span = $tracer->startSpan('http.request', kind: SpanKind::SERVER);

    expect($span->kind)->toBe(SpanKind::SERVER);
});

it('creates child span under active span', function () {
    $tracer = new OtlpTracer(new OtlpTransport);
    $parent = $tracer->startSpan('parent');
    $child = $tracer->startSpan('child');

    expect($child->traceId)->toBe($parent->traceId);
    expect($child->parentSpanId)->toBe($parent->spanId);
});

it('restores parent as active after child ends', function () {
    $tracer = new OtlpTracer(new OtlpTransport);
    $parent = $tracer->startSpan('parent');
    $child = $tracer->startSpan('child');
    $child->end();

    expect($tracer->activeSpan())->toBe($parent);
});

it('flushes all ended spans via OTLP', function () {
    $tracer = new OtlpTracer(new OtlpTransport);
    $span = $tracer->startSpan('test');
    $span->end();
    $tracer->flush();

    Http::assertSent(function ($request) {
        return $request->url() === 'http://alloy:4318/v1/traces'
            && isset($request->data()['resourceSpans']);
    });
});

it('does not send when no spans to flush', function () {
    $tracer = new OtlpTracer(new OtlpTransport);
    $tracer->flush();

    Http::assertNothingSent();
});

it('includes resource attributes in flush', function () {
    $tracer = new OtlpTracer(new OtlpTransport);
    $span = $tracer->startSpan('test');
    $span->end();
    $tracer->flush();

    Http::assertSent(function ($request) {
        $resource = $request->data()['resourceSpans'][0]['resource'];
        $attrs = collect($resource['attributes'])->pluck('value.stringValue', 'key');

        return $attrs['service.name'] === 'test-app';
    });
});

it('can continue an existing trace via trace ID', function () {
    $tracer = new OtlpTracer(new OtlpTransport);
    $existingTraceId = str_repeat('ab', 16);
    $span = $tracer->startSpan('continued', traceId: $existingTraceId);

    expect($span->traceId)->toBe($existingTraceId);
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/pest tests/Otlp/OtlpTracerTest.php`
Expected: FAIL.

- [ ] **Step 3: Implement OtlpTracer**

Create `src/Otlp/OtlpTracer.php`:

```php
<?php

namespace ModusDigital\LaravelMonitoring\Otlp;

use ModusDigital\LaravelMonitoring\Contracts\TracerContract;
use ModusDigital\LaravelMonitoring\Tracing\Span;
use ModusDigital\LaravelMonitoring\Tracing\SpanKind;

class OtlpTracer implements TracerContract
{
    /** @var list<Span> */
    private array $spans = [];

    /** @var list<Span> */
    private array $spanStack = [];

    public function __construct(
        private readonly OtlpTransport $transport,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function startSpan(
        string $name,
        array $attributes = [],
        SpanKind $kind = SpanKind::INTERNAL,
        ?string $traceId = null,
        ?string $parentSpanId = null,
    ): Span {
        $activeSpan = $this->activeSpan();

        $span = new Span(
            name: $name,
            traceId: $traceId ?? $activeSpan?->traceId,
            parentSpanId: $parentSpanId ?? $activeSpan?->spanId,
            kind: $kind,
        );

        foreach ($attributes as $key => $value) {
            $span->setAttribute($key, $value);
        }

        $this->spanStack[] = $span;

        return $span;
    }

    public function activeSpan(): ?Span
    {
        // Return last unended span in the stack
        for ($i = count($this->spanStack) - 1; $i >= 0; $i--) {
            if ($this->spanStack[$i]->endTimeNano === null) {
                return $this->spanStack[$i];
            }
        }

        return null;
    }

    public function flush(): void
    {
        // Collect all spans (ended or not — force-end any still open)
        $spansToExport = [];
        foreach ($this->spanStack as $span) {
            $span->end();
            $spansToExport[] = $span->toOtlp();
        }

        if ($spansToExport === []) {
            return;
        }

        $this->transport->send('/v1/traces', [
            'resourceSpans' => [
                [
                    'resource' => ResourceAttributes::build(),
                    'scopeSpans' => [
                        [
                            'scope' => ['name' => 'laravel-monitoring'],
                            'spans' => $spansToExport,
                        ],
                    ],
                ],
            ],
        ]);

        $this->spanStack = [];
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/pest tests/Otlp/OtlpTracerTest.php`
Expected: All 8 tests PASS.

- [ ] **Step 5: Run PHPStan**

Run: `composer analyse`

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "feat: add OtlpTracer — span management with OTLP export"
```

---

## Task 10: OTLP Log Exporter and Monolog Integration

**Files:**
- Create: `src/Otlp/OtlpLogExporter.php`
- Create: `src/Logging/OtlpLogHandler.php`
- Create: `src/Logging/MonitoringLogProcessor.php`
- Create: `tests/Otlp/OtlpLogExporterTest.php`
- Create: `tests/Logging/OtlpLogHandlerTest.php`
- Create: `tests/Logging/MonitoringLogProcessorTest.php`

- [ ] **Step 1: Write failing MonitoringLogProcessor tests**

Create `tests/Logging/MonitoringLogProcessorTest.php`:

```php
<?php

use Monolog\LogRecord;
use Monolog\Level;
use ModusDigital\LaravelMonitoring\Context\RequestContext;
use ModusDigital\LaravelMonitoring\Logging\MonitoringLogProcessor;

it('enriches log record with trace context', function () {
    $ctx = new RequestContext(
        traceId: 'trace-abc',
        spanId: 'span-def',
        requestId: 'req-123',
    );
    $ctx->route = '/api/orders';
    $ctx->method = 'GET';
    $ctx->userId = 42;

    $this->app->instance(RequestContext::class, $ctx);

    $processor = new MonitoringLogProcessor;
    $record = new LogRecord(
        datetime: new \DateTimeImmutable,
        channel: 'test',
        level: Level::Info,
        message: 'Test message',
    );

    $result = $processor($record);

    expect($result->extra['trace_id'])->toBe('trace-abc');
    expect($result->extra['span_id'])->toBe('span-def');
    expect($result->extra['request_id'])->toBe('req-123');
    expect($result->extra['route'])->toBe('/api/orders');
    expect($result->extra['method'])->toBe('GET');
    expect($result->extra['user_id'])->toBe(42);
});

it('handles missing RequestContext gracefully', function () {
    $processor = new MonitoringLogProcessor;
    $record = new LogRecord(
        datetime: new \DateTimeImmutable,
        channel: 'test',
        level: Level::Info,
        message: 'Test message',
    );

    $result = $processor($record);

    expect($result->extra)->not->toHaveKey('trace_id');
});
```

- [ ] **Step 2: Write failing OtlpLogExporter tests**

Create `tests/Otlp/OtlpLogExporterTest.php`:

```php
<?php

use Illuminate\Support\Facades\Http;
use ModusDigital\LaravelMonitoring\Otlp\OtlpLogExporter;
use ModusDigital\LaravelMonitoring\Otlp\OtlpTransport;

beforeEach(function () {
    Http::fake(['*' => Http::response('', 200)]);
    config()->set('monitoring.otlp.endpoint', 'http://alloy:4318');
    config()->set('monitoring.service.name', 'test-app');
    config()->set('monitoring.service.environment', 'testing');
    config()->set('monitoring.service.instance_id', 'http://localhost');
});

it('exports log records via OTLP', function () {
    $exporter = new OtlpLogExporter(new OtlpTransport);
    $exporter->export([
        [
            'timeUnixNano' => '1712150400000000000',
            'severityNumber' => 9,
            'severityText' => 'INFO',
            'body' => 'Test log message',
            'attributes' => ['trace_id' => 'abc123'],
            'traceId' => 'abc123',
            'spanId' => 'def456',
        ],
    ]);

    Http::assertSent(function ($request) {
        return $request->url() === 'http://alloy:4318/v1/logs'
            && isset($request->data()['resourceLogs']);
    });
});

it('includes resource attributes', function () {
    $exporter = new OtlpLogExporter(new OtlpTransport);
    $exporter->export([
        [
            'timeUnixNano' => '1712150400000000000',
            'severityNumber' => 9,
            'severityText' => 'INFO',
            'body' => 'Test',
            'attributes' => [],
        ],
    ]);

    Http::assertSent(function ($request) {
        $resource = $request->data()['resourceLogs'][0]['resource'];
        $attrs = collect($resource['attributes'])->pluck('value.stringValue', 'key');

        return $attrs['service.name'] === 'test-app';
    });
});

it('does not send when no log records', function () {
    $exporter = new OtlpLogExporter(new OtlpTransport);
    $exporter->export([]);

    Http::assertNothingSent();
});
```

- [ ] **Step 3: Write failing OtlpLogHandler test**

Create `tests/Logging/OtlpLogHandlerTest.php`:

```php
<?php

use Illuminate\Support\Facades\Http;
use Monolog\Level;
use Monolog\LogRecord;
use ModusDigital\LaravelMonitoring\Logging\OtlpLogHandler;
use ModusDigital\LaravelMonitoring\Otlp\OtlpLogExporter;
use ModusDigital\LaravelMonitoring\Otlp\OtlpTransport;

beforeEach(function () {
    Http::fake(['*' => Http::response('', 200)]);
    config()->set('monitoring.otlp.endpoint', 'http://alloy:4318');
    config()->set('monitoring.service.name', 'test-app');
    config()->set('monitoring.service.environment', 'testing');
    config()->set('monitoring.service.instance_id', 'http://localhost');
});

it('buffers log records and flushes on close', function () {
    $handler = new OtlpLogHandler(new OtlpLogExporter(new OtlpTransport));

    $record = new LogRecord(
        datetime: new \DateTimeImmutable,
        channel: 'test',
        level: Level::Info,
        message: 'Hello world',
        context: ['key' => 'value'],
        extra: ['trace_id' => 'abc'],
    );

    $handler->handle($record);
    Http::assertNothingSent(); // Buffered, not sent yet

    $handler->close();

    Http::assertSent(function ($request) {
        $logRecord = $request->data()['resourceLogs'][0]['scopeLogs'][0]['logRecords'][0];

        return $logRecord['body']['stringValue'] === 'Hello world'
            && $logRecord['severityText'] === 'INFO';
    });
});

it('maps Monolog levels to OTLP severity numbers', function () {
    $handler = new OtlpLogHandler(new OtlpLogExporter(new OtlpTransport));

    $record = new LogRecord(
        datetime: new \DateTimeImmutable,
        channel: 'test',
        level: Level::Error,
        message: 'Something failed',
    );

    $handler->handle($record);
    $handler->close();

    Http::assertSent(function ($request) {
        $logRecord = $request->data()['resourceLogs'][0]['scopeLogs'][0]['logRecords'][0];

        return $logRecord['severityNumber'] === 17 // ERROR
            && $logRecord['severityText'] === 'ERROR';
    });
});
```

- [ ] **Step 4: Run tests to verify they fail**

Run: `vendor/bin/pest tests/Logging/ tests/Otlp/OtlpLogExporterTest.php`
Expected: FAIL.

- [ ] **Step 5: Implement MonitoringLogProcessor**

Create `src/Logging/MonitoringLogProcessor.php`:

```php
<?php

namespace ModusDigital\LaravelMonitoring\Logging;

use ModusDigital\LaravelMonitoring\Context\RequestContext;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

class MonitoringLogProcessor implements ProcessorInterface
{
    public function __invoke(LogRecord $record): LogRecord
    {
        $ctx = $this->resolveContext();

        if ($ctx === null) {
            return $record;
        }

        return $record->with(extra: array_merge($record->extra, array_filter([
            'trace_id' => $ctx->traceId,
            'span_id' => $ctx->spanId,
            'request_id' => $ctx->requestId,
            'route' => $ctx->route,
            'method' => $ctx->method,
            'user_id' => $ctx->userId,
        ], fn (mixed $v) => $v !== null)));
    }

    private function resolveContext(): ?RequestContext
    {
        try {
            return app(RequestContext::class);
        } catch (\Throwable) {
            return null;
        }
    }
}
```

- [ ] **Step 6: Implement OtlpLogExporter**

Create `src/Otlp/OtlpLogExporter.php`:

```php
<?php

namespace ModusDigital\LaravelMonitoring\Otlp;

use ModusDigital\LaravelMonitoring\Contracts\LogExporterContract;

class OtlpLogExporter implements LogExporterContract
{
    public function __construct(
        private readonly OtlpTransport $transport,
    ) {}

    /** @param list<array<string, mixed>> $logRecords */
    public function export(array $logRecords): void
    {
        if ($logRecords === []) {
            return;
        }

        $this->transport->send('/v1/logs', [
            'resourceLogs' => [
                [
                    'resource' => ResourceAttributes::build(),
                    'scopeLogs' => [
                        [
                            'scope' => ['name' => 'laravel-monitoring'],
                            'logRecords' => array_map(
                                fn (array $record) => $this->formatLogRecord($record),
                                $logRecords,
                            ),
                        ],
                    ],
                ],
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    private function formatLogRecord(array $record): array
    {
        $formatted = [
            'timeUnixNano' => $record['timeUnixNano'] ?? (string) hrtime(true),
            'severityNumber' => $record['severityNumber'] ?? 9,
            'severityText' => $record['severityText'] ?? 'INFO',
            'body' => ['stringValue' => (string) ($record['body'] ?? '')],
            'attributes' => $this->formatAttributes($record['attributes'] ?? []),
        ];

        if (isset($record['traceId'])) {
            $formatted['traceId'] = $record['traceId'];
        }

        if (isset($record['spanId'])) {
            $formatted['spanId'] = $record['spanId'];
        }

        return $formatted;
    }

    /**
     * @param array<string, mixed> $attributes
     * @return list<array{key: string, value: array<string, mixed>}>
     */
    private function formatAttributes(array $attributes): array
    {
        $result = [];
        foreach ($attributes as $key => $value) {
            $result[] = [
                'key' => (string) $key,
                'value' => match (true) {
                    is_int($value) => ['intValue' => (string) $value],
                    is_float($value) => ['doubleValue' => $value],
                    is_bool($value) => ['boolValue' => $value],
                    default => ['stringValue' => (string) $value],
                },
            ];
        }

        return $result;
    }
}
```

- [ ] **Step 7: Implement OtlpLogHandler**

Create `src/Logging/OtlpLogHandler.php`:

```php
<?php

namespace ModusDigital\LaravelMonitoring\Logging;

use ModusDigital\LaravelMonitoring\Contracts\LogExporterContract;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;

class OtlpLogHandler extends AbstractProcessingHandler
{
    /** @var list<array<string, mixed>> */
    private array $buffer = [];

    private const SEVERITY_MAP = [
        Level::Debug->value     => 5,
        Level::Info->value      => 9,
        Level::Notice->value    => 13,
        Level::Warning->value   => 13,
        Level::Error->value     => 17,
        Level::Critical->value  => 21,
        Level::Alert->value     => 21,
        Level::Emergency->value => 24,
    ];

    public function __construct(
        private readonly LogExporterContract $exporter,
        Level $level = Level::Debug,
        bool $bubble = true,
    ) {
        parent::__construct($level, $bubble);
    }

    protected function write(LogRecord $record): void
    {
        $extra = $record->extra;

        $logRecord = [
            'timeUnixNano' => (string) ($record->datetime->format('U') * 1_000_000_000 + (int) $record->datetime->format('u') * 1_000),
            'severityNumber' => self::SEVERITY_MAP[$record->level->value] ?? 9,
            'severityText' => $record->level->name,
            'body' => $record->message,
            'attributes' => array_merge(
                $record->context,
                $extra,
            ),
        ];

        if (isset($extra['trace_id'])) {
            $logRecord['traceId'] = $extra['trace_id'];
        }

        if (isset($extra['span_id'])) {
            $logRecord['spanId'] = $extra['span_id'];
        }

        $this->buffer[] = $logRecord;
    }

    public function close(): void
    {
        if ($this->buffer !== []) {
            $this->exporter->export($this->buffer);
            $this->buffer = [];
        }

        parent::close();
    }

    public function flush(): void
    {
        if ($this->buffer !== []) {
            $this->exporter->export($this->buffer);
            $this->buffer = [];
        }
    }
}
```

- [ ] **Step 8: Run tests to verify they pass**

Run: `vendor/bin/pest tests/Logging/ tests/Otlp/OtlpLogExporterTest.php`
Expected: All tests PASS.

- [ ] **Step 9: Run PHPStan**

Run: `composer analyse`

- [ ] **Step 10: Commit**

```bash
git add -A
git commit -m "feat: add OTLP log export — OtlpLogHandler, MonitoringLogProcessor, OtlpLogExporter"
```

---

## Task 11: StartRequestTrace Middleware

**Files:**
- Create: `src/Http/Middleware/StartRequestTrace.php`
- Create: `tests/Middleware/StartRequestTraceTest.php`

- [ ] **Step 1: Write failing tests**

Create `tests/Middleware/StartRequestTraceTest.php`:

```php
<?php

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use ModusDigital\LaravelMonitoring\Context\RequestContext;
use ModusDigital\LaravelMonitoring\Contracts\TracerContract;
use ModusDigital\LaravelMonitoring\Http\Middleware\StartRequestTrace;
use ModusDigital\LaravelMonitoring\Otlp\OtlpTracer;
use ModusDigital\LaravelMonitoring\Otlp\OtlpTransport;
use ModusDigital\LaravelMonitoring\Tracing\SpanKind;

beforeEach(function () {
    Http::fake(['*' => Http::response('', 200)]);
    config()->set('monitoring.enabled', true);
    config()->set('monitoring.traces.enabled', true);
    config()->set('monitoring.traces.sample_rate', 1.0);
    config()->set('monitoring.otlp.endpoint', 'http://alloy:4318');
    config()->set('monitoring.service.name', 'test-app');
    config()->set('monitoring.service.environment', 'testing');
    config()->set('monitoring.service.instance_id', 'http://localhost');
    config()->set('monitoring.middleware.exclude', []);
});

it('creates a root span with SERVER kind', function () {
    $tracer = new OtlpTracer(new OtlpTransport);
    $this->app->instance(TracerContract::class, $tracer);

    $middleware = new StartRequestTrace($tracer);
    $request = Request::create('/api/orders', 'GET');

    $middleware->handle($request, function () {
        return new Response('OK', 200);
    });

    $span = $tracer->activeSpan();
    expect($span)->not->toBeNull();
    expect($span->kind)->toBe(SpanKind::SERVER);
    expect($span->name)->toBe('http.request');
});

it('populates RequestContext', function () {
    $tracer = new OtlpTracer(new OtlpTransport);
    $this->app->instance(TracerContract::class, $tracer);

    $middleware = new StartRequestTrace($tracer);
    $request = Request::create('/api/orders', 'POST');

    $middleware->handle($request, function () {
        return new Response('OK', 200);
    });

    $ctx = app(RequestContext::class);
    expect($ctx)->toBeInstanceOf(RequestContext::class);
    expect($ctx->method)->toBe('POST');
});

it('sets response attributes on terminate', function () {
    $tracer = new OtlpTracer(new OtlpTransport);
    $this->app->instance(TracerContract::class, $tracer);

    $middleware = new StartRequestTrace($tracer);
    $request = Request::create('/api/orders', 'GET');
    $response = new Response('Not Found', 404);

    $middleware->handle($request, fn () => $response);
    $middleware->terminate($request, $response);

    // Span should have been flushed
    Http::assertSent(function ($request) {
        $span = $request->data()['resourceSpans'][0]['scopeSpans'][0]['spans'][0] ?? null;
        if (! $span) {
            return false;
        }

        $attrs = collect($span['attributes'])->pluck('value', 'key');

        return ($attrs['http.status_code']['intValue'] ?? null) === '404'
            && ($attrs['http.status_group']['stringValue'] ?? null) === '4xx';
    });
});

it('continues trace from incoming traceparent header', function () {
    $tracer = new OtlpTracer(new OtlpTransport);
    $this->app->instance(TracerContract::class, $tracer);

    $middleware = new StartRequestTrace($tracer);
    $traceId = str_repeat('ab', 16);
    $parentSpanId = str_repeat('cd', 8);
    $request = Request::create('/api/orders', 'GET');
    $request->headers->set('traceparent', "00-{$traceId}-{$parentSpanId}-01");

    $middleware->handle($request, fn () => new Response('OK', 200));

    $span = $tracer->activeSpan();
    expect($span->traceId)->toBe($traceId);
    expect($span->parentSpanId)->toBe($parentSpanId);
});

it('skips excluded routes', function () {
    config()->set('monitoring.middleware.exclude', ['health']);

    $tracer = new OtlpTracer(new OtlpTransport);
    $this->app->instance(TracerContract::class, $tracer);

    $middleware = new StartRequestTrace($tracer);
    $request = Request::create('/health', 'GET');

    $response = $middleware->handle($request, fn () => new Response('OK', 200));

    expect($tracer->activeSpan())->toBeNull();
    expect($response->getStatusCode())->toBe(200);
});

it('sets ERROR status on 5xx response', function () {
    $tracer = new OtlpTracer(new OtlpTransport);
    $this->app->instance(TracerContract::class, $tracer);

    $middleware = new StartRequestTrace($tracer);
    $request = Request::create('/api/orders', 'GET');
    $response = new Response('Server Error', 500);

    $middleware->handle($request, fn () => $response);
    $middleware->terminate($request, $response);

    Http::assertSent(function ($request) {
        $span = $request->data()['resourceSpans'][0]['scopeSpans'][0]['spans'][0] ?? null;

        return $span && $span['status']['code'] === 2; // SpanStatus::ERROR
    });
});

it('respects sample rate', function () {
    config()->set('monitoring.traces.sample_rate', 0.0); // Never sample

    $tracer = new OtlpTracer(new OtlpTransport);
    $this->app->instance(TracerContract::class, $tracer);

    $middleware = new StartRequestTrace($tracer);
    $request = Request::create('/api/orders', 'GET');

    $middleware->handle($request, fn () => new Response('OK', 200));

    expect($tracer->activeSpan())->toBeNull();
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/pest tests/Middleware/StartRequestTraceTest.php`
Expected: FAIL.

- [ ] **Step 3: Implement StartRequestTrace**

Create `src/Http/Middleware/StartRequestTrace.php`:

```php
<?php

namespace ModusDigital\LaravelMonitoring\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use ModusDigital\LaravelMonitoring\Context\RequestContext;
use ModusDigital\LaravelMonitoring\Contracts\TracerContract;
use ModusDigital\LaravelMonitoring\Tracing\Span;
use ModusDigital\LaravelMonitoring\Tracing\SpanKind;
use ModusDigital\LaravelMonitoring\Tracing\SpanStatus;
use Symfony\Component\HttpFoundation\Response;

class StartRequestTrace
{
    private ?Span $rootSpan = null;

    private bool $sampled = true;

    public function __construct(
        private readonly TracerContract $tracer,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->isExcluded($request)) {
            return $next($request);
        }

        $this->sampled = $this->shouldSample($request);

        if (! $this->sampled) {
            return $next($request);
        }

        $traceparent = $this->parseTraceparent($request->header('traceparent'));

        $this->rootSpan = $this->tracer->startSpan(
            name: 'http.request',
            attributes: [
                'http.method' => $request->method(),
                'http.route' => $request->route()?->getName() ?? $request->path(),
            ],
            kind: SpanKind::SERVER,
            traceId: $traceparent['traceId'] ?? null,
            parentSpanId: $traceparent['parentSpanId'] ?? null,
        );

        $ctx = new RequestContext(
            traceId: $this->rootSpan->traceId,
            spanId: $this->rootSpan->spanId,
            requestId: $request->header('X-Request-ID') ?? bin2hex(random_bytes(8)),
        );
        $ctx->route = $request->route()?->getName() ?? $request->path();
        $ctx->method = $request->method();

        app()->instance(RequestContext::class, $ctx);

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        if ($this->rootSpan === null) {
            return;
        }

        $statusCode = $response->getStatusCode();
        $this->rootSpan->setAttribute('http.status_code', $statusCode);
        $this->rootSpan->setAttribute('http.status_group', intdiv($statusCode, 100).'xx');

        if ($statusCode >= 500) {
            $this->rootSpan->setStatus(SpanStatus::ERROR);
        }

        $this->rootSpan->end();
        $this->tracer->flush();
    }

    private function isExcluded(Request $request): bool
    {
        /** @var list<string> $excludes */
        $excludes = config('monitoring.middleware.exclude', []);

        foreach ($excludes as $pattern) {
            $routeName = $request->route()?->getName();
            if ($routeName !== null && str_contains($routeName, $pattern)) {
                return true;
            }
            if (str_contains($request->path(), $pattern)) {
                return true;
            }
        }

        return false;
    }

    private function shouldSample(Request $request): bool
    {
        // Respect upstream sampling decision
        $traceparent = $request->header('traceparent');
        if ($traceparent !== null) {
            $parts = explode('-', $traceparent);
            if (isset($parts[3]) && ((int) hexdec($parts[3]) & 0x01)) {
                return true;
            }
        }

        $rate = (float) config('monitoring.traces.sample_rate', 1.0);

        if ($rate >= 1.0) {
            return true;
        }

        if ($rate <= 0.0) {
            return false;
        }

        return (mt_rand() / mt_getrandmax()) < $rate;
    }

    /** @return array{traceId: ?string, parentSpanId: ?string, traceFlags: int}|null */
    private function parseTraceparent(?string $header): ?array
    {
        if ($header === null) {
            return null;
        }

        $parts = explode('-', $header);
        if (count($parts) !== 4) {
            return null;
        }

        return [
            'traceId' => $parts[1],
            'parentSpanId' => $parts[2],
            'traceFlags' => (int) hexdec($parts[3]),
        ];
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/pest tests/Middleware/StartRequestTraceTest.php`
Expected: All 6 tests PASS.

- [ ] **Step 5: Run PHPStan**

Run: `composer analyse`

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "feat: add StartRequestTrace middleware with W3C traceparent support"
```

---

## Task 12: Service Provider Wiring and Facade Update

Wire everything together — register contracts, configure log channel, update facade.

**Files:**
- Rewrite: `src/MonitoringServiceProvider.php`
- Rewrite: `src/Facades/Monitoring.php`
- Rewrite: `src/helpers.php`

- [ ] **Step 1: Implement full MonitoringServiceProvider**

Rewrite `src/MonitoringServiceProvider.php`:

```php
<?php

namespace ModusDigital\LaravelMonitoring;

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;
use ModusDigital\LaravelMonitoring\Contracts\LogExporterContract;
use ModusDigital\LaravelMonitoring\Contracts\MetricExporterContract;
use ModusDigital\LaravelMonitoring\Contracts\TracerContract;
use ModusDigital\LaravelMonitoring\Logging\MonitoringLogProcessor;
use ModusDigital\LaravelMonitoring\Logging\OtlpLogHandler;
use ModusDigital\LaravelMonitoring\Metrics\MetricRegistry;
use ModusDigital\LaravelMonitoring\Null\NullLogExporter;
use ModusDigital\LaravelMonitoring\Null\NullMetricExporter;
use ModusDigital\LaravelMonitoring\Null\NullTracer;
use ModusDigital\LaravelMonitoring\Otlp\OtlpLogExporter;
use ModusDigital\LaravelMonitoring\Otlp\OtlpMetricExporter;
use ModusDigital\LaravelMonitoring\Otlp\OtlpTracer;
use ModusDigital\LaravelMonitoring\Otlp\OtlpTransport;

class MonitoringServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/monitoring.php',
            'monitoring'
        );

        $this->app->singleton(OtlpTransport::class);
        $this->app->singleton(MetricRegistry::class);

        $this->app->singleton(TracerContract::class, function ($app) {
            if (! config('monitoring.enabled') || ! config('monitoring.traces.enabled')) {
                return new NullTracer;
            }

            return new OtlpTracer($app->make(OtlpTransport::class));
        });

        $this->app->singleton(LogExporterContract::class, function ($app) {
            if (! config('monitoring.enabled') || ! config('monitoring.logs.enabled')) {
                return new NullLogExporter;
            }

            return new OtlpLogExporter($app->make(OtlpTransport::class));
        });

        $this->app->singleton(MetricExporterContract::class, function ($app) {
            if (! config('monitoring.enabled') || ! config('monitoring.metrics.enabled')) {
                return new NullMetricExporter;
            }

            return new OtlpMetricExporter($app->make(OtlpTransport::class));
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/monitoring.php' => config_path('monitoring.php'),
        ], 'monitoring-config');

        // Register monitoring log channel
        $this->app->make('config')->set('logging.channels.monitoring', [
            'driver' => 'monolog',
            'handler' => OtlpLogHandler::class,
            'handler_with' => [
                'exporter' => $this->app->make(LogExporterContract::class),
            ],
            'processors' => [MonitoringLogProcessor::class],
        ]);

        // Auto-flush metrics on queue job completion
        if (config('monitoring.enabled') && config('monitoring.metrics.enabled')) {
            Queue::after(function () {
                $this->flushMetrics();
            });

            Queue::failing(function () {
                $this->flushMetrics();
            });
        }
    }

    private function flushMetrics(): void
    {
        $registry = $this->app->make(MetricRegistry::class);
        $exporter = $this->app->make(MetricExporterContract::class);

        $metrics = $registry->all();
        if ($metrics !== []) {
            $exporter->export($metrics);
            $registry->reset();
        }
    }
}
```

- [ ] **Step 2: Update Facade**

Rewrite `src/Facades/Monitoring.php`:

```php
<?php

namespace ModusDigital\LaravelMonitoring\Facades;

use Closure;
use Illuminate\Support\Facades\Facade;
use ModusDigital\LaravelMonitoring\Contracts\MetricExporterContract;
use ModusDigital\LaravelMonitoring\Contracts\TracerContract;
use ModusDigital\LaravelMonitoring\Metrics\Counter;
use ModusDigital\LaravelMonitoring\Metrics\Gauge;
use ModusDigital\LaravelMonitoring\Metrics\Histogram;
use ModusDigital\LaravelMonitoring\Metrics\MetricRegistry;
use ModusDigital\LaravelMonitoring\Tracing\Span;
use ModusDigital\LaravelMonitoring\Tracing\SpanKind;

/**
 * @method static Counter counter(string $name, array<string, string> $labels = [])
 * @method static Gauge gauge(string $name, array<string, string> $labels = [])
 * @method static Histogram histogram(string $name, array<string, string> $labels = [], ?array<int> $buckets = null)
 *
 * @see MetricRegistry
 */
class Monitoring extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return MetricRegistry::class;
    }

    public static function span(string $name, Closure $callback, SpanKind $kind = SpanKind::INTERNAL): mixed
    {
        $tracer = app(TracerContract::class);
        $span = $tracer->startSpan($name, kind: $kind);

        try {
            $result = $callback();
            $span->end();

            return $result;
        } catch (\Throwable $e) {
            $span->addEvent('exception', [
                'exception.type' => get_class($e),
                'exception.message' => $e->getMessage(),
            ]);
            $span->setStatus(\ModusDigital\LaravelMonitoring\Tracing\SpanStatus::ERROR);
            $span->end();

            throw $e;
        }
    }

    /** @param array<string, mixed> $attributes */
    public static function startSpan(string $name, array $attributes = [], SpanKind $kind = SpanKind::INTERNAL): Span
    {
        return app(TracerContract::class)->startSpan($name, $attributes, $kind);
    }

    public static function flush(): void
    {
        $tracer = app(TracerContract::class);
        $tracer->flush();

        $registry = app(MetricRegistry::class);
        $exporter = app(MetricExporterContract::class);
        $metrics = $registry->all();
        if ($metrics !== []) {
            $exporter->export($metrics);
            $registry->reset();
        }
    }
}
```

- [ ] **Step 3: Update helpers.php**

Rewrite `src/helpers.php`:

```php
<?php

use ModusDigital\LaravelMonitoring\Metrics\MetricRegistry;

if (! function_exists('monitoring')) {
    function monitoring(): MetricRegistry
    {
        return app(MetricRegistry::class);
    }
}
```

(This stays the same — it already returns MetricRegistry.)

- [ ] **Step 4: Write Facade integration tests**

Create `tests/Facades/MonitoringTest.php`:

```php
<?php

use Illuminate\Support\Facades\Http;
use ModusDigital\LaravelMonitoring\Facades\Monitoring;
use ModusDigital\LaravelMonitoring\Tracing\SpanStatus;

beforeEach(function () {
    Http::fake(['*' => Http::response('', 200)]);
    config()->set('monitoring.enabled', true);
    config()->set('monitoring.traces.enabled', true);
    config()->set('monitoring.metrics.enabled', true);
    config()->set('monitoring.otlp.endpoint', 'http://alloy:4318');
    config()->set('monitoring.service.name', 'test-app');
    config()->set('monitoring.service.environment', 'testing');
    config()->set('monitoring.service.instance_id', 'http://localhost');
});

it('wraps a closure in a span', function () {
    $result = Monitoring::span('test.operation', function () {
        return 42;
    });

    expect($result)->toBe(42);
});

it('records exception in span and rethrows', function () {
    expect(function () {
        Monitoring::span('failing.operation', function () {
            throw new \RuntimeException('Test error');
        });
    })->toThrow(\RuntimeException::class, 'Test error');
});

it('starts a manual span', function () {
    $span = Monitoring::startSpan('manual.span');

    expect($span->name)->toBe('manual.span');
    $span->end();
});

it('flushes traces and metrics', function () {
    Monitoring::counter('test_counter')->increment();
    Monitoring::flush();

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/v1/metrics');
    });
});
```

- [ ] **Step 5: Run all tests**

Run: `vendor/bin/pest`
Expected: All tests PASS.

- [ ] **Step 6: Run PHPStan and format**

Run: `composer analyse && composer format`

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -m "feat: wire service provider — register contracts, log channel, queue flush, update facade"
```

---

## Task 13: Final Cleanup and Full Verification

**Files:**
- Modify: `composer.json` (verify description)
- Delete: `src/Commands/` directory (if empty)

- [ ] **Step 1: Clean up empty directories**

```bash
cd E:/packages/laravel-monitoring
rmdir src/Commands 2>/dev/null
```

- [ ] **Step 2: Run full test suite**

Run: `vendor/bin/pest`
Expected: All tests PASS.

- [ ] **Step 3: Run PHPStan level 8**

Run: `composer analyse`
Expected: No errors.

- [ ] **Step 4: Run Laravel Pint**

Run: `composer format`

- [ ] **Step 5: Run tests once more after formatting**

Run: `vendor/bin/pest`
Expected: Still all PASS.

- [ ] **Step 6: Commit any formatting changes**

```bash
git add -A
git commit -m "chore: apply code formatting via Pint"
```

(Only if Pint made changes.)

- [ ] **Step 7: Verify final file structure**

```bash
find src -type f -name '*.php' | sort
```

Expected output:
```
src/Context/RequestContext.php
src/Contracts/LogExporterContract.php
src/Contracts/MetricExporterContract.php
src/Contracts/TracerContract.php
src/Facades/Monitoring.php
src/Http/Middleware/StartRequestTrace.php
src/Logging/MonitoringLogProcessor.php
src/Logging/OtlpLogHandler.php
src/Metrics/Counter.php
src/Metrics/Gauge.php
src/Metrics/Histogram.php
src/Metrics/Metric.php
src/Metrics/MetricRegistry.php
src/MonitoringServiceProvider.php
src/Null/NullLogExporter.php
src/Null/NullMetricExporter.php
src/Null/NullTracer.php
src/Otlp/OtlpLogExporter.php
src/Otlp/OtlpMetricExporter.php
src/Otlp/OtlpTracer.php
src/Otlp/OtlpTransport.php
src/Otlp/ResourceAttributes.php
src/Tracing/Span.php
src/Tracing/SpanKind.php
src/Tracing/SpanStatus.php
src/helpers.php
```

- [ ] **Step 8: Final commit**

```bash
git add -A
git commit -m "chore: final cleanup — verify structure and tests"
```
