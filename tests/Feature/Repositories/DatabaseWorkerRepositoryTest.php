<?php

declare(strict_types=1);

use Cbox\LaravelQueueMetrics\Repositories\DatabaseWorkerRepository;
use Cbox\LaravelQueueMetrics\Support\DatabaseMetricsStore;

beforeEach(function () {
    config()->set('queue-metrics.storage.connection', null);

    $createTables = include __DIR__.'/../../../database/migrations/2024_01_01_000001_create_queue_metrics_storage_tables.php';
    $createTables->up();

    $addSetExpiry = include __DIR__.'/../../../database/migrations/2024_01_01_000002_add_expires_at_to_queue_metrics_sets.php';
    $addSetExpiry->up();

    $this->store = new DatabaseMetricsStore;
    $this->repo = new DatabaseWorkerRepository($this->store);
});

test('register and retrieve worker', function () {
    $this->repo->registerWorker(123, 'prod-01', 'redis', 'default', now());

    $stats = $this->repo->getWorkerStats(123, 'prod-01');

    expect($stats)->not->toBeNull();
    expect($stats->pid)->toBe(123);
    expect($stats->hostname)->toBe('prod-01');
    expect($stats->connection)->toBe('redis');
    expect($stats->queue)->toBe('default');
    expect($stats->status)->toBe('idle');
    expect($stats->jobsProcessed)->toBe(0);
});

test('unregister removes worker', function () {
    $this->repo->registerWorker(123, 'prod-01', 'redis', 'default', now());

    $this->repo->unregisterWorker(123, 'prod-01');

    expect($this->repo->getWorkerStats(123, 'prod-01'))->toBeNull();
});

test('update worker activity changes status and metadata', function () {
    $this->repo->registerWorker(123, 'prod-01', 'redis', 'default', now());

    $this->repo->updateWorkerActivity(123, 'prod-01', 'busy', 'App\\Jobs\\SendEmail', 5, 25.0);

    $stats = $this->repo->getWorkerStats(123, 'prod-01');
    expect($stats)->not->toBeNull();
    expect($stats->status)->toBe('busy');
    expect($stats->currentJob)->toBe('App\\Jobs\\SendEmail');
    expect($stats->jobsProcessed)->toBe(5);
    expect($stats->idlePercentage)->toBe(25.0);
});

test('getActiveWorkers returns all workers when no filters', function () {
    $this->repo->registerWorker(1, 'host', 'redis', 'default', now());
    $this->repo->registerWorker(2, 'host', 'redis', 'emails', now());

    $workers = $this->repo->getActiveWorkers();

    expect($workers)->toHaveCount(2);
});

test('getActiveWorkers filters by connection and queue', function () {
    $this->repo->registerWorker(1, 'host', 'redis', 'default', now());
    $this->repo->registerWorker(2, 'host', 'redis', 'emails', now());
    $this->repo->registerWorker(3, 'host', 'sqs', 'default', now());

    $workers = $this->repo->getActiveWorkers('redis', 'default');

    expect($workers)->toHaveCount(1);
    expect($workers[0]->pid)->toBe(1);
});

test('getActiveWorkers filters by connection only', function () {
    $this->repo->registerWorker(1, 'host', 'redis', 'default', now());
    $this->repo->registerWorker(2, 'host', 'redis', 'emails', now());
    $this->repo->registerWorker(3, 'host', 'sqs', 'default', now());

    $workers = $this->repo->getActiveWorkers('redis');

    expect($workers)->toHaveCount(2);
});

test('countActiveWorkers returns count', function () {
    $this->repo->registerWorker(1, 'host', 'redis', 'default', now());
    $this->repo->registerWorker(2, 'host', 'redis', 'default', now());

    expect($this->repo->countActiveWorkers('redis', 'default'))->toBe(2);
});

test('updateWorkerActivity refreshes active worker membership TTL', function () {
    config()->set('queue-metrics.storage.ttl.raw', 60);

    $this->travelTo(now());
    $this->repo->registerWorker(1, 'host', 'redis', 'default', now());

    $this->travel(30)->seconds();
    $this->repo->updateWorkerActivity(1, 'host', 'busy');

    $this->travel(35)->seconds();

    expect($this->repo->getActiveWorkers())->toHaveCount(1);

    $this->travelBack();
});

test('cleanupStaleWorkers removes old workers', function () {
    $this->repo->registerWorker(1, 'host', 'redis', 'default', now()->subMinutes(5));
    $this->repo->registerWorker(2, 'host', 'redis', 'default', now());

    $removed = $this->repo->cleanupStaleWorkers(60);

    expect($removed)->toBe(1);
    expect($this->repo->getWorkerStats(1, 'host'))->toBeNull();
    expect($this->repo->getWorkerStats(2, 'host'))->not->toBeNull();
});

test('cleanupStaleWorkers returns zero when no stale workers', function () {
    $this->repo->registerWorker(1, 'host', 'redis', 'default', now());

    $removed = $this->repo->cleanupStaleWorkers(60);

    expect($removed)->toBe(0);
});

test('getWorkerStats returns null for non-existent worker', function () {
    expect($this->repo->getWorkerStats(999, 'unknown-host'))->toBeNull();
});
