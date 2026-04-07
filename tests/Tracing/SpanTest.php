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

it('records start time as unix epoch nanoseconds', function () {
    $before = (int) (microtime(true) * 1_000_000_000);
    $span = new Span('test.span');
    $after = (int) (microtime(true) * 1_000_000_000);

    expect($span->startTimeNano)->toBeGreaterThanOrEqual($before);
    expect($span->startTimeNano)->toBeLessThanOrEqual($after);
    // Sanity check: should be a recent Unix timestamp in nanoseconds (year 2024+)
    expect($span->startTimeNano)->toBeGreaterThan(1_700_000_000_000_000_000);
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

it('accepts a custom startTimeNano for backdating', function () {
    $customStart = 1_700_000_000_000_000_000;
    $span = new Span('test.span', startTimeNano: $customStart);

    expect($span->startTimeNano)->toBe($customStart);
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
