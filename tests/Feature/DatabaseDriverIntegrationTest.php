<?php

declare(strict_types=1);

use Cbox\LaravelQueueMetrics\LaravelQueueMetricsServiceProvider;
use Cbox\LaravelQueueMetrics\Repositories\Contracts\BaselineRepository;
use Cbox\LaravelQueueMetrics\Repositories\Contracts\JobMetricsRepository;
use Cbox\LaravelQueueMetrics\Repositories\Contracts\QueueMetricsRepository;
use Cbox\LaravelQueueMetrics\Repositories\Contracts\WorkerHeartbeatRepository;
use Cbox\LaravelQueueMetrics\Repositories\Contracts\WorkerRepository;
use Cbox\LaravelQueueMetrics\Repositories\DatabaseBaselineRepository;
use Cbox\LaravelQueueMetrics\Repositories\DatabaseJobMetricsRepository;
use Cbox\LaravelQueueMetrics\Repositories\DatabaseQueueMetricsRepository;
use Cbox\LaravelQueueMetrics\Repositories\DatabaseWorkerHeartbeatRepository;
use Cbox\LaravelQueueMetrics\Repositories\DatabaseWorkerRepository;
use Cbox\LaravelQueueMetrics\Repositories\RedisWorkerRepository;

test('database driver binds database repositories', function () {
    config()->set('queue-metrics.storage.driver', 'database');
    config()->set('queue-metrics.repositories', [
        JobMetricsRepository::class => null,
        QueueMetricsRepository::class => null,
        WorkerRepository::class => null,
        BaselineRepository::class => null,
        WorkerHeartbeatRepository::class => null,
    ]);

    // Force re-registration
    $provider = new LaravelQueueMetricsServiceProvider(app());
    $provider->packageRegistered();

    expect(app(JobMetricsRepository::class))->toBeInstanceOf(DatabaseJobMetricsRepository::class)
        ->and(app(QueueMetricsRepository::class))->toBeInstanceOf(DatabaseQueueMetricsRepository::class)
        ->and(app(WorkerRepository::class))->toBeInstanceOf(DatabaseWorkerRepository::class)
        ->and(app(BaselineRepository::class))->toBeInstanceOf(DatabaseBaselineRepository::class)
        ->and(app(WorkerHeartbeatRepository::class))->toBeInstanceOf(DatabaseWorkerHeartbeatRepository::class);
});

test('explicit repository override takes precedence over driver', function () {
    config()->set('queue-metrics.storage.driver', 'database');
    config()->set('queue-metrics.repositories', [
        JobMetricsRepository::class => null,
        QueueMetricsRepository::class => null,
        WorkerRepository::class => RedisWorkerRepository::class,
        BaselineRepository::class => null,
        WorkerHeartbeatRepository::class => null,
    ]);

    // Force re-registration
    $provider = new LaravelQueueMetricsServiceProvider(app());
    $provider->packageRegistered();

    expect(app(WorkerRepository::class))->toBeInstanceOf(RedisWorkerRepository::class);
});

test('redis driver binds redis repositories by default', function () {
    config()->set('queue-metrics.storage.driver', 'redis');
    config()->set('queue-metrics.repositories', [
        JobMetricsRepository::class => null,
        WorkerRepository::class => null,
    ]);

    // Force re-registration
    $provider = new LaravelQueueMetricsServiceProvider(app());
    $provider->packageRegistered();

    expect(app(WorkerRepository::class))->toBeInstanceOf(RedisWorkerRepository::class);
});
