<?php

namespace ModusDigital\LaravelMonitoring\Tracing;

enum SpanStatus: int
{
    case UNSET = 0;
    case OK    = 1;
    case ERROR = 2;
}
