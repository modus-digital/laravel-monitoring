<?php

namespace ModusDigital\LaravelMonitoring\Metrics;

class MetricRegistry
{
    /** @var array<string, Metric> */
    private array $metrics = [];

    /** @param array<string, string> $labels */
    public function counter(string $name, array $labels = []): Counter
    {
        $key = $this->key('counter', $name, $labels);

        if (! isset($this->metrics[$key])) {
            $this->metrics[$key] = new Counter($name, $labels);
        }

        /** @var Counter */
        return $this->metrics[$key];
    }

    /** @param array<string, string> $labels */
    public function gauge(string $name, array $labels = []): Gauge
    {
        $key = $this->key('gauge', $name, $labels);

        if (! isset($this->metrics[$key])) {
            $this->metrics[$key] = new Gauge($name, $labels);
        }

        /** @var Gauge */
        return $this->metrics[$key];
    }

    /**
     * @param array<string, string> $labels
     * @param list<int>|null $buckets
     */
    public function histogram(string $name, array $labels = [], ?array $buckets = null): Histogram
    {
        $key = $this->key('histogram', $name, $labels);

        if (! isset($this->metrics[$key])) {
            $this->metrics[$key] = new Histogram($name, $labels, $buckets);
        }

        /** @var Histogram */
        return $this->metrics[$key];
    }

    /** @return list<Metric> */
    public function all(): array
    {
        return array_values($this->metrics);
    }

    public function reset(): void
    {
        foreach ($this->metrics as $metric) {
            $metric->reset();
        }
    }

    /** @param array<string, string> $labels */
    private function key(string $type, string $name, array $labels): string
    {
        ksort($labels);

        return $type.':'.$name.':'.md5(serialize($labels));
    }
}
