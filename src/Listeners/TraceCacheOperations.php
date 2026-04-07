<?php

namespace ModusDigital\LaravelMonitoring\Listeners;

use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\KeyForgotten;
use Illuminate\Cache\Events\KeyWritten;
use ModusDigital\LaravelMonitoring\Contracts\TracerContract;
use ModusDigital\LaravelMonitoring\Tracing\SpanKind;

class TraceCacheOperations
{
    public function __construct(
        private readonly TracerContract $tracer,
    ) {}

    public function handleCacheHit(CacheHit $event): void
    {
        $this->recordSpan('cache.hit', $event->storeName, $event->key, ['cache.hit' => true]);
    }

    public function handleCacheMissed(CacheMissed $event): void
    {
        $this->recordSpan('cache.miss', $event->storeName, $event->key, ['cache.hit' => false]);
    }

    public function handleKeyWritten(KeyWritten $event): void
    {
        $this->recordSpan('cache.write', $event->storeName, $event->key);
    }

    public function handleKeyForgotten(KeyForgotten $event): void
    {
        $this->recordSpan('cache.forget', $event->storeName, $event->key);
    }

    /** @param array<string, mixed> $extraAttributes */
    private function recordSpan(string $name, ?string $store, string $key, array $extraAttributes = []): void
    {
        if (! config('monitoring.auto_instrumentation.cache', true)) {
            return;
        }

        if ($this->tracer->activeSpan() === null) {
            return;
        }

        $span = $this->tracer->startSpan(
            name: $name,
            attributes: array_merge([
                'cache.key' => $key,
                'cache.store' => $store ?? 'default',
            ], $extraAttributes),
            kind: SpanKind::INTERNAL,
        );

        $span->end();
    }
}
