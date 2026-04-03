<?php

namespace ModusDigital\LaravelMonitoring\Null;

use ModusDigital\LaravelMonitoring\Contracts\MetricExporterContract;
use ModusDigital\LaravelMonitoring\Metrics\Metric;

class NullMetricExporter implements MetricExporterContract
{
    /** @param list<Metric> $metrics */
    public function export(array $metrics): void
    {
        // no-op
    }
}
