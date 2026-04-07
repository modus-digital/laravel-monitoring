# Commit Plan — Metrics, Auto-Instrumentation & Exception Handling

Commits to make after implementation is complete, in order:

### After Task 1 (Span backdating support)
```bash
git add src/Tracing/Span.php src/Contracts/TracerContract.php src/Otlp/OtlpTracer.php src/Null/NullTracer.php tests/Tracing/SpanTest.php
git commit -m "feat: add startTimeNano parameter to Span for backdating support"
```

### After Task 2 (HTTP metrics in middleware)
```bash
git add src/Http/Middleware/StartRequestTrace.php tests/Middleware/StartRequestTraceTest.php
git commit -m "feat: add HTTP metrics (counter + histogram) to request middleware"
```

### After Task 3 (Config section)
```bash
git add config/monitoring.php
git commit -m "feat: add auto_instrumentation config section"
```

### After Task 4 (DB query listener)
```bash
git add src/Listeners/TraceDbQueries.php tests/Listeners/TraceDbQueriesTest.php
git commit -m "feat: add TraceDbQueries listener for DB query auto-instrumentation"
```

### After Task 5 (HTTP client listener)
```bash
git add src/Listeners/TraceHttpClient.php tests/Listeners/TraceHttpClientTest.php
git commit -m "feat: add TraceHttpClient listener for HTTP client auto-instrumentation"
```

### After Task 6 (Cache listener)
```bash
git add src/Listeners/TraceCacheOperations.php tests/Listeners/TraceCacheOperationsTest.php
git commit -m "feat: add TraceCacheOperations listener for cache auto-instrumentation"
```

### After Task 7 (Queue listener)
```bash
git add src/Listeners/TraceQueueJobs.php tests/Listeners/TraceQueueJobsTest.php
git commit -m "feat: add TraceQueueJobs listener for queue job auto-instrumentation"
```

### After Task 8 (Exception helper)
```bash
git add src/Facades/Monitoring.php tests/Facades/MonitoringTest.php
git commit -m "feat: add reportException() helper and include stacktrace in span exceptions"
```

### After Task 9 (Service provider registration)
```bash
git add src/MonitoringServiceProvider.php tests/MonitoringServiceProviderTest.php
git commit -m "feat: register auto-instrumentation event listeners in service provider"
```

### After Task 10 (Final formatting)
```bash
git add -A
git commit -m "style: apply formatting"
```
