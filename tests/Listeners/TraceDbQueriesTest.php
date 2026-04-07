<?php

use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Http;
use ModusDigital\LaravelMonitoring\Contracts\TracerContract;
use ModusDigital\LaravelMonitoring\Listeners\TraceDbQueries;
use ModusDigital\LaravelMonitoring\Otlp\OtlpTracer;
use ModusDigital\LaravelMonitoring\Otlp\OtlpTransport;
use ModusDigital\LaravelMonitoring\Tracing\SpanKind;

beforeEach(function () {
    Http::fake(['*' => Http::response('', 200)]);
    config()->set('monitoring.enabled', true);
    config()->set('monitoring.traces.enabled', true);
    config()->set('monitoring.auto_instrumentation.db', true);
    config()->set('monitoring.otlp.endpoint', 'http://alloy:4318');
    config()->set('monitoring.service.name', 'test-app');
    config()->set('monitoring.service.environment', 'testing');
    config()->set('monitoring.service.instance_id', 'http://localhost');
});

it('creates a child span for a DB query', function () {
    $tracer = new OtlpTracer(new OtlpTransport);
    $this->app->instance(TracerContract::class, $tracer);

    $tracer->startSpan('http.request', kind: SpanKind::SERVER);

    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('getDriverName')->andReturn('mysql');
    $connection->shouldReceive('getName')->andReturn('mysql');

    $event = new QueryExecuted(
        'SELECT * FROM users WHERE id = ?',
        [1],
        12.5,
        $connection,
    );

    $listener = new TraceDbQueries($tracer);
    $listener->handle($event);

    $tracer->flush();

    Http::assertSent(function ($request) {
        $spans = $request->data()['resourceSpans'][0]['scopeSpans'][0]['spans'] ?? [];
        $dbSpan = collect($spans)->first(fn ($s) => $s['name'] === 'db.query');

        if (! $dbSpan) {
            return false;
        }

        $attrs = collect($dbSpan['attributes'])->pluck('value', 'key');

        return $dbSpan['kind'] === SpanKind::CLIENT->value
            && ($attrs['db.system']['stringValue'] ?? null) === 'mysql'
            && ($attrs['db.statement']['stringValue'] ?? null) === 'SELECT * FROM users WHERE id = ?'
            && ($attrs['db.connection']['stringValue'] ?? null) === 'mysql'
            && isset($attrs['db.duration_ms']);
    });
});

it('is a no-op when no active span exists', function () {
    $tracer = new OtlpTracer(new OtlpTransport);
    $this->app->instance(TracerContract::class, $tracer);

    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('getDriverName')->andReturn('mysql');
    $connection->shouldReceive('getName')->andReturn('mysql');

    $event = new QueryExecuted('SELECT 1', [], 1.0, $connection);

    $listener = new TraceDbQueries($tracer);
    $listener->handle($event);

    $tracer->flush();

    Http::assertNothingSent();
});

it('is a no-op when config toggle is off', function () {
    config()->set('monitoring.auto_instrumentation.db', false);

    $tracer = new OtlpTracer(new OtlpTransport);
    $this->app->instance(TracerContract::class, $tracer);

    $tracer->startSpan('http.request', kind: SpanKind::SERVER);

    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('getDriverName')->andReturn('mysql');
    $connection->shouldReceive('getName')->andReturn('mysql');

    $event = new QueryExecuted('SELECT 1', [], 1.0, $connection);

    $listener = new TraceDbQueries($tracer);
    $listener->handle($event);

    $tracer->flush();

    Http::assertSent(function ($request) {
        $spans = $request->data()['resourceSpans'][0]['scopeSpans'][0]['spans'] ?? [];

        return count($spans) === 1 && $spans[0]['name'] === 'http.request';
    });
});
