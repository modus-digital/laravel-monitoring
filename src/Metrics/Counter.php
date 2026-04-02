<?php

namespace ModusDigital\LaravelMonitoring\Metrics;

class Counter extends Metric
{
    public function getType(): string
    {
        return 'counter';
    }

    public function increment(): void
    {
        $this->incrementBy(1);
    }

    public function incrementBy(float $value): void
    {
        $key = $this->cacheKey();

        if (! $this->cache()->has($key)) {
            $this->cache()->put($key, 0, $this->ttl());
        }

        // Store as integer (value * 100) for Cache::increment() atomicity
        $this->cache()->increment($key, (int) round($value * 100));
    }

    public function getValue(): float
    {
        $raw = (int) ($this->cache()->get($this->cacheKey()) ?? 0);

        return $raw / 100;
    }

    public function reset(): void
    {
        $this->cache()->forget($this->cacheKey());
    }
}
