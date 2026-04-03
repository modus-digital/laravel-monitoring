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
