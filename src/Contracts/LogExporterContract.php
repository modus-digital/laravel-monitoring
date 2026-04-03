<?php

namespace ModusDigital\LaravelMonitoring\Contracts;

interface LogExporterContract
{
    /** @param list<array<string, mixed>> $logRecords */
    public function export(array $logRecords): void;
}
