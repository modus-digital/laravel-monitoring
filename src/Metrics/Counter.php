<?php

namespace ModusDigital\LaravelMonitoring\Metrics;

class Counter extends Metric
{
    private float $value = 0.0;

    public function increment(): void
    {
        $this->value += 1.0;
    }

    public function incrementBy(float $value): void
    {
        $this->value += $value;
    }

    public function getValue(): float
    {
        return $this->value;
    }

    public function reset(): void
    {
        $this->value = 0.0;
    }

    public function getType(): string
    {
        return 'counter';
    }
}
