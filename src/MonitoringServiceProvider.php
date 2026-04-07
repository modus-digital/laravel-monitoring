<?php

namespace ModusDigital\LaravelMonitoring;

use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\KeyForgotten;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;
use ModusDigital\LaravelMonitoring\Contracts\LogExporterContract;
use ModusDigital\LaravelMonitoring\Contracts\MetricExporterContract;
use ModusDigital\LaravelMonitoring\Contracts\TracerContract;
use ModusDigital\LaravelMonitoring\Listeners\TraceCacheOperations;
use ModusDigital\LaravelMonitoring\Listeners\TraceDbQueries;
use ModusDigital\LaravelMonitoring\Listeners\TraceHttpClient;
use ModusDigital\LaravelMonitoring\Listeners\TraceQueueJobs;
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

        // Auto-flush telemetry on queue job completion
        if (config('monitoring.enabled')) {
            Queue::after(function () {
                $this->flushTelemetry();
            });

            Queue::failing(function () {
                $this->flushTelemetry();
            });
        }

        $this->registerAutoInstrumentation();
    }

    private function registerAutoInstrumentation(): void
    {
        if (! config('monitoring.enabled') || ! config('monitoring.traces.enabled')) {
            return;
        }

        if (config('monitoring.auto_instrumentation.db', true)) {
            $dbListener = $this->app->make(TraceDbQueries::class);
            Event::listen(QueryExecuted::class, fn (QueryExecuted $event) => $dbListener->handle($event));
        }

        if (config('monitoring.auto_instrumentation.http_client', true)) {
            $httpClient = $this->app->make(TraceHttpClient::class);
            Event::listen(RequestSending::class, function (RequestSending $event) use ($httpClient) {
                $httpClient->handleRequestSending($event);
            });
            Event::listen(ResponseReceived::class, function (ResponseReceived $event) use ($httpClient) {
                $httpClient->handleResponseReceived($event);
            });
        }

        if (config('monitoring.auto_instrumentation.cache', true)) {
            $cacheListener = $this->app->make(TraceCacheOperations::class);
            Event::listen(CacheHit::class, fn (CacheHit $e) => $cacheListener->handleCacheHit($e));
            Event::listen(CacheMissed::class, fn (CacheMissed $e) => $cacheListener->handleCacheMissed($e));
            Event::listen(KeyWritten::class, fn (KeyWritten $e) => $cacheListener->handleKeyWritten($e));
            Event::listen(KeyForgotten::class, fn (KeyForgotten $e) => $cacheListener->handleKeyForgotten($e));
        }

        if (config('monitoring.auto_instrumentation.queue', true)) {
            $queueListener = $this->app->make(TraceQueueJobs::class);
            Event::listen(JobProcessing::class, fn (JobProcessing $e) => $queueListener->handleJobProcessing($e));
            Event::listen(JobProcessed::class, fn (JobProcessed $e) => $queueListener->handleJobProcessed($e));
            Event::listen(JobFailed::class, fn (JobFailed $e) => $queueListener->handleJobFailed($e));
        }
    }

    private function flushTelemetry(): void
    {
        if (config('monitoring.traces.enabled')) {
            $this->app->make(TracerContract::class)->flush();
        }

        if (config('monitoring.metrics.enabled')) {
            $registry = $this->app->make(MetricRegistry::class);
            $exporter = $this->app->make(MetricExporterContract::class);

            $metrics = $registry->all();
            if ($metrics !== []) {
                $exporter->export($metrics);
                $registry->reset();
            }
        }
    }
}
