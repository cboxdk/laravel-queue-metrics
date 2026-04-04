<?php

declare(strict_types=1);

use Cbox\LaravelQueueMetrics\Repositories\Contracts\WorkerRepository;

beforeEach(function () {
    $this->workerRepository = Mockery::mock(WorkerRepository::class);
    $this->app->instance(WorkerRepository::class, $this->workerRepository);

    config(['queue-metrics.enabled' => true]);
    config(['queue-metrics.worker_heartbeat.stale_threshold' => 60]);
});

afterEach(function () {
    Mockery::close();
});

it('casts threshold option from string to int', function () {
    $this->workerRepository->shouldReceive('cleanupStaleWorkers')
        ->once()
        ->with(120)
        ->andReturn(0);

    $this->artisan('queue-metrics:cleanup-stale-workers', ['--threshold' => '120'])
        ->assertSuccessful();
});

it('casts config threshold to int', function () {
    config(['queue-metrics.worker_heartbeat.stale_threshold' => '90']);

    $this->workerRepository->shouldReceive('cleanupStaleWorkers')
        ->once()
        ->with(90)
        ->andReturn(0);

    $this->artisan('queue-metrics:cleanup-stale-workers')
        ->assertSuccessful();
});

it('uses default config threshold when no option provided', function () {
    $this->workerRepository->shouldReceive('cleanupStaleWorkers')
        ->once()
        ->with(60)
        ->andReturn(0);

    $this->artisan('queue-metrics:cleanup-stale-workers')
        ->assertSuccessful();
});

it('reports cleaned up stale workers', function () {
    $this->workerRepository->shouldReceive('cleanupStaleWorkers')
        ->once()
        ->with(60)
        ->andReturn(3);

    $this->artisan('queue-metrics:cleanup-stale-workers')
        ->expectsOutputToContain('Cleaned up 3 stale worker(s)')
        ->assertSuccessful();
});

it('skips cleanup in dry-run mode', function () {
    $this->workerRepository->shouldNotReceive('cleanupStaleWorkers');

    $this->artisan('queue-metrics:cleanup-stale-workers', ['--dry-run' => true])
        ->expectsOutputToContain('DRY RUN MODE')
        ->assertSuccessful();
});

it('exits early when metrics are disabled', function () {
    config(['queue-metrics.enabled' => false]);

    $this->workerRepository->shouldNotReceive('cleanupStaleWorkers');

    $this->artisan('queue-metrics:cleanup-stale-workers')
        ->expectsOutputToContain('Queue metrics are disabled')
        ->assertSuccessful();
});
