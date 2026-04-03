<?php

namespace ModusDigital\LaravelMonitoring\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use ModusDigital\LaravelMonitoring\Context\RequestContext;
use ModusDigital\LaravelMonitoring\Contracts\TracerContract;
use ModusDigital\LaravelMonitoring\Tracing\Span;
use ModusDigital\LaravelMonitoring\Tracing\SpanKind;
use ModusDigital\LaravelMonitoring\Tracing\SpanStatus;
use Symfony\Component\HttpFoundation\Response;

class StartRequestTrace
{
    private ?Span $rootSpan = null;

    private bool $sampled = true;

    public function __construct(
        private readonly TracerContract $tracer,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->isExcluded($request)) {
            return $next($request);
        }

        $this->sampled = $this->shouldSample($request);

        if (! $this->sampled) {
            return $next($request);
        }

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

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        if ($this->rootSpan === null) {
            return;
        }

        $statusCode = $response->getStatusCode();
        $this->rootSpan->setAttribute('http.status_code', $statusCode);
        $this->rootSpan->setAttribute('http.status_group', intdiv($statusCode, 100).'xx');

        if ($statusCode >= 500) {
            $this->rootSpan->setStatus(SpanStatus::ERROR);
        }

        $this->rootSpan->end();
        $this->tracer->flush();
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
        // Respect upstream sampling decision
        $traceparent = $this->getTraceparentHeader($request);
        if ($traceparent !== null) {
            $parts = explode('-', $traceparent);
            if (isset($parts[3]) && ((int) hexdec($parts[3]) & 0x01)) {
                return true;
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
