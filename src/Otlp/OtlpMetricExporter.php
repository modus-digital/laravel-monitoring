<?php

namespace ModusDigital\LaravelMonitoring\Otlp;

use ModusDigital\LaravelMonitoring\Contracts\MetricExporterContract;
use ModusDigital\LaravelMonitoring\Metrics\Counter;
use ModusDigital\LaravelMonitoring\Metrics\Gauge;
use ModusDigital\LaravelMonitoring\Metrics\Histogram;
use ModusDigital\LaravelMonitoring\Metrics\Metric;

class OtlpMetricExporter implements MetricExporterContract
{
    public function __construct(
        private readonly OtlpTransport $transport,
    ) {}

    /** @param list<Metric> $metrics */
    public function export(array $metrics): void
    {
        if ($metrics === []) {
            return;
        }

        $now = (string) hrtime(true);

        $this->transport->send('/v1/metrics', [
            'resourceMetrics' => [
                [
                    'resource' => ResourceAttributes::build(),
                    'scopeMetrics' => [
                        [
                            'scope' => ['name' => 'laravel-monitoring'],
                            'metrics' => array_map(
                                fn (Metric $metric) => $this->formatMetric($metric, $now),
                                $metrics,
                            ),
                        ],
                    ],
                ],
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function formatMetric(Metric $metric, string $now): array
    {
        return match (true) {
            $metric instanceof Counter => $this->formatCounter($metric, $now),
            $metric instanceof Gauge => $this->formatGauge($metric, $now),
            $metric instanceof Histogram => $this->formatHistogram($metric, $now),
            default => throw new \InvalidArgumentException('Unknown metric type: '.get_class($metric)),
        };
    }

    /** @return array<string, mixed> */
    private function formatCounter(Counter $counter, string $now): array
    {
        return [
            'name' => $counter->getName(),
            'sum' => [
                'dataPoints' => [
                    [
                        'startTimeUnixNano' => $now,
                        'timeUnixNano' => $now,
                        'asDouble' => $counter->getValue(),
                        'attributes' => self::formatLabels($counter->getLabels()),
                    ],
                ],
                'aggregationTemporality' => 1, // DELTA
                'isMonotonic' => true,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function formatGauge(Gauge $gauge, string $now): array
    {
        return [
            'name' => $gauge->getName(),
            'gauge' => [
                'dataPoints' => [
                    [
                        'timeUnixNano' => $now,
                        'asDouble' => $gauge->getValue(),
                        'attributes' => self::formatLabels($gauge->getLabels()),
                    ],
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function formatHistogram(Histogram $histogram, string $now): array
    {
        $bucketCounts = [];
        foreach ($histogram->getBucketBoundaries() as $bound) {
            $bucketCounts[] = (string) $histogram->getBuckets()[$bound];
        }
        $bucketCounts[] = (string) $histogram->getBuckets()['+Inf'];

        return [
            'name' => $histogram->getName(),
            'histogram' => [
                'dataPoints' => [
                    [
                        'startTimeUnixNano' => $now,
                        'timeUnixNano' => $now,
                        'count' => (string) $histogram->getCount(),
                        'sum' => $histogram->getSum(),
                        'bucketCounts' => $bucketCounts,
                        'explicitBounds' => $histogram->getBucketBoundaries(),
                        'attributes' => self::formatLabels($histogram->getLabels()),
                    ],
                ],
                'aggregationTemporality' => 1, // DELTA
            ],
        ];
    }

    /**
     * @param array<string, string> $labels
     * @return list<array{key: string, value: array{stringValue: string}}>
     */
    private static function formatLabels(array $labels): array
    {
        return array_map(
            fn (string $key, string $value) => [
                'key' => $key,
                'value' => ['stringValue' => $value],
            ],
            array_keys($labels),
            array_values($labels),
        );
    }
}
