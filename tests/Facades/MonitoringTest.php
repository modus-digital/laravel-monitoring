<?php

use Illuminate\Support\Facades\Http;
use ModusDigital\LaravelMonitoring\Facades\Monitoring;

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
