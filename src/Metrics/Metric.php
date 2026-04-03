<?php

namespace ModusDigital\LaravelMonitoring\Metrics;

abstract class Metric
{
    protected string $name;

    /** @var array<string, string> */
    protected array $labels;

    /** @param array<string, string> $labels */
    public function __construct(string $name, array $labels = [])
    {
        $this->name = $name;
        $this->labels = $labels;
        ksort($this->labels);
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

    abstract public function getType(): string;

    abstract public function reset(): void;
}
