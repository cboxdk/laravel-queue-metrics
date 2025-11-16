<?php

declare(strict_types=1);

use PHPeek\LaravelQueueMetrics\Config\QueueMetricsConfig;
use PHPeek\LaravelQueueMetrics\Config\StorageConfig;

beforeEach(function () {
    config()->set('queue-metrics', [
        'enabled' => true,
        'storage' => [
            'driver' => 'redis',
            'connection' => 'default',
            'prefix' => 'queue_metrics',
            'ttl' => [
                'raw' => 3600,
                'aggregated' => 604800,
                'baseline' => 2592000,
            ],
        ],
        'windows' => [
            'short' => 300,
            'medium' => 3600,
            'long' => 86400,
        ],
        'retention' => [
            'job_history' => 604800,
            'worker_history' => 86400,
        ],
        'performance' => [
            'batch_size' => 100,
            'cache_ttl' => 60,
        ],
        'api' => [
            'middleware' => ['api'],
            'prefix' => 'queue-metrics',
        ],
        'prometheus' => [
            'enabled' => true,
            'namespace' => 'laravel_queue',
        ],
        'worker_heartbeat' => [
            'interval' => 30,
            'timeout' => 90,
        ],
    ]);
});

it('creates config from Laravel config', function () {
    $config = QueueMetricsConfig::fromConfig();

    expect($config->enabled)->toBeTrue()
        ->and($config->storage)->toBeInstanceOf(StorageConfig::class)
        ->and($config->windows)->toBe([
            'short' => 300,
            'medium' => 3600,
            'long' => 86400,
        ])
        ->and($config->retention)->toBe([
            'job_history' => 604800,
            'worker_history' => 86400,
        ]);
});

it('has storage config instance', function () {
    $config = QueueMetricsConfig::fromConfig();

    expect($config->storage->driver)->toBe('redis')
        ->and($config->storage->connection)->toBe('default')
        ->and($config->storage->prefix)->toBe('queue_metrics');
});

it('gets prometheus namespace', function () {
    $config = QueueMetricsConfig::fromConfig();

    expect($config->getPrometheusNamespace())->toBe('laravel_queue');
});

it('returns default prometheus namespace when missing', function () {
    config()->set('queue-metrics.prometheus', []);
    $config = QueueMetricsConfig::fromConfig();

    expect($config->getPrometheusNamespace())->toBe('laravel_queue');
});

it('handles missing config with defaults', function () {
    config()->set('queue-metrics', []);
    $config = QueueMetricsConfig::fromConfig();

    expect($config->enabled)->toBeTrue()
        ->and($config->storage)->toBeInstanceOf(StorageConfig::class);
});

it('respects disabled state', function () {
    config()->set('queue-metrics.enabled', false);
    $config = QueueMetricsConfig::fromConfig();

    expect($config->enabled)->toBeFalse();
});

it('is readonly', function () {
    $config = QueueMetricsConfig::fromConfig();

    expect(fn () => $config->enabled = false)
        ->toThrow(Error::class);
});

it('gets api middleware array', function () {
    $config = QueueMetricsConfig::fromConfig();

    expect($config->api['middleware'])->toBe(['api']);
});

it('gets worker heartbeat interval', function () {
    $config = QueueMetricsConfig::fromConfig();

    expect($config->workerHeartbeat['interval'])->toBe(30)
        ->and($config->workerHeartbeat['timeout'])->toBe(90);
});
