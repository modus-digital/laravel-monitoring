<?php

namespace ModusDigital\LaravelMonitoring\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use ModusDigital\LaravelMonitoring\Context\RequestContext;
use ModusDigital\LaravelMonitoring\Contracts\MetricExporterContract;
use ModusDigital\LaravelMonitoring\Contracts\TracerContract;
use ModusDigital\LaravelMonitoring\Metrics\MetricRegistry;
use ModusDigital\LaravelMonitoring\Tracing\Span;
use ModusDigital\LaravelMonitoring\Tracing\SpanKind;
use ModusDigital\LaravelMonitoring\Tracing\SpanStatus;
use Symfony\Component\HttpFoundation\Response;

class StartRequestTrace
{
    private ?Span $rootSpan = null;

    private bool $sampled = true;

    private bool $finalized = false;

    private int $startTime = 0;

    public function __construct(
        private readonly TracerContract $tracer,
        private readonly MetricRegistry $registry,
        private readonly MetricExporterContract $exporter,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->startTime = hrtime(true);

        if ($this->isExcluded($request)) {
            return $next($request);
        }

        $this->sampled = $this->shouldSample($request);

        if ($this->sampled) {
            $traceparent = $this->parseTraceparent($this->getTraceparentHeader($request));

            $this->rootSpan = $this->tracer->startSpan(
                name: 'http.request',
                attributes: [
                    'http.method' => $request->method(),
                    'http.route' => $request->route()?->getName() ?? $request->path(),
                ],
                kind: SpanKind::SERVER,
                traceId: $traceparent['traceId'] ?? null,
                parentSpanId: $traceparent['parentSpanId'] ?? null,
            );

            $ctx = new RequestContext(
                traceId: $this->rootSpan->traceId,
                spanId: $this->rootSpan->spanId,
                requestId: $this->getRequestId($request),
            );
            $ctx->route = $request->route()?->getName() ?? $request->path();
            $ctx->method = $request->method();

            app()->instance(RequestContext::class, $ctx);
        }

        $response = $next($request);

        $this->finalize($request, $response);

        return $response;
    }

    public function terminate(Request $request, Response $response): void
    {
        $this->finalize($request, $response);
    }

    private function finalize(Request $request, Response $response): void
    {
        if ($this->finalized) {
            return;
        }

        $this->finalized = true;

        $statusCode = $response->getStatusCode();

        // Always record metrics (even when unsampled)
        $route = $request->route()?->getName() ?? $request->path();
        $durationMs = (hrtime(true) - $this->startTime) / 1_000_000;

        $this->registry->counter('http_requests_total', [
            'method' => $request->method(),
            'route' => $route,
            'status_group' => intdiv($statusCode, 100).'xx',
        ])->increment();

        $this->registry->histogram('http_request_duration_ms', [
            'method' => $request->method(),
            'route' => $route,
        ])->observe($durationMs);

        // Only record span data if sampling was active
        if ($this->rootSpan !== null) {
            $this->rootSpan->setAttribute('http.status_code', $statusCode);
            $this->rootSpan->setAttribute('http.status_group', intdiv($statusCode, 100).'xx');

            if ($statusCode >= 500) {
                $this->rootSpan->setStatus(SpanStatus::ERROR);
            }

            $this->rootSpan->end();
            $this->tracer->flush();
        }

        // Flush metrics inline
        $metrics = $this->registry->all();
        if ($metrics !== []) {
            $this->exporter->export($metrics);
            $this->registry->reset();
        }
    }

    private function isExcluded(Request $request): bool
    {
        /** @var list<string> $excludes */
        $excludes = config('monitoring.middleware.exclude', []);

        foreach ($excludes as $pattern) {
            $routeName = $request->route()?->getName();
            if ($routeName !== null && str_contains($routeName, $pattern)) {
                return true;
            }
            if (str_contains($request->path(), $pattern)) {
                return true;
            }
        }

        return false;
    }

    private function shouldSample(Request $request): bool
    {
        $traceparent = $this->getTraceparentHeader($request);
        if ($traceparent !== null) {
            $parts = explode('-', $traceparent);
            if (isset($parts[3])) {
                return (bool) ((int) hexdec($parts[3]) & 0x01);
            }
        }

        $rate = (float) config('monitoring.traces.sample_rate', 1.0);

        if ($rate >= 1.0) {
            return true;
        }

        if ($rate <= 0.0) {
            return false;
        }

        return (mt_rand() / mt_getrandmax()) < $rate;
    }

    /** @return array{traceId: ?string, parentSpanId: ?string, traceFlags: int}|null */
    private function parseTraceparent(?string $header): ?array
    {
        if ($header === null) {
            return null;
        }

        $parts = explode('-', $header);
        if (count($parts) !== 4) {
            return null;
        }

        return [
            'traceId' => $parts[1],
            'parentSpanId' => $parts[2],
            'traceFlags' => (int) hexdec($parts[3]),
        ];
    }

    private function getTraceparentHeader(Request $request): ?string
    {
        return $request->header('traceparent');
    }

    private function getRequestId(Request $request): string
    {
        return $request->header('X-Request-ID') ?? bin2hex(random_bytes(8));
    }
}
