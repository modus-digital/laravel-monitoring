<?php

namespace ModusDigital\LaravelMonitoring;

use Illuminate\Support\ServiceProvider;
use ModusDigital\LaravelMonitoring\Metrics\MetricRegistry;

class MonitoringServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/monitoring.php',
            'monitoring'
        );

        $this->app->singleton(MetricRegistry::class);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/monitoring.php' => config_path('monitoring.php'),
        ], 'monitoring-config');
    }
}
