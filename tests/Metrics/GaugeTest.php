<?php

use ModusDigital\LaravelMonitoring\Metrics\Gauge;

it('starts at zero', function () {
    $gauge = new Gauge('test');

    expect($gauge->getValue())->toBe(0.0);
});

it('sets an absolute value', function () {
    $gauge = new Gauge('test');
    $gauge->set(42.5);

    expect($gauge->getValue())->toBe(42.5);
});

it('increments by one', function () {
    $gauge = new Gauge('test');
    $gauge->set(10);
    $gauge->increment();

    expect($gauge->getValue())->toBe(11.0);
});

it('decrements by one', function () {
    $gauge = new Gauge('test');
    $gauge->set(10);
    $gauge->decrement();

    expect($gauge->getValue())->toBe(9.0);
});

it('increments by a specific amount', function () {
    $gauge = new Gauge('test');
    $gauge->incrementBy(3.5);

    expect($gauge->getValue())->toBe(3.5);
});

it('decrements by a specific amount', function () {
    $gauge = new Gauge('test');
    $gauge->set(10);
    $gauge->decrementBy(3.5);

    expect($gauge->getValue())->toBe(6.5);
});

it('resets to zero', function () {
    $gauge = new Gauge('test');
    $gauge->set(100);
    $gauge->reset();

    expect($gauge->getValue())->toBe(0.0);
});

it('returns type gauge', function () {
    $gauge = new Gauge('test');

    expect($gauge->getType())->toBe('gauge');
});
