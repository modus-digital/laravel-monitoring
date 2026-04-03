<?php

use Illuminate\Support\Facades\Http;
use ModusDigital\LaravelMonitoring\Otlp\OtlpTracer;
use ModusDigital\LaravelMonitoring\Otlp\OtlpTransport;
use ModusDigital\LaravelMonitoring\Tracing\SpanKind;

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
