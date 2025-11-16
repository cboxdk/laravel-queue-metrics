<?php

namespace PHPeek\LaravelQueueMetrics\Commands;

use Illuminate\Console\Command;

class LaravelQueueMetricsCommand extends Command
{
    public $signature = 'laravel-queue-metrics';

    public $description = 'My command';

    public function handle(): int
    {
        $this->comment('All done');

        return self::SUCCESS;
    }
}
