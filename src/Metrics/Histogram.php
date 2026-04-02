<?php

namespace ModusDigital\LaravelMonitoring\Metrics;

class Histogram extends Metric
{
    public const DEFAULT_BUCKETS = [5, 10, 25, 50, 100, 250, 500, 1000, 2500, 5000, 10000];

    protected array $buckets;

    public function __construct(string $name, array $labels = [], ?array $buckets = null)
    {
        parent::__construct($name, $labels);

        $this->buckets = $buckets ?? self::DEFAULT_BUCKETS;
        sort($this->buckets);
    }

    public function getType(): string
    {
        return 'histogram';
    }

    public function observe(int|float $value): void
    {
        foreach ($this->buckets as $bound) {
            if ($value <= $bound) {
                $this->cache()->increment($this->cacheKey("bucket:{$bound}"));
            }
        }

        // +Inf always incremented
        $this->cache()->increment($this->cacheKey('bucket:+Inf'));

        // Sum: store as integer (value * 100) for atomicity, divide on read
        $this->cache()->increment($this->cacheKey('sum'), (int) round($value * 100));
    }

    public function getBuckets(): array
    {
        $result = [];

        foreach ($this->buckets as $bound) {
            $result[$bound] = (int) ($this->cache()->get($this->cacheKey("bucket:{$bound}")) ?? 0);
        }

        $result['+Inf'] = (int) ($this->cache()->get($this->cacheKey('bucket:+Inf')) ?? 0);

        return $result;
    }

    public function getSum(): int|float
    {
        $raw = (int) ($this->cache()->get($this->cacheKey('sum')) ?? 0);

        return $raw / 100;
    }

    public function getCount(): int
    {
        return (int) ($this->cache()->get($this->cacheKey('bucket:+Inf')) ?? 0);
    }

    public function getBucketBoundaries(): array
    {
        return $this->buckets;
    }

    public function reset(): void
    {
        foreach ($this->buckets as $bound) {
            $this->cache()->forget($this->cacheKey("bucket:{$bound}"));
        }

        $this->cache()->forget($this->cacheKey('bucket:+Inf'));
        $this->cache()->forget($this->cacheKey('sum'));
    }
}
