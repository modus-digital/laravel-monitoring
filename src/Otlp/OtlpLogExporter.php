<?php

namespace ModusDigital\LaravelMonitoring\Otlp;

use ModusDigital\LaravelMonitoring\Contracts\LogExporterContract;

class OtlpLogExporter implements LogExporterContract
{
    public function __construct(
        private readonly OtlpTransport $transport,
    ) {}

    /** @param list<array<string, mixed>> $logRecords */
    public function export(array $logRecords): void
    {
        if ($logRecords === []) {
            return;
        }

        $this->transport->send('/v1/logs', [
            'resourceLogs' => [
                [
                    'resource' => ResourceAttributes::build(),
                    'scopeLogs' => [
                        [
                            'scope' => ['name' => 'laravel-monitoring'],
                            'logRecords' => array_map(
                                fn (array $record) => $this->formatLogRecord($record),
                                $logRecords,
                            ),
                        ],
                    ],
                ],
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    private function formatLogRecord(array $record): array
    {
        $formatted = [
            'timeUnixNano' => $record['timeUnixNano'] ?? (string) hrtime(true),
            'severityNumber' => $record['severityNumber'] ?? 9,
            'severityText' => $record['severityText'] ?? 'INFO',
            'body' => ['stringValue' => (string) ($record['body'] ?? '')],
            'attributes' => $this->formatAttributes($record['attributes'] ?? []),
        ];

        if (isset($record['traceId'])) {
            $formatted['traceId'] = $record['traceId'];
        }

        if (isset($record['spanId'])) {
            $formatted['spanId'] = $record['spanId'];
        }

        return $formatted;
    }

    /**
     * @param array<string, mixed> $attributes
     * @return list<array{key: string, value: array<string, mixed>}>
     */
    private function formatAttributes(array $attributes): array
    {
        $result = [];
        foreach ($attributes as $key => $value) {
            $result[] = [
                'key' => (string) $key,
                'value' => match (true) {
                    is_int($value) => ['intValue' => (string) $value],
                    is_float($value) => ['doubleValue' => $value],
                    is_bool($value) => ['boolValue' => $value],
                    default => ['stringValue' => (string) $value],
                },
            ];
        }

        return $result;
    }
}
