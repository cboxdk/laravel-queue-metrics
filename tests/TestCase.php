<?php

namespace Cbox\LaravelQueueMetrics\Tests;

use Cbox\LaravelQueueMetrics\LaravelQueueMetricsServiceProvider;
use Illuminate\Database\Eloquent\Factories\Factory;
use Orchestra\Testbench\TestCase as Orchestra;
use Spatie\Prometheus\PrometheusServiceProvider;

class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        Factory::guessFactoryNamesUsing(
            fn (string $modelName) => 'Cbox\\LaravelQueueMetrics\\Database\\Factories\\'.class_basename($modelName).'Factory'
        );
    }

    protected function getPackageProviders($app)
    {
        return [
            LaravelQueueMetricsServiceProvider::class,
            PrometheusServiceProvider::class,
        ];
    }

    public function getEnvironmentSetUp($app)
    {
        config()->set('database.default', 'testing');

        // Disable queue metrics during tests to avoid Redis connection attempts
        config()->set('queue-metrics.enabled', false);

        $this->configureRedis($app);
    }

    /**
     * Configure the `default` Redis connection.
     *
     * When REDIS_CLUSTER_HOSTS_AND_PORTS is set the connection is backed
     * by a real Redis Cluster, otherwise a single node. The same redis
     * test suite therefore runs against both modes depending on the CI job's environment.
     */
    private function configureRedis($app): void
    {
        $clusterHosts = getenv('REDIS_CLUSTER_HOSTS_AND_PORTS');

        if (is_string($clusterHosts) && $clusterHosts !== '') {
            config()->set('database.redis', [
                'client' => 'phpredis',
                'options' => [
                    'cluster' => 'redis',
                    'prefix' => 'lqm_test_',
                ],
                'clusters' => [
                    'default' => array_map(
                        static fn (string $hostAndPort): array => [
                            'host' => explode(':', $hostAndPort)[0],
                            'port' => (int) explode(':', $hostAndPort)[1],
                        ],
                        explode(',', $clusterHosts),
                    ),
                ],
            ]);

            return;
        }

        $host = getenv('REDIS_HOST') ?: '127.0.0.1';
        $port = getenv('REDIS_PORT') ?: '6379';

        config()->set('database.redis', [
            'client' => 'phpredis',
            'options' => [
                'prefix' => 'lqm_test_',
            ],
            'default' => [
                'host' => $host,
                'port' => (int) $port,
                'database' => 0,
                'timeout' => 1.0,
            ],
        ]);
    }
}
