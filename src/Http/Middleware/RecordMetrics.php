<?php

namespace ModusDigital\LaravelMonitoring\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use ModusDigital\LaravelMonitoring\Facades\Monitoring;
use Symfony\Component\HttpFoundation\Response;

class RecordMetrics
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $route = $request->route()?->getName()
            ?? $request->route()?->uri()
            ?? 'unknown';

        if ($this->shouldExclude($route, $request)) {
            return;
        }

        $method = $request->method();
        $statusCode = $response->getStatusCode();
        $statusGroup = intdiv($statusCode, 100).'xx';
        $durationMs = $this->durationMs();

        Monitoring::counter('http_requests_total', [
            'method' => $method,
            'route' => $route,
            'status' => (string) $statusCode,
            'status_group' => $statusGroup,
        ])->increment();

        Monitoring::histogram('http_request_duration_ms', [
            'method' => $method,
            'route' => $route,
        ])->observe($durationMs);

        Monitoring::gauge('http_request_duration_last_ms', [
            'method' => $method,
            'route' => $route,
        ])->set($durationMs);
    }

    private function durationMs(): float
    {
        $start = defined('LARAVEL_START')
            ? LARAVEL_START
            : ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));

        return round((microtime(true) - $start) * 1000, 2);
    }

    private function shouldExclude(string $route, Request $request): bool
    {
        $exclusions = config('monitoring.middleware.exclude', []);

        foreach ($exclusions as $pattern) {
            if (str_contains($route, $pattern) || str_contains($request->path(), $pattern)) {
                return true;
            }
        }

        return false;
    }
}
