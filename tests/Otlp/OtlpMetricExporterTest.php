<?php

use Illuminate\Support\Facades\Http;
use ModusDigital\LaravelMonitoring\Metrics\Counter;
use ModusDigital\LaravelMonitoring\Metrics\Gauge;
use ModusDigital\LaravelMonitoring\Metrics\Histogram;
use ModusDigital\LaravelMonitoring\Otlp\OtlpMetricExporter;
use ModusDigital\LaravelMonitoring\Otlp\OtlpTransport;

beforeEach(function () {
    Http::fake(['*' => Http::response('', 200)]);
    config()->set('monitoring.otlp.endpoint', 'http://alloy:4318');
    config()->set('monitoring.service.name', 'test-app');
    config()->set('monitoring.service.environment', 'testing');
    config()->set('monitoring.service.instance_id', 'http://localhost');
});

it('exports counter as OTLP sum metric', function () {
    $counter = new Counter('orders_total', ['status' => 'ok']);
    $counter->incrementBy(5);

    $exporter = new OtlpMetricExporter(new OtlpTransport);
    $exporter->export([$counter]);

    Http::assertSent(function ($request) {
        $body = $request->data();
        $metric = $body['resourceMetrics'][0]['scopeMetrics'][0]['metrics'][0];

        return $request->url() === 'http://alloy:4318/v1/metrics'
            && $metric['name'] === 'orders_total'
            && isset($metric['sum'])
            && $metric['sum']['aggregationTemporality'] === 1 // DELTA
            && $metric['sum']['isMonotonic'] === true;
    });
});

it('exports gauge as OTLP gauge metric', function () {
    $gauge = new Gauge('queue_depth', ['queue' => 'default']);
    $gauge->set(42);

    $exporter = new OtlpMetricExporter(new OtlpTransport);
    $exporter->export([$gauge]);

    Http::assertSent(function ($request) {
        $metric = $request->data()['resourceMetrics'][0]['scopeMetrics'][0]['metrics'][0];

        return isset($metric['gauge'])
            && $metric['name'] === 'queue_depth';
    });
});

it('exports histogram as OTLP histogram metric', function () {
    $histogram = new Histogram('duration_ms', buckets: [100, 500]);
    $histogram->observe(250);

    $exporter = new OtlpMetricExporter(new OtlpTransport);
    $exporter->export([$histogram]);

    Http::assertSent(function ($request) {
        $metric = $request->data()['resourceMetrics'][0]['scopeMetrics'][0]['metrics'][0];

        return isset($metric['histogram'])
            && $metric['histogram']['aggregationTemporality'] === 1
            && $metric['histogram']['dataPoints'][0]['count'] === '1';
    });
});

it('includes resource attributes', function () {
    $counter = new Counter('test');
    $counter->increment();

    $exporter = new OtlpMetricExporter(new OtlpTransport);
    $exporter->export([$counter]);

    Http::assertSent(function ($request) {
        $resource = $request->data()['resourceMetrics'][0]['resource'];
        $attrs = collect($resource['attributes'])->pluck('value.stringValue', 'key');

        return $attrs['service.name'] === 'test-app'
            && $attrs['deployment.environment'] === 'testing';
    });
});

it('does not send when metrics array is empty', function () {
    $exporter = new OtlpMetricExporter(new OtlpTransport);
    $exporter->export([]);

    Http::assertNothingSent();
});
