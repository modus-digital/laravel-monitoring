<?php

namespace ModusDigital\LaravelMonitoring\Contracts;

use ModusDigital\LaravelMonitoring\Metrics\Metric;

interface MetricExporterContract
{
    /** @param list<Metric> $metrics */
    public function export(array $metrics): void;
}
