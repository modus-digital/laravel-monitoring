<?php

use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Http;
use ModusDigital\LaravelMonitoring\Contracts\TracerContract;
use ModusDigital\LaravelMonitoring\Listeners\TraceQueueJobs;
use ModusDigital\LaravelMonitoring\Otlp\OtlpTracer;
use ModusDigital\LaravelMonitoring\Otlp\OtlpTransport;
use ModusDigital\LaravelMonitoring\Tracing\SpanKind;
use ModusDigital\LaravelMonitoring\Tracing\SpanStatus;

beforeEach(function () {
    Http::fake(['*' => Http::response('', 200)]);
    config()->set('monitoring.enabled', true);
    config()->set('monitoring.traces.enabled', true);
    config()->set('monitoring.auto_instrumentation.queue', true);
    config()->set('monitoring.otlp.endpoint', 'http://alloy:4318');
    config()->set('monitoring.service.name', 'test-app');
    config()->set('monitoring.service.environment', 'testing');
    config()->set('monitoring.service.instance_id', 'http://localhost');
});

function createMockJob(string $class = 'App\\Jobs\\SendEmail', string $queue = 'default', string $connection = 'redis', int $attempts = 1): object
{
    $job = Mockery::mock(Job::class);
    $job->shouldReceive('resolveName')->andReturn($class);
    $job->shouldReceive('getQueue')->andReturn($queue);
    $job->shouldReceive('getConnectionName')->andReturn($connection);
    $job->shouldReceive('attempts')->andReturn($attempts);

    return $job;
}

it('creates a root span for a queue job and flushes on completion', function () {
    $tracer = new OtlpTracer(new OtlpTransport);
    $this->app->instance(TracerContract::class, $tracer);

    $job = createMockJob();

    $listener = new TraceQueueJobs($tracer);
    $listener->handleJobProcessing(new JobProcessing('redis', $job));
    $listener->handleJobProcessed(new JobProcessed('redis', $job));

    Http::assertSent(function ($request) {
        $spans = $request->data()['resourceSpans'][0]['scopeSpans'][0]['spans'] ?? [];
        $jobSpan = $spans[0] ?? null;

        if (! $jobSpan) {
            return false;
        }

        $attrs = collect($jobSpan['attributes'])->pluck('value', 'key');

        return $jobSpan['name'] === 'queue.job'
            && $jobSpan['kind'] === SpanKind::CONSUMER->value
            && ($attrs['job.class']['stringValue'] ?? null) === 'App\\Jobs\\SendEmail'
            && ($attrs['job.queue']['stringValue'] ?? null) === 'default'
            && $jobSpan['status']['code'] === SpanStatus::UNSET->value;
    });
});

it('records exception and ERROR status on job failure', function () {
    $tracer = new OtlpTracer(new OtlpTransport);
    $this->app->instance(TracerContract::class, $tracer);

    $job = createMockJob();
    $exception = new RuntimeException('Job failed');

    $listener = new TraceQueueJobs($tracer);
    $listener->handleJobProcessing(new JobProcessing('redis', $job));
    $listener->handleJobFailed(new JobFailed('redis', $job, $exception));

    Http::assertSent(function ($request) {
        $spans = $request->data()['resourceSpans'][0]['scopeSpans'][0]['spans'] ?? [];
        $jobSpan = $spans[0] ?? null;

        if (! $jobSpan) {
            return false;
        }

        $hasExceptionEvent = collect($jobSpan['events'])->contains(fn ($e) => $e['name'] === 'exception');

        return $jobSpan['status']['code'] === SpanStatus::ERROR->value && $hasExceptionEvent;
    });
});

it('creates independent root spans for consecutive jobs (daemon mode)', function () {
    $tracer = new OtlpTracer(new OtlpTransport);
    $this->app->instance(TracerContract::class, $tracer);

    $listener = new TraceQueueJobs($tracer);

    $job1 = createMockJob('App\\Jobs\\Job1');
    $listener->handleJobProcessing(new JobProcessing('redis', $job1));
    $listener->handleJobProcessed(new JobProcessed('redis', $job1));

    $job2 = createMockJob('App\\Jobs\\Job2');
    $listener->handleJobProcessing(new JobProcessing('redis', $job2));
    $listener->handleJobProcessed(new JobProcessed('redis', $job2));

    Http::assertSentCount(2);
});

it('is a no-op when config toggle is off', function () {
    config()->set('monitoring.auto_instrumentation.queue', false);

    $tracer = new OtlpTracer(new OtlpTransport);
    $this->app->instance(TracerContract::class, $tracer);

    $job = createMockJob();

    $listener = new TraceQueueJobs($tracer);
    $listener->handleJobProcessing(new JobProcessing('redis', $job));
    $listener->handleJobProcessed(new JobProcessed('redis', $job));

    Http::assertNothingSent();
});
