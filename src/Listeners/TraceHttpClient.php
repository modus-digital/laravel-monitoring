<?php

namespace ModusDigital\LaravelMonitoring\Listeners;

use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Http\Client\Request;
use ModusDigital\LaravelMonitoring\Contracts\TracerContract;
use ModusDigital\LaravelMonitoring\Tracing\SpanKind;
use ModusDigital\LaravelMonitoring\Tracing\SpanStatus;
use WeakMap;

class TraceHttpClient
{
    /** @var WeakMap<Request, int> */
    private WeakMap $startTimes;

    public function __construct(
        private readonly TracerContract $tracer,
    ) {
        /** @var WeakMap<Request, int> $startTimes */
        $startTimes = new WeakMap;
        $this->startTimes = $startTimes;
    }

    public function handleRequestSending(RequestSending $event): void
    {
        if (! config('monitoring.auto_instrumentation.http_client', true)) {
            return;
        }

        $this->startTimes[$event->request] = (int) (microtime(true) * 1_000_000_000);
    }

    public function handleResponseReceived(ResponseReceived $event): void
    {
        if (! config('monitoring.auto_instrumentation.http_client', true)) {
            return;
        }

        if ($this->tracer->activeSpan() === null) {
            return;
        }

        $startTimeNano = isset($this->startTimes[$event->request])
            ? $this->startTimes[$event->request]
            : null;

        unset($this->startTimes[$event->request]);

        $statusCode = $event->response->status();

        $span = $this->tracer->startSpan(
            name: 'http.client',
            attributes: [
                'http.method' => $event->request->method(),
                'http.url' => $event->request->url(),
                'http.status_code' => $statusCode,
            ],
            kind: SpanKind::CLIENT,
            startTimeNano: $startTimeNano,
        );

        if ($statusCode >= 500) {
            $span->setStatus(SpanStatus::ERROR);
        }

        $span->end();
    }
}
