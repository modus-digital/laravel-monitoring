<?php

namespace ModusDigital\LaravelMonitoring;

use Illuminate\Support\ServiceProvider;

class MonitoringServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/monitoring.php',
            'monitoring'
        );
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/monitoring.php' => config_path('monitoring.php'),
        ], 'monitoring-config');
    }
}
