<?php

use ModusDigital\LaravelMonitoring\Metrics\Counter;

beforeEach(function () {
    config()->set('monitoring.cache.store', 'array');
    config()->set('monitoring.cache.key_prefix', 'test_monitoring');
    config()->set('monitoring.cache.ttl', 3600);
});

it('starts at zero', function () {
    $counter = new Counter('requests_total', []);

    expect($counter->getValue())->toBe(0.0);
});

it('increments by 1', function () {
    $counter = new Counter('requests_total', []);

    $counter->increment();
    $counter->increment();
    $counter->increment();

    expect($counter->getValue())->toEqual(3);
});

it('increments by a custom amount', function () {
    $counter = new Counter('requests_total', []);

    $counter->incrementBy(5);
    $counter->incrementBy(3);

    expect($counter->getValue())->toEqual(8);
});

it('increments by a float amount', function () {
    $counter = new Counter('requests_total', []);

    $counter->incrementBy(1.5);
    $counter->incrementBy(2.25);

    expect($counter->getValue())->toEqual(3.75);
});

it('resets to zero', function () {
    $counter = new Counter('requests_total', []);

    $counter->incrementBy(10);
    $counter->reset();

    expect($counter->getValue())->toEqual(0);
});

it('tracks separate label combinations independently', function () {
    $get = new Counter('requests_total', ['method' => 'GET']);
    $post = new Counter('requests_total', ['method' => 'POST']);

    $get->incrementBy(5);
    $post->incrementBy(3);

    expect($get->getValue())->toEqual(5);
    expect($post->getValue())->toEqual(3);
});

it('returns counter type', function () {
    $counter = new Counter('test', []);

    expect($counter->getType())->toBe('counter');
});
