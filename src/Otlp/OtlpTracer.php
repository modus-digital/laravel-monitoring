<?php

namespace ModusDigital\LaravelMonitoring\Otlp;

use ModusDigital\LaravelMonitoring\Contracts\TracerContract;
use ModusDigital\LaravelMonitoring\Tracing\Span;
use ModusDigital\LaravelMonitoring\Tracing\SpanKind;

class OtlpTracer implements TracerContract
{
    /** @var list<Span> */
    private array $spanStack = [];

    public function __construct(
        private readonly OtlpTransport $transport,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function startSpan(
        string $name,
        array $attributes = [],
        SpanKind $kind = SpanKind::INTERNAL,
        ?string $traceId = null,
        ?string $parentSpanId = null,
        ?int $startTimeNano = null,
    ): Span {
        $activeSpan = $this->activeSpan();

        $span = new Span(
            name: $name,
            traceId: $traceId ?? $activeSpan?->traceId,
            parentSpanId: $parentSpanId ?? $activeSpan?->spanId,
            kind: $kind,
            startTimeNano: $startTimeNano,
        );

        foreach ($attributes as $key => $value) {
            $span->setAttribute($key, $value);
        }

        $this->spanStack[] = $span;

        return $span;
    }

    public function activeSpan(): ?Span
    {
        // Return last unended span in the stack
        for ($i = count($this->spanStack) - 1; $i >= 0; $i--) {
            if ($this->spanStack[$i]->endTimeNano === null) {
                return $this->spanStack[$i];
            }
        }

        return null;
    }

    public function flush(): void
    {
        // Collect all spans (ended or not — force-end any still open)
        $spansToExport = [];
        foreach ($this->spanStack as $span) {
            $span->end();
            $spansToExport[] = $span->toOtlp();
        }

        if ($spansToExport === []) {
            return;
        }

        $this->transport->send('/v1/traces', [
            'resourceSpans' => [
                [
                    'resource' => ResourceAttributes::build(),
                    'scopeSpans' => [
                        [
                            'scope' => ['name' => 'laravel-monitoring'],
                            'spans' => $spansToExport,
                        ],
                    ],
                ],
            ],
        ]);

        $this->spanStack = [];
    }
}
