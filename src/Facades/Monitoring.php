<?php

namespace ModusDigital\LaravelMonitoring\Facades;

use Illuminate\Support\Facades\Facade;
use ModusDigital\LaravelMonitoring\Metrics\MetricRegistry;

/**
 * @method static \ModusDigital\LaravelMonitoring\Metrics\Counter counter(string $name, array $labels = [])
 * @method static \ModusDigital\LaravelMonitoring\Metrics\Gauge gauge(string $name, array $labels = [])
 * @method static \ModusDigital\LaravelMonitoring\Metrics\Histogram histogram(string $name, array $labels = [], ?array $buckets = null)
 *
 * @see MetricRegistry
 */
class Monitoring extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return MetricRegistry::class;
    }
}
