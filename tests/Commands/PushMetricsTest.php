<?php

use ModusDigital\LaravelMonitoring\Metrics\MetricRegistry;
use Illuminate\Support\Facades\Artisan;

beforeEach(function () {
    config()->set('monitoring.cache.store', 'array');
    config()->set('monitoring.cache.key_prefix', 'test_monitoring');
    config()->set('monitoring.cache.ttl', 3600);
    config()->set('monitoring.pushgateway.enabled', true);
    config()->set('monitoring.pushgateway.url', 'http://localhost:9091');
    config()->set('monitoring.pushgateway.auth', '');
    config()->set('monitoring.pushgateway.job_name', 'test_app');
});

it('outputs prometheus text format for counters', function () {
    $registry = app(MetricRegistry::class);
    $registry->counter('requests_total', ['method' => 'GET'])->incrementBy(5);

    Artisan::call('monitoring:push', ['--dry-run' => true]);
    $output = Artisan::output();

    expect($output)->toContain('# TYPE requests_total counter');
    expect($output)->toContain('requests_total{method="GET"} 5');
});

it('outputs prometheus text format for gauges', function () {
    $registry = app(MetricRegistry::class);
    $registry->gauge('queue_depth', ['queue' => 'emails'])->set(42);

    Artisan::call('monitoring:push', ['--dry-run' => true]);
    $output = Artisan::output();

    expect($output)->toContain('# TYPE queue_depth gauge');
    expect($output)->toContain('queue_depth{queue="emails"} 42');
});

it('outputs prometheus text format for histograms', function () {
    $registry = app(MetricRegistry::class);
    $histogram = $registry->histogram('duration_ms', ['route' => '/api'], [10, 50, 100]);
    $histogram->observe(25);
    $histogram->observe(75);

    Artisan::call('monitoring:push', ['--dry-run' => true]);
    $output = Artisan::output();

    expect($output)->toContain('# TYPE duration_ms histogram');
    expect($output)->toContain('duration_ms_bucket{route="/api",le="10"} 0');
    expect($output)->toContain('duration_ms_bucket{route="/api",le="50"} 1');
    expect($output)->toContain('duration_ms_bucket{route="/api",le="100"} 2');
    expect($output)->toContain('duration_ms_bucket{route="/api",le="+Inf"} 2');
    expect($output)->toContain('duration_ms_count{route="/api"} 2');
});

it('skips push when disabled', function () {
    config()->set('monitoring.pushgateway.enabled', false);
    $registry = app(MetricRegistry::class);
    $registry->counter('test', [])->increment();

    Artisan::call('monitoring:push');
    $output = Artisan::output();

    expect($output)->toContain('Pushgateway is disabled');
});

it('does not reset metrics on dry-run', function () {
    $registry = app(MetricRegistry::class);
    $counter = $registry->counter('requests_total', []);
    $gauge = $registry->gauge('queue_depth', []);
    $histogram = $registry->histogram('duration_ms', [], [10, 50]);

    $counter->incrementBy(10);
    $gauge->set(42);
    $histogram->observe(25);

    Artisan::call('monitoring:push', ['--dry-run' => true]);

    expect($counter->getValue())->toEqual(10);
    expect($gauge->getValue())->toEqual(42);
    expect($histogram->getCount())->toEqual(1);
});
