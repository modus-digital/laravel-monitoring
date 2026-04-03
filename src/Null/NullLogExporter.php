<?php

namespace ModusDigital\LaravelMonitoring\Null;

use ModusDigital\LaravelMonitoring\Contracts\LogExporterContract;

class NullLogExporter implements LogExporterContract
{
    /** @param list<array<string, mixed>> $logRecords */
    public function export(array $logRecords): void
    {
        // no-op
    }
}
