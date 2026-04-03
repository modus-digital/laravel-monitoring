<?php

namespace ModusDigital\LaravelMonitoring\Metrics;

class Histogram extends Metric
{
    private const DEFAULT_BUCKETS = [5, 10, 25, 50, 100, 250, 500, 1000, 2500, 5000, 10000];

    /** @var array<int|string, int> */
    private array $buckets = [];

    private float $sum = 0.0;

    /** @var list<int> */
    private array $boundaries;

    /**
     * @param  array<string, string>  $labels
     * @param  list<int>|null  $buckets
     */
    public function __construct(string $name, array $labels = [], ?array $buckets = null)
    {
        parent::__construct($name, $labels);
        $this->boundaries = $buckets ?? self::DEFAULT_BUCKETS;
        $this->initBuckets();
    }

    public function observe(float $value): void
    {
        $this->sum += $value;

        foreach ($this->boundaries as $bound) {
            if ($value <= $bound) {
                $this->buckets[$bound]++;
            }
        }

        $this->buckets['+Inf']++;
    }

    /** @return array<int|string, int> */
    public function getBuckets(): array
    {
        return $this->buckets;
    }

    /** @return list<int> */
    public function getBucketBoundaries(): array
    {
        return $this->boundaries;
    }

    public function getSum(): float
    {
        return $this->sum;
    }

    public function getCount(): int
    {
        return $this->buckets['+Inf'];
    }

    public function reset(): void
    {
        $this->sum = 0.0;
        $this->initBuckets();
    }

    public function getType(): string
    {
        return 'histogram';
    }

    private function initBuckets(): void
    {
        $this->buckets = [];
        foreach ($this->boundaries as $bound) {
            $this->buckets[$bound] = 0;
        }
        $this->buckets['+Inf'] = 0;
    }
}
