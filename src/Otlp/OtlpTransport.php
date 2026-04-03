<?php

namespace ModusDigital\LaravelMonitoring\Otlp;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class OtlpTransport
{
    /**
     * @param  string  $path  OTLP endpoint path (e.g., /v1/traces)
     * @param  array<string, mixed>  $payload  JSON payload
     */
    public function send(string $path, array $payload): void
    {
        $endpoint = rtrim((string) config('monitoring.otlp.endpoint'), '/');
        $timeout = (int) config('monitoring.otlp.timeout', 3);

        try {
            Http::timeout($timeout)
                ->withHeaders($this->parseHeaders())
                ->asJson()
                ->post($endpoint.$path, $payload);
        } catch (ConnectionException) {
            // Fire and forget — telemetry loss is acceptable
        } catch (\Throwable $e) {
            // Use error_log to avoid recursive logging if monitoring is the default log channel
            error_log('OTLP transport error: '.$e->getMessage());
        }
    }

    /** @return array<string, string> */
    private function parseHeaders(): array
    {
        $raw = (string) config('monitoring.otlp.headers', '');
        if ($raw === '') {
            return [];
        }

        $headers = [];
        foreach (explode(',', $raw) as $pair) {
            $parts = explode('=', $pair, 2);
            if (count($parts) === 2) {
                $headers[trim($parts[0])] = trim($parts[1]);
            }
        }

        return $headers;
    }
}
