<?php

use Illuminate\Support\Facades\Http;
use ModusDigital\LaravelMonitoring\Logging\OtlpLogHandler;
use ModusDigital\LaravelMonitoring\Otlp\OtlpLogExporter;
use ModusDigital\LaravelMonitoring\Otlp\OtlpTransport;
use Monolog\Level;
use Monolog\LogRecord;

beforeEach(function () {
    Http::fake(['*' => Http::response('', 200)]);
    config()->set('monitoring.otlp.endpoint', 'http://alloy:4318');
    config()->set('monitoring.service.name', 'test-app');
    config()->set('monitoring.service.environment', 'testing');
    config()->set('monitoring.service.instance_id', 'http://localhost');
});

it('buffers log records and flushes on close', function () {
    $handler = new OtlpLogHandler(new OtlpLogExporter(new OtlpTransport));

    $record = new LogRecord(
        datetime: new DateTimeImmutable,
        channel: 'test',
        level: Level::Info,
        message: 'Hello world',
        context: ['key' => 'value'],
        extra: ['trace_id' => 'abc'],
    );

    $handler->handle($record);
    Http::assertNothingSent(); // Buffered, not sent yet

    $handler->close();

    Http::assertSent(function ($request) {
        $logRecord = $request->data()['resourceLogs'][0]['scopeLogs'][0]['logRecords'][0];

        return $logRecord['body']['stringValue'] === 'Hello world'
            && $logRecord['severityText'] === 'INFO';
    });
});

it('maps Monolog levels to OTLP severity numbers', function () {
    $handler = new OtlpLogHandler(new OtlpLogExporter(new OtlpTransport));

    $record = new LogRecord(
        datetime: new DateTimeImmutable,
        channel: 'test',
        level: Level::Error,
        message: 'Something failed',
    );

    $handler->handle($record);
    $handler->close();

    Http::assertSent(function ($request) {
        $logRecord = $request->data()['resourceLogs'][0]['scopeLogs'][0]['logRecords'][0];

        return $logRecord['severityNumber'] === 17 // ERROR
            && $logRecord['severityText'] === 'ERROR';
    });
});
