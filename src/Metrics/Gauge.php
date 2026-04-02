<?php

namespace ModusDigital\LaravelMonitoring\Metrics;

class Gauge extends Metric
{
    public function getType(): string
    {
        return 'gauge';
    }

    public function set(int|float $value): void
    {
        $this->cache()->put($this->cacheKey(), (int) round($value * 100), $this->ttl());
    }

    public function increment(): void
    {
        $this->incrementBy(1);
    }

    public function decrement(): void
    {
        $this->decrementBy(1);
    }

    public function incrementBy(float $value): void
    {
        $key = $this->cacheKey();

        $this->cache()->add($key, 0, $this->ttl());
        $this->cache()->increment($key, (int) round($value * 100));
    }

    public function decrementBy(float $value): void
    {
        $key = $this->cacheKey();

        $this->cache()->add($key, 0, $this->ttl());
        $this->cache()->decrement($key, (int) round($value * 100));
    }

    public function getValue(): float
    {
        $raw = $this->cache()->get($this->cacheKey()) ?? 0;

        return $raw / 100;
    }

    public function reset(): void
    {
        $this->cache()->forget($this->cacheKey());
    }
}
