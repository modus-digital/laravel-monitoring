<?php

use Illuminate\Support\Facades\Cache;
use ModusDigital\LaravelMonitoring\Metrics\Counter;

beforeEach(function () {
    config()->set('monitoring.cache.store', 'array');
    config()->set('monitoring.cache.key_prefix', 'test_monitoring');
    config()->set('monitoring.cache.ttl', 3600);
});

it('generates consistent cache keys regardless of label order', function () {
    $a = new Counter('test_metric', ['b' => '2', 'a' => '1']);
    $b = new Counter('test_metric', ['a' => '1', 'b' => '2']);

    $a->increment();
    $b->increment();

    expect($b->getValue())->toBe(2.0);
});

it('generates different cache keys for different labels', function () {
    $a = new Counter('test_metric', ['env' => 'prod']);
    $b = new Counter('test_metric', ['env' => 'staging']);

    $a->increment();
    $b->increment();

    expect($a->getValue())->toBe(1.0);
    expect($b->getValue())->toBe(1.0);
});

it('generates a cache key with no labels', function () {
    $metric = new Counter('simple_metric', []);
    $metric->increment();

    expect($metric->getValue())->toBe(1.0);
});
