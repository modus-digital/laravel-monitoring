<?php

namespace ModusDigital\LaravelMonitoring\Null;

use ModusDigital\LaravelMonitoring\Contracts\TracerContract;
use ModusDigital\LaravelMonitoring\Tracing\Span;
use ModusDigital\LaravelMonitoring\Tracing\SpanKind;

class NullTracer implements TracerContract
{
    /** @param array<string, mixed> $attributes */
    public function startSpan(
        string $name,
        array $attributes = [],
        SpanKind $kind = SpanKind::INTERNAL,
        ?string $traceId = null,
        ?string $parentSpanId = null,
    ): Span {
        return new Span($name, kind: $kind);
    }

    public function activeSpan(): ?Span
    {
        return null;
    }

    public function flush(): void
    {
        // no-op
    }
}
