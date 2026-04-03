<?php

namespace ModusDigital\LaravelMonitoring\Otlp;

class ResourceAttributes
{
    /** @return array{attributes: list<array{key: string, value: array{stringValue: string}}>} */
    public static function build(): array
    {
        return [
            'attributes' => [
                [
                    'key' => 'service.name',
                    'value' => ['stringValue' => (string) (config('monitoring.service.name') ?? config('app.name', 'laravel'))],
                ],
                [
                    'key' => 'deployment.environment',
                    'value' => ['stringValue' => (string) (config('monitoring.service.environment') ?? config('app.env', 'production'))],
                ],
                [
                    'key' => 'service.instance.id',
                    'value' => ['stringValue' => (string) (config('monitoring.service.instance_id') ?? config('app.url', 'http://localhost'))],
                ],
            ],
        ];
    }
}
