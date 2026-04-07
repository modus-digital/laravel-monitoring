<?php

use Illuminate\Support\Facades\Http;
use ModusDigital\LaravelMonitoring\Contracts\TracerContract;
use ModusDigital\LaravelMonitoring\Facades\Monitoring;
use ModusDigital\LaravelMonitoring\Otlp\OtlpTracer;
use ModusDigital\LaravelMonitoring\Otlp\OtlpTransport;
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
            throw new RuntimeException('Test error');
        });
    })->toThrow(RuntimeException::class, 'Test error');
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

it('reportException records exception event on active span', function () {
    $tracer = new OtlpTracer(new OtlpTransport);
    $this->app->instance(TracerContract::class, $tracer);

    $span = Monitoring::startSpan('test.span');
    $exception = new RuntimeException('Something broke');

    Monitoring::reportException($exception);

    $events = $span->getEvents();
    expect($events)->toHaveCount(1);
    expect($events[0]['name'])->toBe('exception');
    expect($events[0]['attributes']['exception.type'])->toBe('RuntimeException');
    expect($events[0]['attributes']['exception.message'])->toBe('Something broke');
    expect($events[0]['attributes']['exception.stacktrace'])->toBeString();
    expect($span->getStatus())->toBe(SpanStatus::ERROR);
});

it('reportException is a no-op when no active span exists', function () {
    $tracer = new OtlpTracer(new OtlpTransport);
    $this->app->instance(TracerContract::class, $tracer);

    Monitoring::reportException(new RuntimeException('No span'));

    expect(true)->toBeTrue();
});

it('span method includes exception.stacktrace', function () {
    $tracer = new OtlpTracer(new OtlpTransport);
    $this->app->instance(TracerContract::class, $tracer);

    try {
        Monitoring::span('failing.operation', function () {
            throw new RuntimeException('Test error');
        });
    } catch (RuntimeException) {
        // expected
    }

    $tracer->flush();

    Http::assertSent(function ($request) {
        $spans = $request->data()['resourceSpans'][0]['scopeSpans'][0]['spans'] ?? [];
        $span = collect($spans)->first(fn ($s) => $s['name'] === 'failing.operation');

        if (! $span) {
            return false;
        }

        $exceptionEvent = collect($span['events'])->first(fn ($e) => $e['name'] === 'exception');
        $attrs = collect($exceptionEvent['attributes'] ?? [])->pluck('value', 'key');

        return isset($attrs['exception.stacktrace']);
    });
});
