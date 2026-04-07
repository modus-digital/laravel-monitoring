<?php

namespace ModusDigital\LaravelMonitoring\Listeners;

use Illuminate\Database\Events\QueryExecuted;
use ModusDigital\LaravelMonitoring\Contracts\TracerContract;
use ModusDigital\LaravelMonitoring\Tracing\SpanKind;

class TraceDbQueries
{
    public function __construct(
        private readonly TracerContract $tracer,
    ) {}

    public function handle(QueryExecuted $event): void
    {
        if (! config('monitoring.auto_instrumentation.db', true)) {
            return;
        }

        if ($this->tracer->activeSpan() === null) {
            return;
        }

        $durationMs = $event->time;
        $startTimeNano = (int) ((microtime(true) - ($durationMs / 1000)) * 1_000_000_000);

        $span = $this->tracer->startSpan(
            name: 'db.query',
            attributes: [
                'db.system' => $event->connection->getDriverName(),
                'db.statement' => $event->sql,
                'db.duration_ms' => $durationMs,
                'db.connection' => $event->connectionName,
            ],
            kind: SpanKind::CLIENT,
            startTimeNano: $startTimeNano,
        );

        $span->end();
    }
}
