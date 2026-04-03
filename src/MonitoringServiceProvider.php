<?php

namespace ModusDigital\LaravelMonitoring;

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;
use ModusDigital\LaravelMonitoring\Contracts\LogExporterContract;
use ModusDigital\LaravelMonitoring\Contracts\MetricExporterContract;
use ModusDigital\LaravelMonitoring\Contracts\TracerContract;
use ModusDigital\LaravelMonitoring\Logging\MonitoringLogProcessor;
use ModusDigital\LaravelMonitoring\Logging\OtlpLogHandler;
use ModusDigital\LaravelMonitoring\Metrics\MetricRegistry;
use ModusDigital\LaravelMonitoring\Null\NullLogExporter;
use ModusDigital\LaravelMonitoring\Null\NullMetricExporter;
use ModusDigital\LaravelMonitoring\Null\NullTracer;
use ModusDigital\LaravelMonitoring\Otlp\OtlpLogExporter;
use ModusDigital\LaravelMonitoring\Otlp\OtlpMetricExporter;
use ModusDigital\LaravelMonitoring\Otlp\OtlpTracer;
use ModusDigital\LaravelMonitoring\Otlp\OtlpTransport;

class MonitoringServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/monitoring.php',
            'monitoring'
        );

        $this->app->singleton(OtlpTransport::class);
        $this->app->singleton(MetricRegistry::class);

        $this->app->singleton(TracerContract::class, function ($app) {
            if (! config('monitoring.enabled') || ! config('monitoring.traces.enabled')) {
                return new NullTracer;
            }

            return new OtlpTracer($app->make(OtlpTransport::class));
        });

        $this->app->singleton(LogExporterContract::class, function ($app) {
            if (! config('monitoring.enabled') || ! config('monitoring.logs.enabled')) {
                return new NullLogExporter;
            }

            return new OtlpLogExporter($app->make(OtlpTransport::class));
        });

        $this->app->singleton(MetricExporterContract::class, function ($app) {
            if (! config('monitoring.enabled') || ! config('monitoring.metrics.enabled')) {
                return new NullMetricExporter;
            }

            return new OtlpMetricExporter($app->make(OtlpTransport::class));
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/monitoring.php' => config_path('monitoring.php'),
        ], 'monitoring-config');

        // Register monitoring log channel
        $this->app->make('config')->set('logging.channels.monitoring', [
            'driver' => 'monolog',
            'handler' => OtlpLogHandler::class,
            'handler_with' => [
                'exporter' => $this->app->make(LogExporterContract::class),
            ],
            'processors' => [MonitoringLogProcessor::class],
        ]);

        // Auto-flush metrics on queue job completion
        if (config('monitoring.enabled') && config('monitoring.metrics.enabled')) {
            Queue::after(function () {
                $this->flushMetrics();
            });

            Queue::failing(function () {
                $this->flushMetrics();
            });
        }
    }

    private function flushMetrics(): void
    {
        $registry = $this->app->make(MetricRegistry::class);
        $exporter = $this->app->make(MetricExporterContract::class);

        $metrics = $registry->all();
        if ($metrics !== []) {
            $exporter->export($metrics);
            $registry->reset();
        }
    }
}
