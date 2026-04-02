<?php

use ModusDigital\LaravelMonitoring\Metrics\MetricRegistry;

if (! function_exists('monitoring')) {
    function monitoring(): MetricRegistry
    {
        return app(MetricRegistry::class);
    }
}
