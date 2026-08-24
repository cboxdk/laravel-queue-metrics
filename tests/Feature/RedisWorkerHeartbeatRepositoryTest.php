<?php

declare(strict_types=1);

use Carbon\Carbon;
use Cbox\LaravelQueueMetrics\Enums\WorkerState;
use Cbox\LaravelQueueMetrics\Repositories\RedisWorkerHeartbeatRepository;
use Cbox\LaravelQueueMetrics\Support\RedisMetricsStore;
use Illuminate\Support\Facades\Redis;

/**
 * Runs against whichever Redis the environment provides: a single node
 * normally, or a real cluster when REDIS_CLUSTER_HOSTS_AND_PORTS is set (see
 * the cluster CI job). The heartbeat write path previously ran a multi-key
 * Lua script whose keys hash to different slots, so every heartbeat failed
 * with CROSSSLOT on a multi-shard cluster - the same assertions must hold in
 * both modes.
 */
beforeEach(function () {
    if (! getenv('REDIS_AVAILABLE')) {
        test()->markTestSkipped('Requires Redis - run with redis group');
    }

    config()->set('queue-metrics.enabled', true);
    config()->set('queue-metrics.storage.driver', 'redis');
    config()->set('queue-metrics.storage.connection', 'default');

    Redis::connection('default')->flushdb();

    $this->repository = new RedisWorkerHeartbeatRepository(app(RedisMetricsStore::class));
});

afterEach(function () {
    Carbon::setTestNow();
});

function heartbeat(RedisWorkerHeartbeatRepository $repository, string $workerId, WorkerState $state, string|int|null $jobId = null): void
{
    $repository->recordHeartbeat(
        workerId: $workerId,
        connection: 'redis',
        queue: 'default',
        state: $state,
        currentJobId: $jobId,
        currentJobClass: $jobId !== null ? 'App\\Jobs\\Example' : null,
        pid: 1234,
        hostname: 'test-host',
        memoryUsageMb: 64.5,
        cpuUsagePercent: 12.5,
    );
}

it('records a heartbeat and reads the worker back', function () {
    heartbeat($this->repository, 'worker-1', WorkerState::IDLE);

    $worker = $this->repository->getWorker('worker-1');

    expect($worker)->not->toBeNull()
        ->and($worker->workerId)->toBe('worker-1')
        ->and($worker->connection)->toBe('redis')
        ->and($worker->queue)->toBe('default')
        ->and($worker->state)->toBe(WorkerState::IDLE)
        ->and($worker->hostname)->toBe('test-host');
})->group('redis');

it('lists the worker as active through the index', function () {
    heartbeat($this->repository, 'worker-2', WorkerState::BUSY, 'job-1');

    $active = $this->repository->getActiveWorkers('redis', 'default');

    expect($active)->toHaveCount(1)
        ->and($active->first()->workerId)->toBe('worker-2');
})->group('redis');

it('increments jobs_processed on a busy to idle transition', function () {
    heartbeat($this->repository, 'worker-3', WorkerState::BUSY, 'job-1');
    heartbeat($this->repository, 'worker-3', WorkerState::IDLE);

    $worker = $this->repository->getWorker('worker-3');

    expect($worker->jobsProcessed)->toBe(1);
})->group('redis');

it('marks a stale worker as crashed via the index score', function () {
    Carbon::setTestNow(Carbon::now()->subSeconds(120));
    heartbeat($this->repository, 'worker-4', WorkerState::IDLE);
    Carbon::setTestNow();

    $marked = $this->repository->detectStaledWorkers(60);

    expect($marked)->toBe(1)
        ->and($this->repository->getWorker('worker-4')->state)->toBe(WorkerState::CRASHED);
})->group('redis');

it('removes a worker from both the hash and the index', function () {
    heartbeat($this->repository, 'worker-5', WorkerState::IDLE);

    $this->repository->removeWorker('worker-5');

    expect($this->repository->getWorker('worker-5'))->toBeNull()
        ->and($this->repository->getActiveWorkers())->toHaveCount(0);
})->group('redis');
