<?php

namespace ModusDigital\LaravelMonitoring\Commands;

use Illuminate\Console\Command;

class LaravelMonitoringCommand extends Command
{
    public $signature = 'laravel-monitoring';

    public $description = 'My command';

    public function handle(): int
    {
        $this->comment('All done');

        return self::SUCCESS;
    }
}
