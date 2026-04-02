<?php

namespace ModusDigital\LaravelMonitoring;

use Illuminate\Foundation\Http\Kernel;
use Illuminate\Support\ServiceProvider;
use ModusDigital\LaravelMonitoring\Http\Middleware\RecordMetrics;
use ModusDigital\LaravelMonitoring\Commands\PushMetrics;
use ModusDigital\LaravelMonitoring\Logging\LokiHandler;

class LaravelMonitoringServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/monitoring.php',
            'monitoring'
        );

        $this->app->singleton(
            \ModusDigital\LaravelMonitoring\Metrics\MetricRegistry::class
        );
    }

    public function boot(): void
    {
        // ── Publish config ────────────────────────────────────
        $this->publishes([
            __DIR__ . '/../config/monitoring.php' => config_path('monitoring.php'),
        ], 'monitoring-config');

        // ── Auto-register HTTP metrics middleware ─────────────
        if (config('monitoring.middleware.auto_register', true)) {
            $kernel = $this->app->make(Kernel::class);
            $kernel->pushMiddleware(RecordMetrics::class);
        }

        // ── Register artisan command ──────────────────────────
        if ($this->app->runningInConsole()) {
            $this->commands([PushMetrics::class]);
        }

        // ── Register Loki as a named log channel ──────────────
        // Apps can add 'loki' to their stack channel without any extra code.
        $this->app->make('config')->set('logging.channels.loki', [
            'driver' => 'custom',
            'via'    => LokiHandler::class,
            'level'  => config('monitoring.loki.level', 'debug'),
        ]);
    }
}
