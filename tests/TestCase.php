<?php

namespace PHPeek\LaravelQueueMetrics\Tests;

use Illuminate\Database\Eloquent\Factories\Factory;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPeek\LaravelQueueMetrics\LaravelQueueMetricsServiceProvider;

class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        Factory::guessFactoryNamesUsing(
            fn (string $modelName) => 'PHPeek\\LaravelQueueMetrics\\Database\\Factories\\'.class_basename($modelName).'Factory'
        );
    }

    protected function getPackageProviders($app)
    {
        return [
            LaravelQueueMetricsServiceProvider::class,
            \Spatie\Prometheus\PrometheusServiceProvider::class,
        ];
    }

    public function getEnvironmentSetUp($app)
    {
        config()->set('database.default', 'testing');

        // Disable queue metrics during tests to avoid Redis connection attempts
        config()->set('queue-metrics.enabled', false);

        /*
         foreach (\Illuminate\Support\Facades\File::allFiles(__DIR__ . '/../database/migrations') as $migration) {
            (include $migration->getRealPath())->up();
         }
         */
    }
}
