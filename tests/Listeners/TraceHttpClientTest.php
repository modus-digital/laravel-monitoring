<?php

use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Client\Response as ClientResponse;
use Illuminate\Support\Facades\Http;
use ModusDigital\LaravelMonitoring\Contracts\TracerContract;
use ModusDigital\LaravelMonitoring\Listeners\TraceHttpClient;
use ModusDigital\LaravelMonitoring\Otlp\OtlpTracer;
use ModusDigital\LaravelMonitoring\Otlp\OtlpTransport;
use ModusDigital\LaravelMonitoring\Tracing\SpanKind;
use ModusDigital\LaravelMonitoring\Tracing\SpanStatus;

beforeEach(function () {
    Http::fake(['*' => Http::response('', 200)]);
    config()->set('monitoring.enabled', true);
    config()->set('monitoring.traces.enabled', true);
    config()->set('monitoring.auto_instrumentation.http_client', true);
    config()->set('monitoring.otlp.endpoint', 'http://alloy:4318');
    config()->set('monitoring.service.name', 'test-app');
    config()->set('monitoring.service.environment', 'testing');
    config()->set('monitoring.service.instance_id', 'http://localhost');
});

it('creates a child span for an HTTP client request', function () {
    $tracer = new OtlpTracer(new OtlpTransport);
    $this->app->instance(TracerContract::class, $tracer);

    $tracer->startSpan('http.request', kind: SpanKind::SERVER);

    $psrRequest = new PsrRequest('GET', 'https://api.example.com/users');
    $clientRequest = new ClientRequest($psrRequest);
    $psrResponse = new PsrResponse(200, [], 'OK');
    $clientResponse = new ClientResponse($psrResponse);

    $listener = new TraceHttpClient($tracer);
    $listener->handleRequestSending(new RequestSending($clientRequest));
    $listener->handleResponseReceived(new ResponseReceived($clientRequest, $clientResponse));

    $tracer->flush();

    Http::assertSent(function ($request) {
        $spans = $request->data()['resourceSpans'][0]['scopeSpans'][0]['spans'] ?? [];
        $httpSpan = collect($spans)->first(fn ($s) => $s['name'] === 'http.client');

        if (! $httpSpan) {
            return false;
        }

        $attrs = collect($httpSpan['attributes'])->pluck('value', 'key');

        return $httpSpan['kind'] === SpanKind::CLIENT->value
            && ($attrs['http.method']['stringValue'] ?? null) === 'GET'
            && ($attrs['http.url']['stringValue'] ?? null) === 'https://api.example.com/users'
            && ($attrs['http.status_code']['intValue'] ?? null) === '200';
    });
});

it('sets ERROR status on 5xx response', function () {
    $tracer = new OtlpTracer(new OtlpTransport);
    $this->app->instance(TracerContract::class, $tracer);

    $tracer->startSpan('http.request', kind: SpanKind::SERVER);

    $psrRequest = new PsrRequest('POST', 'https://api.example.com/orders');
    $clientRequest = new ClientRequest($psrRequest);
    $psrResponse = new PsrResponse(500, [], 'Server Error');
    $clientResponse = new ClientResponse($psrResponse);

    $listener = new TraceHttpClient($tracer);
    $listener->handleRequestSending(new RequestSending($clientRequest));
    $listener->handleResponseReceived(new ResponseReceived($clientRequest, $clientResponse));

    $tracer->flush();

    Http::assertSent(function ($request) {
        $spans = $request->data()['resourceSpans'][0]['scopeSpans'][0]['spans'] ?? [];
        $httpSpan = collect($spans)->first(fn ($s) => $s['name'] === 'http.client');

        return $httpSpan && $httpSpan['status']['code'] === SpanStatus::ERROR->value;
    });
});

it('handles missing RequestSending event gracefully', function () {
    $tracer = new OtlpTracer(new OtlpTransport);
    $this->app->instance(TracerContract::class, $tracer);

    $tracer->startSpan('http.request', kind: SpanKind::SERVER);

    $psrRequest = new PsrRequest('GET', 'https://api.example.com/users');
    $clientRequest = new ClientRequest($psrRequest);
    $psrResponse = new PsrResponse(200, [], 'OK');
    $clientResponse = new ClientResponse($psrResponse);

    $listener = new TraceHttpClient($tracer);
    $listener->handleResponseReceived(new ResponseReceived($clientRequest, $clientResponse));

    $tracer->flush();

    Http::assertSent(function ($request) {
        $spans = $request->data()['resourceSpans'][0]['scopeSpans'][0]['spans'] ?? [];

        return collect($spans)->contains(fn ($s) => $s['name'] === 'http.client');
    });
});

it('is a no-op when no active span exists', function () {
    $tracer = new OtlpTracer(new OtlpTransport);
    $this->app->instance(TracerContract::class, $tracer);

    $psrRequest = new PsrRequest('GET', 'https://api.example.com/users');
    $clientRequest = new ClientRequest($psrRequest);
    $psrResponse = new PsrResponse(200, [], 'OK');
    $clientResponse = new ClientResponse($psrResponse);

    $listener = new TraceHttpClient($tracer);
    $listener->handleRequestSending(new RequestSending($clientRequest));
    $listener->handleResponseReceived(new ResponseReceived($clientRequest, $clientResponse));

    $tracer->flush();

    Http::assertNothingSent();
});

it('is a no-op when config toggle is off', function () {
    config()->set('monitoring.auto_instrumentation.http_client', false);

    $tracer = new OtlpTracer(new OtlpTransport);
    $this->app->instance(TracerContract::class, $tracer);

    $tracer->startSpan('http.request', kind: SpanKind::SERVER);

    $psrRequest = new PsrRequest('GET', 'https://api.example.com/users');
    $clientRequest = new ClientRequest($psrRequest);
    $psrResponse = new PsrResponse(200, [], 'OK');
    $clientResponse = new ClientResponse($psrResponse);

    $listener = new TraceHttpClient($tracer);
    $listener->handleRequestSending(new RequestSending($clientRequest));
    $listener->handleResponseReceived(new ResponseReceived($clientRequest, $clientResponse));

    $tracer->flush();

    Http::assertSent(function ($request) {
        $spans = $request->data()['resourceSpans'][0]['scopeSpans'][0]['spans'] ?? [];

        return count($spans) === 1 && $spans[0]['name'] === 'http.request';
    });
});
