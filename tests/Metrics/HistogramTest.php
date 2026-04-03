<?php

use ModusDigital\LaravelMonitoring\Metrics\Histogram;

it('has default buckets', function () {
    $histogram = new Histogram('test');

    expect($histogram->getBucketBoundaries())->toBe([5, 10, 25, 50, 100, 250, 500, 1000, 2500, 5000, 10000]);
});

it('accepts custom buckets', function () {
    $histogram = new Histogram('test', buckets: [10, 50, 100]);

    expect($histogram->getBucketBoundaries())->toBe([10, 50, 100]);
});

it('observes values into correct buckets', function () {
    $histogram = new Histogram('test', buckets: [10, 50, 100]);
    $histogram->observe(25);

    $buckets = $histogram->getBuckets();
    expect($buckets[10])->toBe(0);   // 25 > 10
    expect($buckets[50])->toBe(1);   // 25 <= 50
    expect($buckets[100])->toBe(1);  // 25 <= 100
    expect($buckets['+Inf'])->toBe(1);
});

it('tracks sum and count', function () {
    $histogram = new Histogram('test', buckets: [100]);
    $histogram->observe(30);
    $histogram->observe(70);

    expect($histogram->getSum())->toBe(100.0);
    expect($histogram->getCount())->toBe(2);
});

it('resets all data', function () {
    $histogram = new Histogram('test', buckets: [100]);
    $histogram->observe(50);
    $histogram->reset();

    expect($histogram->getSum())->toBe(0.0);
    expect($histogram->getCount())->toBe(0);
    expect($histogram->getBuckets()[100])->toBe(0);
});

it('returns type histogram', function () {
    $histogram = new Histogram('test');

    expect($histogram->getType())->toBe('histogram');
});
