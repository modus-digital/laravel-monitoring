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

it('creates a root span with SERVER kind and flushes', function () {
    $tracer = new OtlpTracer(new OtlpTransport);
    $this->app->instance(TracerContract::class, $tracer);

    $middleware = new StartRequestTrace($tracer);
    $request = Request::create('/api/orders', 'GET');

    $middleware->handle($request, function () {
        return new Response('OK', 200);
    });

    // Span is flushed inline — verify it was sent
    Http::assertSent(function ($request) {
        $span = $request->data()['resourceSpans'][0]['scopeSpans'][0]['spans'][0] ?? null;

        return $span && $span['name'] === 'http.request' && $span['kind'] === 2; // SERVER
    });
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

it('sets response attributes including status code', function () {
    $tracer = new OtlpTracer(new OtlpTransport);
    $this->app->instance(TracerContract::class, $tracer);

    $middleware = new StartRequestTrace($tracer);
    $request = Request::create('/api/orders', 'GET');

    $middleware->handle($request, fn () => new Response('Not Found', 404));

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

    Http::assertSent(function ($request) use ($traceId, $parentSpanId) {
        $span = $request->data()['resourceSpans'][0]['scopeSpans'][0]['spans'][0] ?? null;

        return $span
            && $span['traceId'] === $traceId
            && $span['parentSpanId'] === $parentSpanId;
    });
});

it('respects upstream sampled=0 flag', function () {
    $tracer = new OtlpTracer(new OtlpTransport);
    $this->app->instance(TracerContract::class, $tracer);

    $middleware = new StartRequestTrace($tracer);
    $traceId = str_repeat('ab', 16);
    $parentSpanId = str_repeat('cd', 8);
    $request = Request::create('/api/orders', 'GET');
    $request->headers->set('traceparent', "00-{$traceId}-{$parentSpanId}-00");

    $middleware->handle($request, fn () => new Response('OK', 200));

    Http::assertNothingSent();
});

it('skips excluded routes', function () {
    config()->set('monitoring.middleware.exclude', ['health']);

    $tracer = new OtlpTracer(new OtlpTransport);
    $this->app->instance(TracerContract::class, $tracer);

    $middleware = new StartRequestTrace($tracer);
    $request = Request::create('/health', 'GET');

    $response = $middleware->handle($request, fn () => new Response('OK', 200));

    Http::assertNothingSent();
    expect($response->getStatusCode())->toBe(200);
});

it('sets ERROR status on 5xx response', function () {
    $tracer = new OtlpTracer(new OtlpTransport);
    $this->app->instance(TracerContract::class, $tracer);

    $middleware = new StartRequestTrace($tracer);
    $request = Request::create('/api/orders', 'GET');

    $middleware->handle($request, fn () => new Response('Server Error', 500));

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

    Http::assertNothingSent();
});

it('does not flush twice when terminate is also called', function () {
    $tracer = new OtlpTracer(new OtlpTransport);
    $this->app->instance(TracerContract::class, $tracer);

    $middleware = new StartRequestTrace($tracer);
    $request = Request::create('/api/orders', 'GET');
    $response = new Response('OK', 200);

    $middleware->handle($request, fn () => $response);
    $middleware->terminate($request, $response);

    // Should only send once (handle flushes, terminate is a no-op)
    Http::assertSentCount(1);
});
