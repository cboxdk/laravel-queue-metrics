<?php

declare(strict_types=1);

use Carbon\Carbon;
use Cbox\LaravelQueueMetrics\Enums\WorkerState;
use Cbox\LaravelQueueMetrics\Repositories\DatabaseWorkerHeartbeatRepository;
use Cbox\LaravelQueueMetrics\Support\DatabaseMetricsStore;

beforeEach(function () {
    config()->set('queue-metrics.storage.connection', null);

    $migration = include __DIR__.'/../../../database/migrations/2024_01_01_000001_create_queue_metrics_storage_tables.php';
    $migration->up();

    $this->store = new DatabaseMetricsStore;
    $this->repo = new DatabaseWorkerHeartbeatRepository($this->store);
});

// --- recordHeartbeat ---

test('recordHeartbeat creates new worker entry', function () {
    $this->repo->recordHeartbeat(
        workerId: 'worker-1',
        connection: 'redis',
        queue: 'default',
        state: WorkerState::IDLE,
        currentJobId: null,
        currentJobClass: null,
        pid: 123,
        hostname: 'prod-01',
    );

    $worker = $this->repo->getWorker('worker-1');

    expect($worker)->not->toBeNull();
    expect($worker->workerId)->toBe('worker-1');
    expect($worker->connection)->toBe('redis');
    expect($worker->queue)->toBe('default');
    expect($worker->state)->toBe(WorkerState::IDLE);
    expect($worker->pid)->toBe(123);
    expect($worker->hostname)->toBe('prod-01');
    expect($worker->jobsProcessed)->toBe(0);
});

test('recordHeartbeat updates worker with state transitions', function () {
    $this->repo->recordHeartbeat(
        workerId: 'worker-1',
        connection: 'redis',
        queue: 'default',
        state: WorkerState::IDLE,
        currentJobId: null,
        currentJobClass: null,
        pid: 123,
        hostname: 'prod-01',
    );

    $worker = $this->repo->getWorker('worker-1');
    expect($worker)->not->toBeNull();
    expect($worker->state)->toBe(WorkerState::IDLE);

    // Transition to BUSY
    $this->repo->recordHeartbeat(
        workerId: 'worker-1',
        connection: 'redis',
        queue: 'default',
        state: WorkerState::BUSY,
        currentJobId: 'job-42',
        currentJobClass: 'App\\Jobs\\SendEmail',
        pid: 123,
        hostname: 'prod-01',
    );

    $worker = $this->repo->getWorker('worker-1');
    expect($worker->state)->toBe(WorkerState::BUSY);
    expect($worker->currentJobClass)->toBe('App\\Jobs\\SendEmail');
});

test('recordHeartbeat increments jobs_processed on busy to idle transition', function () {
    Carbon::setTestNow(Carbon::parse('2025-01-01 12:00:00'));

    // Start as BUSY with a job
    $this->repo->recordHeartbeat(
        workerId: 'worker-1',
        connection: 'redis',
        queue: 'default',
        state: WorkerState::BUSY,
        currentJobId: 'job-1',
        currentJobClass: 'App\\Jobs\\ProcessOrder',
        pid: 100,
        hostname: 'prod-01',
    );

    Carbon::setTestNow(Carbon::parse('2025-01-01 12:00:05'));

    // Transition to IDLE with no job (job completed)
    $this->repo->recordHeartbeat(
        workerId: 'worker-1',
        connection: 'redis',
        queue: 'default',
        state: WorkerState::IDLE,
        currentJobId: null,
        currentJobClass: null,
        pid: 100,
        hostname: 'prod-01',
    );

    $worker = $this->repo->getWorker('worker-1');
    expect($worker->jobsProcessed)->toBe(1);

    Carbon::setTestNow(Carbon::parse('2025-01-01 12:00:06'));

    // Go busy again
    $this->repo->recordHeartbeat(
        workerId: 'worker-1',
        connection: 'redis',
        queue: 'default',
        state: WorkerState::BUSY,
        currentJobId: 'job-2',
        currentJobClass: 'App\\Jobs\\SendEmail',
        pid: 100,
        hostname: 'prod-01',
    );

    Carbon::setTestNow(Carbon::parse('2025-01-01 12:00:10'));

    // Complete second job
    $this->repo->recordHeartbeat(
        workerId: 'worker-1',
        connection: 'redis',
        queue: 'default',
        state: WorkerState::IDLE,
        currentJobId: null,
        currentJobClass: null,
        pid: 100,
        hostname: 'prod-01',
    );

    $worker = $this->repo->getWorker('worker-1');
    expect($worker->jobsProcessed)->toBe(2);

    Carbon::setTestNow();
});

test('recordHeartbeat tracks peak memory usage', function () {
    $this->repo->recordHeartbeat(
        workerId: 'worker-1',
        connection: 'redis',
        queue: 'default',
        state: WorkerState::IDLE,
        currentJobId: null,
        currentJobClass: null,
        pid: 100,
        hostname: 'prod-01',
        memoryUsageMb: 50.0,
    );

    $worker = $this->repo->getWorker('worker-1');
    expect($worker->peakMemoryUsageMb)->toBe(50.0);

    // Higher memory
    $this->repo->recordHeartbeat(
        workerId: 'worker-1',
        connection: 'redis',
        queue: 'default',
        state: WorkerState::IDLE,
        currentJobId: null,
        currentJobClass: null,
        pid: 100,
        hostname: 'prod-01',
        memoryUsageMb: 120.0,
    );

    $worker = $this->repo->getWorker('worker-1');
    expect($worker->peakMemoryUsageMb)->toBe(120.0);

    // Lower memory - peak should not decrease
    $this->repo->recordHeartbeat(
        workerId: 'worker-1',
        connection: 'redis',
        queue: 'default',
        state: WorkerState::IDLE,
        currentJobId: null,
        currentJobClass: null,
        pid: 100,
        hostname: 'prod-01',
        memoryUsageMb: 30.0,
    );

    $worker = $this->repo->getWorker('worker-1');
    expect($worker->peakMemoryUsageMb)->toBe(120.0);
    expect($worker->memoryUsageMb)->toBe(30.0);
});

test('recordHeartbeat accumulates idle and busy time', function () {
    Carbon::setTestNow(Carbon::parse('2025-01-01 12:00:00'));

    $this->repo->recordHeartbeat(
        workerId: 'worker-1',
        connection: 'redis',
        queue: 'default',
        state: WorkerState::IDLE,
        currentJobId: null,
        currentJobClass: null,
        pid: 100,
        hostname: 'prod-01',
    );

    // 10 seconds pass while idle
    Carbon::setTestNow(Carbon::parse('2025-01-01 12:00:10'));

    $this->repo->recordHeartbeat(
        workerId: 'worker-1',
        connection: 'redis',
        queue: 'default',
        state: WorkerState::BUSY,
        currentJobId: 'job-1',
        currentJobClass: 'App\\Jobs\\Test',
        pid: 100,
        hostname: 'prod-01',
    );

    $worker = $this->repo->getWorker('worker-1');
    expect($worker->idleTimeSeconds)->toBe(10.0);
    expect($worker->busyTimeSeconds)->toBe(0.0);

    // 5 seconds pass while busy
    Carbon::setTestNow(Carbon::parse('2025-01-01 12:00:15'));

    $this->repo->recordHeartbeat(
        workerId: 'worker-1',
        connection: 'redis',
        queue: 'default',
        state: WorkerState::IDLE,
        currentJobId: null,
        currentJobClass: null,
        pid: 100,
        hostname: 'prod-01',
    );

    $worker = $this->repo->getWorker('worker-1');
    expect($worker->idleTimeSeconds)->toBe(10.0);
    expect($worker->busyTimeSeconds)->toBe(5.0);

    Carbon::setTestNow();
});

// --- transitionState ---

test('transitionState changes worker state', function () {
    $this->repo->recordHeartbeat(
        workerId: 'worker-1',
        connection: 'redis',
        queue: 'default',
        state: WorkerState::IDLE,
        currentJobId: null,
        currentJobClass: null,
        pid: 100,
        hostname: 'prod-01',
    );

    $this->repo->transitionState('worker-1', WorkerState::PAUSED, Carbon::now());

    $worker = $this->repo->getWorker('worker-1');
    expect($worker->state)->toBe(WorkerState::PAUSED);
});

test('transitionState does nothing for non-existent worker', function () {
    // Should not throw
    $this->repo->transitionState('non-existent', WorkerState::CRASHED, Carbon::now());

    expect($this->repo->getWorker('non-existent'))->toBeNull();
});

// --- getWorker ---

test('getWorker returns null for non-existent worker', function () {
    expect($this->repo->getWorker('non-existent'))->toBeNull();
});

test('getWorker returns WorkerHeartbeat for existing worker', function () {
    $this->repo->recordHeartbeat(
        workerId: 'worker-1',
        connection: 'redis',
        queue: 'default',
        state: WorkerState::IDLE,
        currentJobId: null,
        currentJobClass: null,
        pid: 200,
        hostname: 'web-01',
    );

    $worker = $this->repo->getWorker('worker-1');

    expect($worker)->not->toBeNull();
    expect($worker->workerId)->toBe('worker-1');
    expect($worker->connection)->toBe('redis');
    expect($worker->queue)->toBe('default');
    expect($worker->pid)->toBe(200);
    expect($worker->hostname)->toBe('web-01');
});

// --- getActiveWorkers ---

test('getActiveWorkers returns only active workers', function () {
    $this->repo->recordHeartbeat(
        workerId: 'worker-idle',
        connection: 'redis',
        queue: 'default',
        state: WorkerState::IDLE,
        currentJobId: null,
        currentJobClass: null,
        pid: 1,
        hostname: 'host',
    );

    $this->repo->recordHeartbeat(
        workerId: 'worker-busy',
        connection: 'redis',
        queue: 'default',
        state: WorkerState::BUSY,
        currentJobId: 'job-1',
        currentJobClass: 'App\\Jobs\\Test',
        pid: 2,
        hostname: 'host',
    );

    $this->repo->recordHeartbeat(
        workerId: 'worker-paused',
        connection: 'redis',
        queue: 'default',
        state: WorkerState::PAUSED,
        currentJobId: null,
        currentJobClass: null,
        pid: 3,
        hostname: 'host',
    );

    $this->repo->recordHeartbeat(
        workerId: 'worker-stopped',
        connection: 'redis',
        queue: 'default',
        state: WorkerState::STOPPED,
        currentJobId: null,
        currentJobClass: null,
        pid: 4,
        hostname: 'host',
    );

    $active = $this->repo->getActiveWorkers();
    expect($active)->toHaveCount(2);

    $ids = $active->pluck('workerId')->all();
    expect($ids)->toContain('worker-idle');
    expect($ids)->toContain('worker-busy');
});

test('getActiveWorkers filters by connection', function () {
    $this->repo->recordHeartbeat(
        workerId: 'worker-redis',
        connection: 'redis',
        queue: 'default',
        state: WorkerState::IDLE,
        currentJobId: null,
        currentJobClass: null,
        pid: 1,
        hostname: 'host',
    );

    $this->repo->recordHeartbeat(
        workerId: 'worker-sqs',
        connection: 'sqs',
        queue: 'default',
        state: WorkerState::IDLE,
        currentJobId: null,
        currentJobClass: null,
        pid: 2,
        hostname: 'host',
    );

    $workers = $this->repo->getActiveWorkers(connection: 'redis');
    expect($workers)->toHaveCount(1);
    expect($workers->first()->workerId)->toBe('worker-redis');
});

test('getActiveWorkers filters by queue', function () {
    $this->repo->recordHeartbeat(
        workerId: 'worker-default',
        connection: 'redis',
        queue: 'default',
        state: WorkerState::IDLE,
        currentJobId: null,
        currentJobClass: null,
        pid: 1,
        hostname: 'host',
    );

    $this->repo->recordHeartbeat(
        workerId: 'worker-emails',
        connection: 'redis',
        queue: 'emails',
        state: WorkerState::IDLE,
        currentJobId: null,
        currentJobClass: null,
        pid: 2,
        hostname: 'host',
    );

    $workers = $this->repo->getActiveWorkers(queue: 'emails');
    expect($workers)->toHaveCount(1);
    expect($workers->first()->workerId)->toBe('worker-emails');
});

test('getActiveWorkers filters by both connection and queue', function () {
    $this->repo->recordHeartbeat(
        workerId: 'worker-1',
        connection: 'redis',
        queue: 'default',
        state: WorkerState::IDLE,
        currentJobId: null,
        currentJobClass: null,
        pid: 1,
        hostname: 'host',
    );

    $this->repo->recordHeartbeat(
        workerId: 'worker-2',
        connection: 'redis',
        queue: 'emails',
        state: WorkerState::IDLE,
        currentJobId: null,
        currentJobClass: null,
        pid: 2,
        hostname: 'host',
    );

    $this->repo->recordHeartbeat(
        workerId: 'worker-3',
        connection: 'sqs',
        queue: 'default',
        state: WorkerState::IDLE,
        currentJobId: null,
        currentJobClass: null,
        pid: 3,
        hostname: 'host',
    );

    $workers = $this->repo->getActiveWorkers(connection: 'redis', queue: 'default');
    expect($workers)->toHaveCount(1);
    expect($workers->first()->workerId)->toBe('worker-1');
});

// --- getWorkersByState ---

test('getWorkersByState returns workers matching state', function () {
    $this->repo->recordHeartbeat(
        workerId: 'worker-idle-1',
        connection: 'redis',
        queue: 'default',
        state: WorkerState::IDLE,
        currentJobId: null,
        currentJobClass: null,
        pid: 1,
        hostname: 'host',
    );

    $this->repo->recordHeartbeat(
        workerId: 'worker-idle-2',
        connection: 'redis',
        queue: 'default',
        state: WorkerState::IDLE,
        currentJobId: null,
        currentJobClass: null,
        pid: 2,
        hostname: 'host',
    );

    $this->repo->recordHeartbeat(
        workerId: 'worker-busy',
        connection: 'redis',
        queue: 'default',
        state: WorkerState::BUSY,
        currentJobId: 'job-1',
        currentJobClass: 'App\\Jobs\\Test',
        pid: 3,
        hostname: 'host',
    );

    $idleWorkers = $this->repo->getWorkersByState(WorkerState::IDLE);
    expect($idleWorkers)->toHaveCount(2);

    $busyWorkers = $this->repo->getWorkersByState(WorkerState::BUSY);
    expect($busyWorkers)->toHaveCount(1);
    expect($busyWorkers->first()->workerId)->toBe('worker-busy');

    $pausedWorkers = $this->repo->getWorkersByState(WorkerState::PAUSED);
    expect($pausedWorkers)->toHaveCount(0);
});

// --- detectStaledWorkers ---

test('detectStaledWorkers marks active stale workers as crashed', function () {
    Carbon::setTestNow(Carbon::parse('2025-01-01 12:00:00'));

    // Register two active workers
    $this->repo->recordHeartbeat(
        workerId: 'worker-stale',
        connection: 'redis',
        queue: 'default',
        state: WorkerState::IDLE,
        currentJobId: null,
        currentJobClass: null,
        pid: 1,
        hostname: 'host',
    );

    $this->repo->recordHeartbeat(
        workerId: 'worker-fresh',
        connection: 'redis',
        queue: 'default',
        state: WorkerState::BUSY,
        currentJobId: 'job-1',
        currentJobClass: 'App\\Jobs\\Test',
        pid: 2,
        hostname: 'host',
    );

    // Move time forward so worker-stale becomes stale
    Carbon::setTestNow(Carbon::parse('2025-01-01 12:02:00'));

    // Update worker-fresh so it stays current
    $this->repo->recordHeartbeat(
        workerId: 'worker-fresh',
        connection: 'redis',
        queue: 'default',
        state: WorkerState::BUSY,
        currentJobId: 'job-1',
        currentJobClass: 'App\\Jobs\\Test',
        pid: 2,
        hostname: 'host',
    );

    $markedCount = $this->repo->detectStaledWorkers(thresholdSeconds: 60);

    expect($markedCount)->toBe(1);

    $staleWorker = $this->repo->getWorker('worker-stale');
    expect($staleWorker->state)->toBe(WorkerState::CRASHED);

    $freshWorker = $this->repo->getWorker('worker-fresh');
    expect($freshWorker->state)->toBe(WorkerState::BUSY);

    Carbon::setTestNow();
});

test('detectStaledWorkers does not mark non-active workers as crashed', function () {
    Carbon::setTestNow(Carbon::parse('2025-01-01 12:00:00'));

    $this->repo->recordHeartbeat(
        workerId: 'worker-stopped',
        connection: 'redis',
        queue: 'default',
        state: WorkerState::STOPPED,
        currentJobId: null,
        currentJobClass: null,
        pid: 1,
        hostname: 'host',
    );

    Carbon::setTestNow(Carbon::parse('2025-01-01 12:02:00'));

    $markedCount = $this->repo->detectStaledWorkers(thresholdSeconds: 60);

    expect($markedCount)->toBe(0);

    $worker = $this->repo->getWorker('worker-stopped');
    expect($worker->state)->toBe(WorkerState::STOPPED);

    Carbon::setTestNow();
});

// --- removeWorker ---

test('removeWorker deletes worker hash and sorted set entry', function () {
    $this->repo->recordHeartbeat(
        workerId: 'worker-1',
        connection: 'redis',
        queue: 'default',
        state: WorkerState::IDLE,
        currentJobId: null,
        currentJobClass: null,
        pid: 100,
        hostname: 'prod-01',
    );

    expect($this->repo->getWorker('worker-1'))->not->toBeNull();

    $this->repo->removeWorker('worker-1');

    expect($this->repo->getWorker('worker-1'))->toBeNull();

    // Verify also removed from sorted set (getActiveWorkers should be empty)
    $active = $this->repo->getActiveWorkers();
    expect($active)->toHaveCount(0);
});

test('removeWorker is safe for non-existent worker', function () {
    // Should not throw
    $this->repo->removeWorker('non-existent');

    expect($this->repo->getActiveWorkers())->toHaveCount(0);
});

// --- cleanup ---

test('cleanup removes old worker records', function () {
    Carbon::setTestNow(Carbon::parse('2025-01-01 12:00:00'));

    $this->repo->recordHeartbeat(
        workerId: 'worker-old',
        connection: 'redis',
        queue: 'default',
        state: WorkerState::IDLE,
        currentJobId: null,
        currentJobClass: null,
        pid: 1,
        hostname: 'host',
    );

    Carbon::setTestNow(Carbon::parse('2025-01-01 14:00:00'));

    $this->repo->recordHeartbeat(
        workerId: 'worker-recent',
        connection: 'redis',
        queue: 'default',
        state: WorkerState::IDLE,
        currentJobId: null,
        currentJobClass: null,
        pid: 2,
        hostname: 'host',
    );

    // Cleanup workers older than 1 hour
    $cleaned = $this->repo->cleanup(olderThanSeconds: 3600);

    expect($cleaned)->toBe(1);
    expect($this->repo->getWorker('worker-old'))->toBeNull();
    expect($this->repo->getWorker('worker-recent'))->not->toBeNull();

    Carbon::setTestNow();
});

test('cleanup returns zero when no old workers exist', function () {
    $this->repo->recordHeartbeat(
        workerId: 'worker-1',
        connection: 'redis',
        queue: 'default',
        state: WorkerState::IDLE,
        currentJobId: null,
        currentJobClass: null,
        pid: 1,
        hostname: 'host',
    );

    $cleaned = $this->repo->cleanup(olderThanSeconds: 3600);

    expect($cleaned)->toBe(0);
});

test('recordHeartbeat stores cpu usage', function () {
    $this->repo->recordHeartbeat(
        workerId: 'worker-1',
        connection: 'redis',
        queue: 'default',
        state: WorkerState::IDLE,
        currentJobId: null,
        currentJobClass: null,
        pid: 100,
        hostname: 'prod-01',
        memoryUsageMb: 64.5,
        cpuUsagePercent: 23.7,
    );

    $worker = $this->repo->getWorker('worker-1');
    expect($worker->memoryUsageMb)->toBe(64.5);
    expect($worker->cpuUsagePercent)->toBe(23.7);
});
