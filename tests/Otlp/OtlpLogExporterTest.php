<?php

use Illuminate\Support\Facades\Http;
use ModusDigital\LaravelMonitoring\Otlp\OtlpLogExporter;
use ModusDigital\LaravelMonitoring\Otlp\OtlpTransport;

beforeEach(function () {
    Http::fake(['*' => Http::response('', 200)]);
    config()->set('monitoring.otlp.endpoint', 'http://alloy:4318');
    config()->set('monitoring.service.name', 'test-app');
    config()->set('monitoring.service.environment', 'testing');
    config()->set('monitoring.service.instance_id', 'http://localhost');
});

it('exports log records via OTLP', function () {
    $exporter = new OtlpLogExporter(new OtlpTransport);
    $exporter->export([
        [
            'timeUnixNano' => '1712150400000000000',
            'severityNumber' => 9,
            'severityText' => 'INFO',
            'body' => 'Test log message',
            'attributes' => ['trace_id' => 'abc123'],
            'traceId' => 'abc123',
            'spanId' => 'def456',
        ],
    ]);

    Http::assertSent(function ($request) {
        return $request->url() === 'http://alloy:4318/v1/logs'
            && isset($request->data()['resourceLogs']);
    });
});

it('includes resource attributes', function () {
    $exporter = new OtlpLogExporter(new OtlpTransport);
    $exporter->export([
        [
            'timeUnixNano' => '1712150400000000000',
            'severityNumber' => 9,
            'severityText' => 'INFO',
            'body' => 'Test',
            'attributes' => [],
        ],
    ]);

    Http::assertSent(function ($request) {
        $resource = $request->data()['resourceLogs'][0]['resource'];
        $attrs = collect($resource['attributes'])->pluck('value.stringValue', 'key');

        return $attrs['service.name'] === 'test-app';
    });
});

it('does not send when no log records', function () {
    $exporter = new OtlpLogExporter(new OtlpTransport);
    $exporter->export([]);

    Http::assertNothingSent();
});
