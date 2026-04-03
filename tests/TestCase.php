<?php

namespace ModusDigital\LaravelMonitoring\Tests;

use ModusDigital\LaravelMonitoring\MonitoringServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app)
    {
        return [
            MonitoringServiceProvider::class,
        ];
    }
}
