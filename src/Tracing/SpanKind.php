<?php

namespace ModusDigital\LaravelMonitoring\Tracing;

enum SpanKind: int
{
    case INTERNAL = 1;
    case SERVER = 2;
    case CLIENT = 3;
    case PRODUCER = 4;
    case CONSUMER = 5;
}
