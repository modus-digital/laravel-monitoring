<?php

use ModusDigital\LaravelMonitoring\Metrics\Counter;
use ModusDigital\LaravelMonitoring\Metrics\Gauge;
use ModusDigital\LaravelMonitoring\Metrics\Histogram;
use ModusDigital\LaravelMonitoring\Metrics\MetricRegistry;

it('creates a counter', function () {
    $registry = new MetricRegistry;
    $counter = $registry->counter('test_counter');

    expect($counter)->toBeInstanceOf(Counter::class);
    expect($counter->getName())->toBe('test_counter');
});

it('creates a gauge', function () {
    $registry = new MetricRegistry;
    $gauge = $registry->gauge('test_gauge');

    expect($gauge)->toBeInstanceOf(Gauge::class);
});

it('creates a histogram', function () {
    $registry = new MetricRegistry;
    $histogram = $registry->histogram('test_histogram');

    expect($histogram)->toBeInstanceOf(Histogram::class);
});

it('returns same instance for same name and labels', function () {
    $registry = new MetricRegistry;
    $a = $registry->counter('test', ['method' => 'GET']);
    $b = $registry->counter('test', ['method' => 'GET']);

    expect($a)->toBe($b);
});

it('returns different instances for different labels', function () {
    $registry = new MetricRegistry;
    $a = $registry->counter('test', ['method' => 'GET']);
    $b = $registry->counter('test', ['method' => 'POST']);

    expect($a)->not->toBe($b);
});

it('returns all registered metrics', function () {
    $registry = new MetricRegistry;
    $registry->counter('counter_a');
    $registry->gauge('gauge_a');
    $registry->histogram('hist_a');

    $all = $registry->all();

    expect($all)->toHaveCount(3);
});

it('resets all metrics', function () {
    $registry = new MetricRegistry;
    $counter = $registry->counter('test');
    $counter->incrementBy(10);

    $registry->reset();

    expect($counter->getValue())->toBe(0.0);
});

it('resolves via the monitoring() helper', function () {
    $registry = app(MetricRegistry::class);

    expect(monitoring())->toBe($registry);
});
