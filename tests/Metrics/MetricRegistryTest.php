<?php

use ModusDigital\LaravelMonitoring\Metrics\MetricRegistry;
use ModusDigital\LaravelMonitoring\Metrics\Counter;
use ModusDigital\LaravelMonitoring\Metrics\Gauge;
use ModusDigital\LaravelMonitoring\Metrics\Histogram;

beforeEach(function () {
    config()->set('monitoring.cache.store', 'array');
    config()->set('monitoring.cache.key_prefix', 'test_monitoring');
    config()->set('monitoring.cache.ttl', 3600);
});

it('creates a counter and registers it', function () {
    $registry = new MetricRegistry();
    $counter = $registry->counter('requests_total', ['method' => 'GET']);
    expect($counter)->toBeInstanceOf(Counter::class);
    expect($counter->getName())->toBe('requests_total');
    expect($counter->getLabels())->toBe(['method' => 'GET']);
});

it('creates a gauge and registers it', function () {
    $registry = new MetricRegistry();
    $gauge = $registry->gauge('queue_depth', ['queue' => 'emails']);
    expect($gauge)->toBeInstanceOf(Gauge::class);
});

it('creates a histogram and registers it', function () {
    $registry = new MetricRegistry();
    $histogram = $registry->histogram('duration_ms', ['route' => '/api'], [10, 50, 100]);
    expect($histogram)->toBeInstanceOf(Histogram::class);
});

it('returns the same counter for the same name and labels', function () {
    $registry = new MetricRegistry();
    $a = $registry->counter('requests_total', ['method' => 'GET']);
    $b = $registry->counter('requests_total', ['method' => 'GET']);
    $a->increment();
    expect($b->getValue())->toBe(1.0);
});

it('lists all registered metrics', function () {
    $registry = new MetricRegistry();
    $registry->counter('requests_total', ['method' => 'GET']);
    $registry->gauge('queue_depth', []);
    $registry->histogram('duration_ms', [], [10, 50]);
    $all = $registry->all();
    expect($all)->toHaveCount(3);
});

it('does not duplicate registry entries for same metric', function () {
    $registry = new MetricRegistry();
    $registry->counter('requests_total', ['method' => 'GET']);
    $registry->counter('requests_total', ['method' => 'GET']);
    $registry->counter('requests_total', ['method' => 'GET']);
    $all = $registry->all();
    expect($all)->toHaveCount(1);
});

it('cleans stale entries from the registry', function () {
    $registry = new MetricRegistry();
    $counter = $registry->counter('stale_metric', []);
    $counter->increment();
    $counter->reset();
    $registry->cleanStale();
    $all = $registry->all();
    expect($all)->toHaveCount(0);
});

it('resolves via the monitoring() helper', function () {
    expect(monitoring())->toBeInstanceOf(MetricRegistry::class);
});

it('creates a counter via the Monitoring facade', function () {
    $counter = \ModusDigital\LaravelMonitoring\Facades\Monitoring::counter('facade_test', []);
    $counter->increment();
    expect($counter->getValue())->toEqual(1);
});
