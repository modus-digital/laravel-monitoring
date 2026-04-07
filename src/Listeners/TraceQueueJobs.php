<?php

namespace ModusDigital\LaravelMonitoring\Listeners;

use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use ModusDigital\LaravelMonitoring\Contracts\TracerContract;
use ModusDigital\LaravelMonitoring\Tracing\Span;
use ModusDigital\LaravelMonitoring\Tracing\SpanKind;
use ModusDigital\LaravelMonitoring\Tracing\SpanStatus;

class TraceQueueJobs
{
    private ?Span $currentSpan = null;

    public function __construct(
        private readonly TracerContract $tracer,
    ) {}

    public function handleJobProcessing(JobProcessing $event): void
    {
        if (! config('monitoring.auto_instrumentation.queue', true)) {
            return;
        }

        $this->currentSpan = $this->tracer->startSpan(
            name: 'queue.job',
            attributes: [
                'job.class' => $event->job->resolveName(),
                'job.queue' => $event->job->getQueue(),
                'job.connection' => $event->job->getConnectionName(),
                'job.attempt' => $event->job->attempts(),
            ],
            kind: SpanKind::CONSUMER,
        );
    }

    public function handleJobProcessed(JobProcessed $event): void
    {
        if ($this->currentSpan === null) {
            return;
        }

        $this->currentSpan->end();
        $this->tracer->flush();
        $this->currentSpan = null;
    }

    public function handleJobFailed(JobFailed $event): void
    {
        if ($this->currentSpan === null) {
            return;
        }

        if ($event->exception !== null) {
            $this->currentSpan->addEvent('exception', [
                'exception.type' => get_class($event->exception),
                'exception.message' => $event->exception->getMessage(),
                'exception.stacktrace' => $event->exception->getTraceAsString(),
            ]);
        }

        $this->currentSpan->setStatus(SpanStatus::ERROR);
        $this->currentSpan->end();
        $this->tracer->flush();
        $this->currentSpan = null;
    }
}
