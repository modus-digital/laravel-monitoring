<?php

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use ModusDigital\LaravelMonitoring\Http\Middleware\RecordMetrics;
use ModusDigital\LaravelMonitoring\Metrics\MetricRegistry;

beforeEach(function () {
    config()->set('monitoring.cache.store', 'array');
    config()->set('monitoring.cache.key_prefix', 'test_monitoring');
    config()->set('monitoring.cache.ttl', 3600);
    config()->set('monitoring.pushgateway.enabled', true);
    config()->set('monitoring.middleware.exclude', ['_debugbar']);
});

it('records http request counter, duration histogram and duration gauge', function () {
    $registry = app(MetricRegistry::class);
    $middleware = new RecordMetrics;

    $request = Request::create('/api/users', 'GET');
    $response = new Response('OK', 200);

    $middleware->handle($request, fn () => $response);
    $middleware->terminate($request, $response);

    $all = $registry->all();
    $types = array_column($all, 'type');

    expect($types)->toContain('counter');
    expect($types)->toContain('histogram');
    expect($types)->toContain('gauge');
});

it('skips excluded routes', function () {
    $registry = app(MetricRegistry::class);
    $middleware = new RecordMetrics;

    $request = Request::create('/_debugbar/assets', 'GET');
    $response = new Response('OK', 200);

    $middleware->handle($request, fn () => $response);
    $middleware->terminate($request, $response);

    $all = $registry->all();
    expect($all)->toHaveCount(0);
});

it('records metrics even when pushgateway is disabled', function () {
    config()->set('monitoring.pushgateway.enabled', false);

    $registry = app(MetricRegistry::class);
    $middleware = new RecordMetrics;

    $request = Request::create('/api/users', 'GET');
    $response = new Response('OK', 200);

    $middleware->handle($request, fn () => $response);
    $middleware->terminate($request, $response);

    $all = $registry->all();
    expect($all)->not->toHaveCount(0);
});
