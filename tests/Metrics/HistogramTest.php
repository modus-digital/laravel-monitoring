<?php

use ModusDigital\LaravelMonitoring\Metrics\Histogram;

beforeEach(function () {
    config()->set('monitoring.cache.store', 'array');
    config()->set('monitoring.cache.key_prefix', 'test_monitoring');
    config()->set('monitoring.cache.ttl', 3600);
});

it('starts with all buckets at zero', function () {
    $histogram = new Histogram('duration_ms', [], [10, 50, 100]);
    $buckets = $histogram->getBuckets();
    expect($buckets)->toEqual([10 => 0, 50 => 0, 100 => 0, '+Inf' => 0]);
    expect($histogram->getSum())->toEqual(0);
    expect($histogram->getCount())->toEqual(0);
});

it('observes a value into the correct buckets', function () {
    $histogram = new Histogram('duration_ms', [], [10, 50, 100]);
    $histogram->observe(25);
    $buckets = $histogram->getBuckets();
    expect($buckets[10])->toEqual(0);
    expect($buckets[50])->toEqual(1);
    expect($buckets[100])->toEqual(1);
    expect($buckets['+Inf'])->toEqual(1);
    expect($histogram->getSum())->toEqual(25);
    expect($histogram->getCount())->toEqual(1);
});

it('accumulates multiple observations', function () {
    $histogram = new Histogram('duration_ms', [], [10, 50, 100]);
    $histogram->observe(5);
    $histogram->observe(75);
    $histogram->observe(200);
    $buckets = $histogram->getBuckets();
    expect($buckets[10])->toEqual(1);
    expect($buckets[50])->toEqual(1);
    expect($buckets[100])->toEqual(2);
    expect($buckets['+Inf'])->toEqual(3);
    expect($histogram->getSum())->toEqual(280);
    expect($histogram->getCount())->toEqual(3);
});

it('uses default buckets when none provided', function () {
    $histogram = new Histogram('duration_ms', []);
    $buckets = $histogram->getBuckets();
    expect(array_keys($buckets))->toContain(5, 10, 25, 50, 100, 250, 500, 1000, 2500, 5000, 10000, '+Inf');
});

it('resets all bucket counts and sum', function () {
    $histogram = new Histogram('duration_ms', [], [10, 50, 100]);
    $histogram->observe(25);
    $histogram->observe(75);
    $histogram->reset();
    expect($histogram->getCount())->toEqual(0);
    expect($histogram->getSum())->toEqual(0);
    expect($histogram->getBuckets())->toEqual([10 => 0, 50 => 0, 100 => 0, '+Inf' => 0]);
});

it('returns histogram type', function () {
    $histogram = new Histogram('test', []);
    expect($histogram->getType())->toBe('histogram');
});
