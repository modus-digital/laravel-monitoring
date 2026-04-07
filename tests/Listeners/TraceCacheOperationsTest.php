<?php

use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\KeyForgotten;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Support\Facades\Http;
use ModusDigital\LaravelMonitoring\Contracts\TracerContract;
use ModusDigital\LaravelMonitoring\Listeners\TraceCacheOperations;
use ModusDigital\LaravelMonitoring\Otlp\OtlpTracer;
use ModusDigital\LaravelMonitoring\Otlp\OtlpTransport;
use ModusDigital\LaravelMonitoring\Tracing\SpanKind;

beforeEach(function () {
    Http::fake(['*' => Http::response('', 200)]);
    config()->set('monitoring.enabled', true);
    config()->set('monitoring.traces.enabled', true);
    config()->set('monitoring.auto_instrumentation.cache', true);
    config()->set('monitoring.otlp.endpoint', 'http://alloy:4318');
    config()->set('monitoring.service.name', 'test-app');
    config()->set('monitoring.service.environment', 'testing');
    config()->set('monitoring.service.instance_id', 'http://localhost');
});

it('creates a child span for cache hit', function () {
    $tracer = new OtlpTracer(new OtlpTransport);
    $this->app->instance(TracerContract::class, $tracer);

    $tracer->startSpan('http.request', kind: SpanKind::SERVER);

    $event = new CacheHit('array', 'users.1', 'cached-value');

    $listener = new TraceCacheOperations($tracer);
    $listener->handleCacheHit($event);

    $tracer->flush();

    Http::assertSent(function ($request) {
        $spans = $request->data()['resourceSpans'][0]['scopeSpans'][0]['spans'] ?? [];
        $cacheSpan = collect($spans)->first(fn ($s) => $s['name'] === 'cache.hit');

        if (! $cacheSpan) {
            return false;
        }

        $attrs = collect($cacheSpan['attributes'])->pluck('value', 'key');

        return $cacheSpan['kind'] === SpanKind::INTERNAL->value
            && ($attrs['cache.key']['stringValue'] ?? null) === 'users.1'
            && ($attrs['cache.store']['stringValue'] ?? null) === 'array'
            && ($attrs['cache.hit']['boolValue'] ?? null) === true;
    });
});

it('creates a child span for cache miss', function () {
    $tracer = new OtlpTracer(new OtlpTransport);
    $this->app->instance(TracerContract::class, $tracer);

    $tracer->startSpan('http.request', kind: SpanKind::SERVER);

    $event = new CacheMissed('array', 'users.99');

    $listener = new TraceCacheOperations($tracer);
    $listener->handleCacheMissed($event);

    $tracer->flush();

    Http::assertSent(function ($request) {
        $spans = $request->data()['resourceSpans'][0]['scopeSpans'][0]['spans'] ?? [];
        $cacheSpan = collect($spans)->first(fn ($s) => $s['name'] === 'cache.miss');

        if (! $cacheSpan) {
            return false;
        }

        $attrs = collect($cacheSpan['attributes'])->pluck('value', 'key');

        return ($attrs['cache.hit']['boolValue'] ?? null) === false;
    });
});

it('creates a child span for cache write', function () {
    $tracer = new OtlpTracer(new OtlpTransport);
    $this->app->instance(TracerContract::class, $tracer);

    $tracer->startSpan('http.request', kind: SpanKind::SERVER);

    $event = new KeyWritten('array', 'users.1', 'value', 60);

    $listener = new TraceCacheOperations($tracer);
    $listener->handleKeyWritten($event);

    $tracer->flush();

    Http::assertSent(function ($request) {
        $spans = $request->data()['resourceSpans'][0]['scopeSpans'][0]['spans'] ?? [];

        return collect($spans)->contains(fn ($s) => $s['name'] === 'cache.write');
    });
});

it('creates a child span for cache forget', function () {
    $tracer = new OtlpTracer(new OtlpTransport);
    $this->app->instance(TracerContract::class, $tracer);

    $tracer->startSpan('http.request', kind: SpanKind::SERVER);

    $event = new KeyForgotten('array', 'users.1');

    $listener = new TraceCacheOperations($tracer);
    $listener->handleKeyForgotten($event);

    $tracer->flush();

    Http::assertSent(function ($request) {
        $spans = $request->data()['resourceSpans'][0]['scopeSpans'][0]['spans'] ?? [];

        return collect($spans)->contains(fn ($s) => $s['name'] === 'cache.forget');
    });
});

it('is a no-op when no active span exists', function () {
    $tracer = new OtlpTracer(new OtlpTransport);
    $this->app->instance(TracerContract::class, $tracer);

    $event = new CacheHit('array', 'users.1', 'value');

    $listener = new TraceCacheOperations($tracer);
    $listener->handleCacheHit($event);

    $tracer->flush();

    Http::assertNothingSent();
});
