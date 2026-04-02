<?php

namespace ModusDigital\LaravelMonitoring\Metrics;

use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

class MetricRegistry
{
    /** @var array<string, Metric> In-memory cache of metric instances for this request */
    private array $instances = [];

    /** @param array<string, string> $labels */
    public function counter(string $name, array $labels = []): Counter
    {
        $counter = new Counter($name, $labels);
        $key = $this->instanceKey($counter);

        if (! isset($this->instances[$key])) {
            $this->instances[$key] = $counter;
            $this->register($counter);
        }

        /** @var Counter */
        return $this->instances[$key];
    }

    /** @param array<string, string> $labels */
    public function gauge(string $name, array $labels = []): Gauge
    {
        $gauge = new Gauge($name, $labels);
        $key = $this->instanceKey($gauge);

        if (! isset($this->instances[$key])) {
            $this->instances[$key] = $gauge;
            $this->register($gauge);
        }

        /** @var Gauge */
        return $this->instances[$key];
    }

    /**
     * @param  array<string, string>  $labels
     * @param  array<int>|null  $buckets
     */
    public function histogram(string $name, array $labels = [], ?array $buckets = null): Histogram
    {
        $histogram = new Histogram($name, $labels, $buckets);
        $key = $this->instanceKey($histogram);

        if (! isset($this->instances[$key])) {
            $this->instances[$key] = $histogram;
            $this->register($histogram);
        }

        /** @var Histogram */
        return $this->instances[$key];
    }

    /**
     * @return array<array{type: string, name: string, labels: array<string, string>, buckets?: array<int>}>
     */
    public function all(): array
    {
        $index = $this->cache()->get($this->indexKey()) ?? [];
        $entries = [];

        foreach ($index as $regKey) {
            $entry = $this->cache()->get($regKey);
            if ($entry !== null) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    public function cleanStale(): void
    {
        $index = $this->cache()->get($this->indexKey()) ?? [];
        $cleaned = [];

        foreach ($index as $regKey) {
            $entry = $this->cache()->get($regKey);
            if ($entry === null) {
                continue;
            }

            $metric = $this->reconstruct($entry);

            if ($metric !== null && $this->hasData($metric)) {
                $cleaned[] = $regKey;
            } else {
                $this->cache()->forget($regKey);
            }
        }

        $this->cache()->put($this->indexKey(), $cleaned);
    }

    private function register(Metric $metric): void
    {
        $regKey = $this->registrationKey($metric);

        if ($this->cache()->has($regKey)) {
            return;
        }

        $entry = [
            'type' => $metric->getType(),
            'name' => $metric->getName(),
            'labels' => $metric->getLabels(),
        ];

        if ($metric instanceof Histogram) {
            $entry['buckets'] = $metric->getBucketBoundaries();
        }

        $this->cache()->put($regKey, $entry);
        $this->updateIndex($regKey);
    }

    /** @param array{type: string, name: string, labels: array<string, string>, buckets?: array<int>} $entry */
    private function reconstruct(array $entry): ?Metric
    {
        return match ($entry['type']) {
            'counter' => new Counter($entry['name'], $entry['labels']),
            'gauge' => new Gauge($entry['name'], $entry['labels']),
            'histogram' => new Histogram($entry['name'], $entry['labels'], $entry['buckets'] ?? null),
            default => null,
        };
    }

    private function hasData(Metric $metric): bool
    {
        return match (true) {
            $metric instanceof Counter => $metric->getValue() > 0,
            $metric instanceof Gauge => true, // Gauges represent current state — never stale
            $metric instanceof Histogram => $metric->getCount() > 0,
            default => false,
        };
    }

    private function updateIndex(string $regKey): void
    {
        $callback = function () use ($regKey) {
            $index = $this->cache()->get($this->indexKey()) ?? [];

            if (! in_array($regKey, $index, true)) {
                $index[] = $regKey;
                $this->cache()->put($this->indexKey(), $index);
            }
        };

        try {
            $store = $this->cache()->getStore();

            if ($store instanceof LockProvider) {
                $store->lock($this->indexKey().':lock', 5)->get($callback);
            } else {
                $callback();
            }
        } catch (\Throwable) {
            $callback();
        }
    }

    private function instanceKey(Metric $metric): string
    {
        return "{$metric->getType()}:{$metric->getName()}:{$metric->getLabelsHash()}";
    }

    private function registrationKey(Metric $metric): string
    {
        $prefix = config('monitoring.cache.key_prefix', 'monitoring');

        return "{$prefix}:reg:{$metric->getType()}:{$metric->getName()}:{$metric->getLabelsHash()}";
    }

    private function indexKey(): string
    {
        $prefix = config('monitoring.cache.key_prefix', 'monitoring');

        return "{$prefix}:registry_index";
    }

    private function cache(): Repository
    {
        $store = config('monitoring.cache.store');

        return Cache::store($store);
    }
}
