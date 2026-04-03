<?php

use ModusDigital\LaravelMonitoring\Metrics\Counter;

it('stores name and labels', function () {
    $counter = new Counter('requests_total', ['method' => 'GET']);

    expect($counter->getName())->toBe('requests_total');
    expect($counter->getLabels())->toBe(['method' => 'GET']);
});

it('sorts labels by key for consistency', function () {
    $counter = new Counter('test', ['z' => '1', 'a' => '2']);

    expect(array_keys($counter->getLabels()))->toBe(['a', 'z']);
});

it('returns its metric type', function () {
    $counter = new Counter('test');

    expect($counter->getType())->toBe('counter');
});
