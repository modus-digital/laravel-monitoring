<?php

namespace ModusDigital\LaravelMonitoring\Logging;

use ModusDigital\LaravelMonitoring\Context\RequestContext;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

class MonitoringLogProcessor implements ProcessorInterface
{
    public function __invoke(LogRecord $record): LogRecord
    {
        $ctx = $this->resolveContext();

        if ($ctx === null) {
            return $record;
        }

        return $record->with(extra: array_merge($record->extra, array_filter([
            'trace_id' => $ctx->traceId,
            'span_id' => $ctx->spanId,
            'request_id' => $ctx->requestId,
            'route' => $ctx->route,
            'method' => $ctx->method,
            'user_id' => $ctx->userId,
        ], fn (mixed $v) => $v !== null)));
    }

    private function resolveContext(): ?RequestContext
    {
        try {
            return app(RequestContext::class);
        } catch (\Throwable) {
            return null;
        }
    }
}
