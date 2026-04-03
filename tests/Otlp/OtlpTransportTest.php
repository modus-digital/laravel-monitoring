<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use ModusDigital\LaravelMonitoring\Otlp\OtlpTransport;

beforeEach(function () {
    Http::fake(['*' => Http::response('', 200)]);
    config()->set('monitoring.otlp.endpoint', 'http://alloy:4318');
    config()->set('monitoring.otlp.timeout', 5);
    config()->set('monitoring.otlp.headers', null);
});

it('posts JSON to the correct endpoint', function () {
    $transport = new OtlpTransport;
    $transport->send('/v1/traces', ['resourceSpans' => []]);

    Http::assertSent(function ($request) {
        return $request->url() === 'http://alloy:4318/v1/traces'
            && $request->hasHeader('Content-Type', 'application/json');
    });
});

it('includes custom headers from config', function () {
    config()->set('monitoring.otlp.headers', 'X-Scope-OrgID=tenant1,Authorization=Basic abc');

    $transport = new OtlpTransport;
    $transport->send('/v1/logs', ['resourceLogs' => []]);

    Http::assertSent(function ($request) {
        return $request->hasHeader('X-Scope-OrgID', 'tenant1')
            && $request->hasHeader('Authorization', 'Basic abc');
    });
});

it('sends correct JSON payload', function () {
    $transport = new OtlpTransport;
    $payload = ['resourceSpans' => [['resource' => ['attributes' => []]]]];

    $transport->send('/v1/traces', $payload);

    Http::assertSent(function ($request) use ($payload) {
        return $request->data() === $payload;
    });
});

it('does not throw on transport failure', function () {
    Http::fake(['*' => Http::response('', 500)]);

    $transport = new OtlpTransport;
    $transport->send('/v1/traces', ['resourceSpans' => []]);

    // Should not throw — fire and forget
    expect(true)->toBeTrue();
});

it('does not throw on connection error', function () {
    Http::fake(function () {
        throw new ConnectionException('Connection refused');
    });

    $transport = new OtlpTransport;
    $transport->send('/v1/traces', ['resourceSpans' => []]);

    expect(true)->toBeTrue();
});
