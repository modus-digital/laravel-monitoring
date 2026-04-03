<?php

use ModusDigital\LaravelMonitoring\Metrics\Counter;

it('starts at zero', function () {
    $counter = new Counter('test');

    expect($counter->getValue())->toBe(0.0);
});

it('increments by one', function () {
    $counter = new Counter('test');
    $counter->increment();

    expect($counter->getValue())->toBe(1.0);
});

it('increments by a specific amount', function () {
    $counter = new Counter('test');
    $counter->incrementBy(5.5);

    expect($counter->getValue())->toBe(5.5);
});

it('accumulates multiple increments', function () {
    $counter = new Counter('test');
    $counter->increment();
    $counter->incrementBy(2.5);
    $counter->increment();

    expect($counter->getValue())->toBe(4.5);
});

it('resets to zero', function () {
    $counter = new Counter('test');
    $counter->incrementBy(10);
    $counter->reset();

    expect($counter->getValue())->toBe(0.0);
});
