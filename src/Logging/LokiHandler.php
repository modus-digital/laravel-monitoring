<?php

namespace ModusDigital\LaravelMonitoring\Logging;

use Illuminate\Support\Facades\Auth;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\LogRecord;
use Monolog\Level;

class LokiHandler extends AbstractProcessingHandler
{
    private string $endpoint;
    private string $auth;
    private array  $labels;

    public function __construct()
    {
        parent::__construct(
            Level::fromName(config('monitoring.loki.level', 'debug')),
            bubble: true
        );

        $this->endpoint = rtrim(config('monitoring.loki.url', ''), '/') . '/loki/api/v1/push';
        $this->auth     = config('monitoring.loki.auth', '');
        $this->labels   = array_merge(
            [
                'app' => config('app.name', 'laravel'),
                'env' => config('app.env', 'production'),
            ],
            config('monitoring.loki.labels', [])
        );
    }

    protected function write(LogRecord $record): void
    {
        if (! config('monitoring.loki.enabled', true) || empty($this->endpoint)) {
            return;
        }

        $payload = $this->buildPayload($record);

        $this->push($payload);
    }

    private function buildPayload(LogRecord $record): string
    {
        $timestampNs = (string)($record->datetime->getTimestamp() * 1_000_000_000);

        $logData = array_filter([
            'message'    => $record->message,
            'context'    => $record->context  ?: null,
            'extra'      => $record->extra     ?: null,
            'route'      => request()->route()?->getName() ?? request()->path(),
            'method'     => request()->method(),
            'user_id'    => Auth::id(),
            'request_id' => request()->header('X-Request-ID'),
        ]);

        $stream = array_merge($this->labels, [
            'level'   => strtolower($record->level->name),
            'channel' => $record->channel,
        ]);

        return json_encode([
            'streams' => [[
                'stream' => $stream,
                'values' => [[$timestampNs, json_encode($logData)]],
            ]],
        ]);
    }

    private function push(string $payload): void
    {
        $ch = curl_init($this->endpoint);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT        => 3,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        if ($this->auth) {
            curl_setopt($ch, CURLOPT_USERPWD, $this->auth);
        }

        curl_exec($ch);
    }
}
