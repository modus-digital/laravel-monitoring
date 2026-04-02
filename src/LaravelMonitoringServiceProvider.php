<?php

namespace ModusDigital\LaravelMonitoring;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use ModusDigital\LaravelMonitoring\Commands\PushMetrics;
use ModusDigital\LaravelMonitoring\Logging\LokiHandler;
use ModusDigital\LaravelMonitoring\Metrics\MetricRegistry;

class LaravelMonitoringServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/monitoring.php',
            'monitoring'
        );

        $this->app->singleton(
            MetricRegistry::class
        );
    }

    public function boot(): void
    {
        // ── Publish config ────────────────────────────────────
        $this->publishes([
            __DIR__.'/../config/monitoring.php' => config_path('monitoring.php'),
        ], 'monitoring-config');

        // ── Register artisan command ──────────────────────────
        if ($this->app->runningInConsole()) {
            $this->commands([PushMetrics::class]);
        }

        // ── Schedule metrics push ────────────────────────────
        if (config('monitoring.pushgateway.enabled', true) && config('monitoring.pushgateway.schedule')) {
            $this->app->afterResolving(Schedule::class, function (Schedule $schedule): void {
                $method = config('monitoring.pushgateway.schedule', 'everyMinute');
                $schedule->command('monitoring:push')->{$method}();
            });
        }

        // ── Register Loki as a named log channel ──────────────
        // Apps can add 'loki' to their stack channel without any extra code.
        $this->app->make('config')->set('logging.channels.loki', [
            'driver' => 'custom',
            'via' => LokiHandler::class,
            'level' => config('monitoring.loki.level', 'debug'),
        ]);
    }
}
