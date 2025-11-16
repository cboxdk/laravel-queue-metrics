<?php

namespace PHPeek\LaravelQueueMetrics;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use PHPeek\LaravelQueueMetrics\Commands\LaravelQueueMetricsCommand;

class LaravelQueueMetricsServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        /*
         * This class is a Package Service Provider
         *
         * More info: https://github.com/spatie/laravel-package-tools
         */
        $package
            ->name('laravel-queue-metrics')
            ->hasConfigFile()
            ->hasViews()
            ->hasMigration('create_laravel_queue_metrics_table')
            ->hasCommand(LaravelQueueMetricsCommand::class);
    }
}
