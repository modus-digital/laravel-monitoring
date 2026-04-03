<?php

namespace ModusDigital\LaravelMonitoring\Context;

class RequestContext
{
    public ?string $route = null;

    public ?string $method = null;

    public ?int $userId = null;

    public function __construct(
        public readonly string $traceId,
        public readonly string $spanId,
        public readonly string $requestId,
    ) {}
}
