<?php

namespace ModusDigital\LaravelMonitoring\Metrics;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

abstract class Metric
{
    protected string $name;

    /** @var array<string, string> */
    protected array $labels;

    protected string $labelsHash;

    /** @param array<string, string> $labels */
    public function __construct(string $name, array $labels = [])
    {
        $this->name = $name;

        ksort($labels);
        $this->labels = $labels;
        $this->labelsHash = md5(serialize($labels));
    }

    public function getName(): string
    {
        return $this->name;
    }

    /** @return array<string, string> */
    public function getLabels(): array
    {
        return $this->labels;
    }

    public function getLabelsHash(): string
    {
        return $this->labelsHash;
    }

    abstract public function getType(): string;

    protected function cacheKey(string $suffix = ''): string
    {
        $prefix = config('monitoring.cache.key_prefix', 'monitoring');
        $key = "{$prefix}:{$this->getType()}:{$this->name}:{$this->labelsHash}";

        return $suffix ? "{$key}:{$suffix}" : $key;
    }

    protected function cache(): Repository
    {
        $store = config('monitoring.cache.store');

        return Cache::store($store);
    }

    protected function ttl(): int
    {
        return config('monitoring.cache.ttl', 3600);
    }
}
