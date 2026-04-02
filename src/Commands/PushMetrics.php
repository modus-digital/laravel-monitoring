<?php

namespace ModusDigital\LaravelMonitoring\Commands;

use Illuminate\Console\Command;
use ModusDigital\LaravelMonitoring\Metrics\Counter;
use ModusDigital\LaravelMonitoring\Metrics\Gauge;
use ModusDigital\LaravelMonitoring\Metrics\Histogram;
use ModusDigital\LaravelMonitoring\Metrics\MetricRegistry;

class PushMetrics extends Command
{
    public $signature = 'monitoring:push {--dry-run : Output metrics without pushing}';

    public $description = 'Push collected metrics to Prometheus Pushgateway';

    public function handle(MetricRegistry $registry): int
    {
        if (! config('monitoring.pushgateway.enabled', true)) {
            $this->info('Pushgateway is disabled.');

            return self::SUCCESS;
        }

        $entries = $registry->all();

        if (empty($entries)) {
            $this->info('No metrics to push.');

            return self::SUCCESS;
        }

        $output = $this->formatPrometheus($entries);

        $this->line($output);

        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }

        $pushed = $this->pushToGateway($output);

        if (! $pushed) {
            $this->warn('Failed to push metrics to Pushgateway.');

            return self::FAILURE;
        }

        $this->resetMetrics($entries);
        $registry->cleanStale();

        $this->info('Metrics pushed successfully.');

        return self::SUCCESS;
    }

    private function formatPrometheus(array $entries): string
    {
        $lines = [];
        $seen = [];

        foreach ($entries as $entry) {
            $name = $entry['name'];
            $labels = $entry['labels'];
            $type = $entry['type'];

            $metric = $this->reconstruct($entry);

            if ($metric === null) {
                continue;
            }

            if (! isset($seen[$name])) {
                $lines[] = "# HELP {$name} {$name}";
                $lines[] = "# TYPE {$name} {$type}";
                $seen[$name] = true;
            }

            $labelStr = $this->formatLabels($labels);

            if ($metric instanceof Counter) {
                $value = $metric->getValue();
                $lines[] = "{$name}{$labelStr} {$value}";
            } elseif ($metric instanceof Gauge) {
                $value = $metric->getValue();
                $lines[] = "{$name}{$labelStr} {$value}";
            } elseif ($metric instanceof Histogram) {
                $buckets = $metric->getBuckets();

                foreach ($buckets as $le => $count) {
                    $bucketLabels = array_merge($labels, ['le' => (string) $le]);
                    $bucketLabelStr = $this->formatLabels($bucketLabels);
                    $lines[] = "{$name}_bucket{$bucketLabelStr} {$count}";
                }

                $sumLabels = $this->formatLabels($labels);
                $lines[] = "{$name}_sum{$sumLabels} {$metric->getSum()}";
                $lines[] = "{$name}_count{$sumLabels} {$metric->getCount()}";
            }
        }

        return implode("\n", $lines) . "\n";
    }

    private function formatLabels(array $labels): string
    {
        if (empty($labels)) {
            return '';
        }

        $pairs = [];

        foreach ($labels as $key => $value) {
            $escaped = str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], (string) $value);
            $pairs[] = "{$key}=\"{$escaped}\"";
        }

        return '{' . implode(',', $pairs) . '}';
    }

    private function reconstruct(array $entry): Counter|Gauge|Histogram|null
    {
        return match ($entry['type']) {
            'counter'   => new Counter($entry['name'], $entry['labels']),
            'gauge'     => new Gauge($entry['name'], $entry['labels']),
            'histogram' => new Histogram($entry['name'], $entry['labels'], $entry['buckets'] ?? null),
            default     => null,
        };
    }

    private function pushToGateway(string $payload): bool
    {
        $url = rtrim(config('monitoring.pushgateway.url', ''), '/');
        $jobName = config('monitoring.pushgateway.job_name') ?? config('app.name', 'laravel');
        $auth = config('monitoring.pushgateway.auth', '');

        $endpoint = "{$url}/metrics/job/{$jobName}";

        $ch = curl_init($endpoint);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => ['Content-Type: text/plain'],
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        if ($auth) {
            curl_setopt($ch, CURLOPT_USERPWD, $auth);
        }

        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            $this->error("cURL error: {$error}");

            return false;
        }

        return $httpCode >= 200 && $httpCode < 300;
    }

    private function resetMetrics(array $entries): void
    {
        foreach ($entries as $entry) {
            $metric = $this->reconstruct($entry);

            if ($metric === null) {
                continue;
            }

            if ($metric instanceof Counter || $metric instanceof Histogram) {
                $metric->reset();
            }
        }
    }
}
