<?php

use Illuminate\Cache\Events\CacheHit;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    config()->set('monitoring.enabled', true);
    config()->set('monitoring.traces.enabled', true);
    config()->set('monitoring.auto_instrumentation.db', true);
    config()->set('monitoring.auto_instrumentation.http_client', true);
    config()->set('monitoring.auto_instrumentation.cache', true);
    config()->set('monitoring.auto_instrumentation.queue', true);
});

it('registers DB query listener when enabled', function () {
    expect(Event::hasListeners(QueryExecuted::class))->toBeTrue();
});

it('registers HTTP client listeners when enabled', function () {
    expect(Event::hasListeners(RequestSending::class))->toBeTrue();
    expect(Event::hasListeners(ResponseReceived::class))->toBeTrue();
});

it('registers cache listeners when enabled', function () {
    expect(Event::hasListeners(CacheHit::class))->toBeTrue();
});

it('registers queue listeners when enabled', function () {
    expect(Event::hasListeners(JobProcessing::class))->toBeTrue();
    expect(Event::hasListeners(JobProcessed::class))->toBeTrue();
    expect(Event::hasListeners(JobFailed::class))->toBeTrue();
});
