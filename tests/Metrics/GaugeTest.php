<?php

use ModusDigital\LaravelMonitoring\Metrics\Gauge;

beforeEach(function () {
    config()->set('monitoring.cache.store', 'array');
    config()->set('monitoring.cache.key_prefix', 'test_monitoring');
    config()->set('monitoring.cache.ttl', 3600);
});

it('starts at zero', function () {
    $gauge = new Gauge('queue_depth', []);
    expect($gauge->getValue())->toEqual(0);
});

it('sets an absolute value', function () {
    $gauge = new Gauge('queue_depth', []);
    $gauge->set(42);
    expect($gauge->getValue())->toEqual(42);
});

it('increments by 1', function () {
    $gauge = new Gauge('queue_depth', []);
    $gauge->set(10);
    $gauge->increment();
    expect($gauge->getValue())->toEqual(11);
});

it('decrements by 1', function () {
    $gauge = new Gauge('queue_depth', []);
    $gauge->set(10);
    $gauge->decrement();
    expect($gauge->getValue())->toEqual(9);
});

it('increments by a custom amount', function () {
    $gauge = new Gauge('queue_depth', []);
    $gauge->set(10);
    $gauge->incrementBy(5);
    expect($gauge->getValue())->toEqual(15);
});

it('decrements by a custom amount', function () {
    $gauge = new Gauge('queue_depth', []);
    $gauge->set(10);
    $gauge->decrementBy(3);
    expect($gauge->getValue())->toEqual(7);
});

it('resets to zero', function () {
    $gauge = new Gauge('queue_depth', []);
    $gauge->set(42);
    $gauge->reset();
    expect($gauge->getValue())->toEqual(0);
});

it('returns gauge type', function () {
    $gauge = new Gauge('test', []);
    expect($gauge->getType())->toBe('gauge');
});
