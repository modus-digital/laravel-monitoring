# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

Laravel package providing Prometheus metrics collection + Loki log shipping for Laravel 12/13 apps. Namespace: `ModusDigital\LaravelMonitoring`.

## Commands

```bash
composer test              # Run tests (Pest)
composer test-coverage     # Tests with coverage
composer analyse           # PHPStan level 8
composer format            # Laravel Pint code style
```

Run a single test file:
```bash
vendor/bin/pest tests/Metrics/CounterTest.php
```

Run a single test by name:
```bash
vendor/bin/pest --filter="it can increment"
```

## Architecture

**Metric system**: Abstract `Metric` base class with three concrete types — `Counter`, `Gauge`, `Histogram`. All stored in Laravel cache with integer values (×100) for atomic increment support, divided on read for float precision.

**MetricRegistry**: Singleton managing metric instances. In-memory array deduplicates within a request; a cache-backed registry index tracks metrics across requests. Same name + sorted labels = same instance.

**Cache key pattern**: `monitoring:{type}:{name}:{labels_hash}` — labels are ksorted before md5 hashing, so order doesn't matter.

**RecordMetrics middleware**: Records HTTP request count, duration, and status via `terminate()` (after response sent). Auto-registers when `MONITORING_AUTO_MIDDLEWARE=true`.

**PushMetrics command** (`monitoring:push`): Formats all metrics as Prometheus text exposition format, POSTs to Pushgateway via cURL. Resets counters/histograms after push (gauges persist). Scheduled via config.

**LokiHandler**: Monolog handler shipping logs to Loki as JSON streams with contextual data (route, method, user_id, request_id).

**Service provider**: Registers MetricRegistry singleton, publishes config, auto-registers middleware, schedules push command, registers `loki` log channel.

## Testing

- Pest + Orchestra Testbench for Laravel integration testing
- Array cache driver used in tests for isolation
- Architecture tests enforce no `dd`/`dump`/`ray` calls
- CI matrix: PHP 8.4/8.5 × Laravel 12/13 × prefer-lowest/prefer-stable on Ubuntu + Windows

## Code Style

- PHPStan level 8 (strict) — run `composer analyse` before committing
- Laravel Pint for formatting — run `composer format`
- PHP 8.4 minimum
