<?php

namespace ModusDigital\LaravelMonitoring\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \ModusDigital\LaravelMonitoring\LaravelMonitoring
 */
class LaravelMonitoring extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \ModusDigital\LaravelMonitoring\LaravelMonitoring::class;
    }
}
