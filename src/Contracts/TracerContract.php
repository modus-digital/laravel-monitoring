<?php

namespace ModusDigital\LaravelMonitoring\Contracts;

use ModusDigital\LaravelMonitoring\Tracing\Span;
use ModusDigital\LaravelMonitoring\Tracing\SpanKind;

interface TracerContract
{
    /** @param array<string, mixed> $attributes */
    public function startSpan(
        string $name,
        array $attributes = [],
        SpanKind $kind = SpanKind::INTERNAL,
        ?string $traceId = null,
        ?string $parentSpanId = null,
        ?int $startTimeNano = null,
    ): Span;

    public function activeSpan(): ?Span;

    public function flush(): void;
}
