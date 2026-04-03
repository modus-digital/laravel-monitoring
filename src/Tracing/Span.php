<?php

namespace ModusDigital\LaravelMonitoring\Tracing;

class Span
{
    public readonly string $traceId;

    public readonly string $spanId;

    public readonly ?string $parentSpanId;

    public readonly int $traceFlags;

    public readonly SpanKind $kind;

    public readonly string $name;

    public readonly int $startTimeNano;

    public ?int $endTimeNano = null;

    /** @var array<string, mixed> */
    private array $attributes = [];

    /** @var list<array{name: string, attributes: array<string, mixed>, timeNano: int}> */
    private array $events = [];

    private SpanStatus $status = SpanStatus::UNSET;

    public function __construct(
        string $name,
        ?string $traceId = null,
        ?string $parentSpanId = null,
        int $traceFlags = 1,
        SpanKind $kind = SpanKind::INTERNAL,
    ) {
        $this->name = $name;
        $this->traceId = $traceId ?? bin2hex(random_bytes(16));
        $this->spanId = bin2hex(random_bytes(8));
        $this->parentSpanId = $parentSpanId;
        $this->traceFlags = $traceFlags;
        $this->kind = $kind;
        $this->startTimeNano = self::nowUnixNano();
    }

    public function setAttribute(string $key, mixed $value): self
    {
        $this->attributes[$key] = $value;

        return $this;
    }

    /** @return array<string, mixed> */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    /** @param array<string, mixed> $attributes */
    public function addEvent(string $name, array $attributes = []): self
    {
        $this->events[] = [
            'name' => $name,
            'attributes' => $attributes,
            'timeNano' => self::nowUnixNano(),
        ];

        return $this;
    }

    /** @return list<array{name: string, attributes: array<string, mixed>, timeNano: int}> */
    public function getEvents(): array
    {
        return $this->events;
    }

    public function setStatus(SpanStatus $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getStatus(): SpanStatus
    {
        return $this->status;
    }

    public function end(): void
    {
        if ($this->endTimeNano === null) {
            $this->endTimeNano = self::nowUnixNano();
        }
    }

    public function child(string $name, SpanKind $kind = SpanKind::INTERNAL): self
    {
        return new self(
            name: $name,
            traceId: $this->traceId,
            parentSpanId: $this->spanId,
            traceFlags: $this->traceFlags,
            kind: $kind,
        );
    }

    /** @return array<string, mixed> */
    public function toOtlp(): array
    {
        return [
            'traceId' => $this->traceId,
            'spanId' => $this->spanId,
            'parentSpanId' => $this->parentSpanId ?? '',
            'name' => $this->name,
            'kind' => $this->kind->value,
            'startTimeUnixNano' => (string) $this->startTimeNano,
            'endTimeUnixNano' => (string) ($this->endTimeNano ?? self::nowUnixNano()),
            'attributes' => array_map(
                fn (string $key, mixed $value) => [
                    'key' => $key,
                    'value' => self::encodeAttributeValue($value),
                ],
                array_keys($this->attributes),
                array_values($this->attributes),
            ),
            'events' => array_map(
                fn (array $event) => [
                    'timeUnixNano' => (string) $event['timeNano'],
                    'name' => $event['name'],
                    'attributes' => array_map(
                        fn (string $k, mixed $v) => ['key' => $k, 'value' => self::encodeAttributeValue($v)],
                        array_keys($event['attributes']),
                        array_values($event['attributes']),
                    ),
                ],
                $this->events,
            ),
            'status' => ['code' => $this->status->value],
            'traceState' => '',
            'flags' => $this->traceFlags,
        ];
    }

    private static function nowUnixNano(): int
    {
        return (int) (microtime(true) * 1_000_000_000);
    }

    /** @return array{stringValue?: string, intValue?: string, doubleValue?: float, boolValue?: bool} */
    private static function encodeAttributeValue(mixed $value): array
    {
        return match (true) {
            is_int($value) => ['intValue' => (string) $value],
            is_float($value) => ['doubleValue' => $value],
            is_bool($value) => ['boolValue' => $value],
            default => ['stringValue' => (string) $value],
        };
    }
}
