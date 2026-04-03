<?php

namespace ModusDigital\LaravelMonitoring\Facades;

use Closure;
use Illuminate\Support\Facades\Facade;
use ModusDigital\LaravelMonitoring\Contracts\MetricExporterContract;
use ModusDigital\LaravelMonitoring\Contracts\TracerContract;
use ModusDigital\LaravelMonitoring\Metrics\Counter;
use ModusDigital\LaravelMonitoring\Metrics\Gauge;
use ModusDigital\LaravelMonitoring\Metrics\Histogram;
use ModusDigital\LaravelMonitoring\Metrics\MetricRegistry;
use ModusDigital\LaravelMonitoring\Tracing\Span;
use ModusDigital\LaravelMonitoring\Tracing\SpanKind;
use ModusDigital\LaravelMonitoring\Tracing\SpanStatus;

/**
 * @method static Counter counter(string $name, array<string, string> $labels = [])
 * @method static Gauge gauge(string $name, array<string, string> $labels = [])
 * @method static Histogram histogram(string $name, array<string, string> $labels = [], ?array<int> $buckets = null)
 *
 * @see MetricRegistry
 */
class Monitoring extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return MetricRegistry::class;
    }

    public static function span(string $name, Closure $callback, SpanKind $kind = SpanKind::INTERNAL): mixed
    {
        $tracer = app(TracerContract::class);
        $span = $tracer->startSpan($name, kind: $kind);

        try {
            $result = $callback();
            $span->end();

            return $result;
        } catch (\Throwable $e) {
            $span->addEvent('exception', [
                'exception.type' => get_class($e),
                'exception.message' => $e->getMessage(),
            ]);
            $span->setStatus(SpanStatus::ERROR);
            $span->end();

            throw $e;
        }
    }

    /** @param array<string, mixed> $attributes */
    public static function startSpan(string $name, array $attributes = [], SpanKind $kind = SpanKind::INTERNAL): Span
    {
        return app(TracerContract::class)->startSpan($name, $attributes, $kind);
    }

    public static function flush(): void
    {
        $tracer = app(TracerContract::class);
        $tracer->flush();

        $registry = app(MetricRegistry::class);
        $exporter = app(MetricExporterContract::class);
        $metrics = $registry->all();
        if ($metrics !== []) {
            $exporter->export($metrics);
            $registry->reset();
        }
    }
}
