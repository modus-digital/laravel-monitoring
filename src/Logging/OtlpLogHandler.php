<?php

namespace ModusDigital\LaravelMonitoring\Logging;

use ModusDigital\LaravelMonitoring\Contracts\LogExporterContract;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;

class OtlpLogHandler extends AbstractProcessingHandler
{
    /** @var list<array<string, mixed>> */
    private array $buffer = [];

    /** @var array<int, int> */
    private static array $severityMap = [
        100 => 5,  // Debug
        200 => 9,  // Info
        250 => 13, // Notice
        300 => 13, // Warning
        400 => 17, // Error
        500 => 21, // Critical
        550 => 21, // Alert
        600 => 24, // Emergency
    ];

    public function __construct(
        private readonly LogExporterContract $exporter,
        Level $level = Level::Debug,
        bool $bubble = true,
    ) {
        parent::__construct($level, $bubble);
    }

    protected function write(LogRecord $record): void
    {
        $extra = $record->extra;

        $logRecord = [
            'timeUnixNano' => (string) ($record->datetime->format('U') * 1_000_000_000 + (int) $record->datetime->format('u') * 1_000),
            'severityNumber' => self::$severityMap[$record->level->value] ?? 9,
            'severityText' => strtoupper($record->level->name),
            'body' => $record->message,
            'attributes' => array_merge(
                $record->context,
                $extra,
            ),
        ];

        if (isset($extra['trace_id'])) {
            $logRecord['traceId'] = $extra['trace_id'];
        }

        if (isset($extra['span_id'])) {
            $logRecord['spanId'] = $extra['span_id'];
        }

        $this->buffer[] = $logRecord;
    }

    public function close(): void
    {
        if ($this->buffer !== []) {
            $this->exporter->export($this->buffer);
            $this->buffer = [];
        }

        parent::close();
    }

    public function flush(): void
    {
        if ($this->buffer !== []) {
            $this->exporter->export($this->buffer);
            $this->buffer = [];
        }
    }
}
