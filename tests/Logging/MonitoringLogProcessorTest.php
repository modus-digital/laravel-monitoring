<?php

use ModusDigital\LaravelMonitoring\Context\RequestContext;
use ModusDigital\LaravelMonitoring\Logging\MonitoringLogProcessor;
use Monolog\Level;
use Monolog\LogRecord;

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
        datetime: new DateTimeImmutable,
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
        datetime: new DateTimeImmutable,
        channel: 'test',
        level: Level::Info,
        message: 'Test message',
    );

    $result = $processor($record);

    expect($result->extra)->not->toHaveKey('trace_id');
});
