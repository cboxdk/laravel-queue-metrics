<?php

declare(strict_types=1);

use Cbox\LaravelQueueMetrics\Events\HealthScoreChanged;
use Cbox\LaravelQueueMetrics\Repositories\DatabaseQueueMetricsRepository;
use Cbox\LaravelQueueMetrics\Support\DatabaseMetricsStore;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config()->set('queue-metrics.storage.connection', null);

    $createTables = include __DIR__.'/../../../database/migrations/2024_01_01_000001_create_queue_metrics_storage_tables.php';
    $createTables->up();

    $addSetExpiry = include __DIR__.'/../../../database/migrations/2024_01_01_000002_add_expires_at_to_queue_metrics_sets.php';
    $addSetExpiry->up();

    $this->store = new DatabaseMetricsStore;
    $this->repo = new DatabaseQueueMetricsRepository($this->store);
});

// --- getQueueState ---

test('getQueueState returns depth info from queue driver', function () {
    Queue::shouldReceive('connection')
        ->with('redis')
        ->andReturnSelf();

    Queue::shouldReceive('size')
        ->with('default')
        ->andReturn(42);

    $state = $this->repo->getQueueState('redis', 'default');

    expect($state['depth'])->toBe(42);
    expect($state['pending'])->toBe(42);
    expect($state['scheduled'])->toBe(0);
    expect($state['reserved'])->toBe(0);
    expect($state['oldest_job_age'])->toBe(0);
});

// --- recordSnapshot and getLatestMetrics ---

test('recordSnapshot stores snapshot and getLatestMetrics retrieves it', function () {
    $metrics = [
        'depth' => 10,
        'pending' => 8,
        'scheduled' => 1,
        'reserved' => 1,
        'oldest_job_age' => 120,
        'throughput_per_minute' => 5.5,
        'avg_duration' => 250.0,
        'failure_rate' => 2.0,
        'utilization_rate' => 0.75,
        'active_workers' => 3,
    ];

    $this->repo->recordSnapshot('redis', 'default', $metrics);

    $latest = $this->repo->getLatestMetrics('redis', 'default');

    expect($latest['depth'])->toBe(10);
    expect($latest['pending'])->toBe(8);
    expect($latest['scheduled'])->toBe(1);
    expect($latest['reserved'])->toBe(1);
    expect($latest['oldest_job_age'])->toBe(120);
    expect($latest['throughput_per_minute'])->toBe(5.5);
    expect($latest['avg_duration'])->toBe(250.0);
    expect($latest['failure_rate'])->toBe(2.0);
    expect($latest['utilization_rate'])->toBe(0.75);
    expect($latest['active_workers'])->toBe(3);
    expect($latest['recorded_at'])->not->toBeNull();
});

test('getLatestMetrics returns empty array when no snapshot exists', function () {
    $latest = $this->repo->getLatestMetrics('redis', 'default');
    expect($latest)->toBe([]);
});

test('recordSnapshot trims sorted set to 1000 entries', function () {
    // Record 1005 snapshots
    for ($i = 0; $i < 1005; $i++) {
        $this->repo->recordSnapshot('redis', 'default', [
            'depth' => $i,
            'pending' => $i,
        ]);
    }

    // Check sorted set count - should be trimmed to 1000
    $timestampKey = $this->store->key('queue_snapshots', 'redis', 'default');
    $count = $this->store->countSortedSetByScore($timestampKey, '-inf', '+inf');

    expect($count)->toBeLessThanOrEqual(1000);
});

// --- getHealthStatus ---

test('getHealthStatus returns unknown when no metrics exist', function () {
    Event::fake();

    $status = $this->repo->getHealthStatus('redis', 'default');

    expect($status['status'])->toBe('unknown');
    expect($status['score'])->toBe(0.0);
});

test('getHealthStatus returns healthy for good metrics', function () {
    Event::fake();

    $this->repo->recordSnapshot('redis', 'default', [
        'depth' => 10,
        'pending' => 10,
        'scheduled' => 0,
        'reserved' => 0,
        'oldest_job_age' => 60,
        'throughput_per_minute' => 10.0,
        'avg_duration' => 100.0,
        'failure_rate' => 0.0,
        'utilization_rate' => 0.5,
        'active_workers' => 5,
    ]);

    $status = $this->repo->getHealthStatus('redis', 'default');

    expect($status['status'])->toBe('healthy');
    expect($status['score'])->toBe(100.0);
});

test('getHealthStatus returns warning for moderate issues', function () {
    Event::fake();

    $this->repo->recordSnapshot('redis', 'default', [
        'depth' => 250,
        'pending' => 250,
        'scheduled' => 0,
        'reserved' => 0,
        'oldest_job_age' => 400,
        'throughput_per_minute' => 2.0,
        'avg_duration' => 500.0,
        'failure_rate' => 5.0,
        'utilization_rate' => 0.9,
        'active_workers' => 2,
    ]);

    $status = $this->repo->getHealthStatus('redis', 'default');

    expect($status['status'])->toBe('warning');
    expect($status['score'])->toBeGreaterThanOrEqual(50.0);
    expect($status['score'])->toBeLessThan(80.0);
});

test('getHealthStatus returns critical for severe issues', function () {
    Event::fake();

    $this->repo->recordSnapshot('redis', 'default', [
        'depth' => 500,
        'pending' => 500,
        'scheduled' => 0,
        'reserved' => 0,
        'oldest_job_age' => 3000,
        'throughput_per_minute' => 0.0,
        'avg_duration' => 1000.0,
        'failure_rate' => 20.0,
        'utilization_rate' => 1.0,
        'active_workers' => 0,
    ]);

    $status = $this->repo->getHealthStatus('redis', 'default');

    expect($status['status'])->toBe('critical');
    expect($status['score'])->toBeLessThan(50.0);
});

test('getHealthStatus dispatches HealthScoreChanged event on significant change', function () {
    Event::fake();

    // Record healthy metrics and get health status to establish baseline
    $this->repo->recordSnapshot('redis', 'default', [
        'depth' => 10,
        'pending' => 10,
        'scheduled' => 0,
        'reserved' => 0,
        'oldest_job_age' => 0,
        'throughput_per_minute' => 10.0,
        'avg_duration' => 100.0,
        'failure_rate' => 0.0,
        'utilization_rate' => 0.5,
        'active_workers' => 5,
    ]);
    $this->repo->getHealthStatus('redis', 'default'); // Score = 100

    // Now record bad metrics
    $this->repo->recordSnapshot('redis', 'default', [
        'depth' => 500,
        'pending' => 500,
        'scheduled' => 0,
        'reserved' => 0,
        'oldest_job_age' => 3000,
        'throughput_per_minute' => 0.0,
        'avg_duration' => 1000.0,
        'failure_rate' => 20.0,
        'utilization_rate' => 1.0,
        'active_workers' => 0,
    ]);
    $this->repo->getHealthStatus('redis', 'default'); // Score should be much lower

    Event::assertDispatched(HealthScoreChanged::class, function (HealthScoreChanged $event) {
        return $event->connection === 'redis'
            && $event->queue === 'default'
            && $event->previousScore === 100.0;
    });
});

test('getHealthStatus does not dispatch event on small change', function () {
    Event::fake();

    // Record healthy metrics to establish baseline
    $this->repo->recordSnapshot('redis', 'default', [
        'depth' => 10,
        'pending' => 10,
        'scheduled' => 0,
        'reserved' => 0,
        'oldest_job_age' => 0,
        'throughput_per_minute' => 10.0,
        'avg_duration' => 100.0,
        'failure_rate' => 0.0,
        'utilization_rate' => 0.5,
        'active_workers' => 5,
    ]);
    $this->repo->getHealthStatus('redis', 'default'); // Score = 100

    // Record metrics with only a tiny change (depth 110 = small penalty)
    $this->repo->recordSnapshot('redis', 'default', [
        'depth' => 110,
        'pending' => 110,
        'scheduled' => 0,
        'reserved' => 0,
        'oldest_job_age' => 0,
        'throughput_per_minute' => 10.0,
        'avg_duration' => 100.0,
        'failure_rate' => 0.0,
        'utilization_rate' => 0.5,
        'active_workers' => 5,
    ]);
    $this->repo->getHealthStatus('redis', 'default'); // Score = 99 (small change)

    Event::assertNotDispatched(HealthScoreChanged::class);
});

// --- listQueues and markQueueDiscovered ---

test('listQueues returns empty array initially', function () {
    expect($this->repo->listQueues())->toBe([]);
});

test('markQueueDiscovered registers queue and listQueues returns it', function () {
    $this->repo->markQueueDiscovered('redis', 'default');
    $this->repo->markQueueDiscovered('sqs', 'emails');

    $queues = $this->repo->listQueues();
    expect($queues)->toHaveCount(2);

    $connections = array_column($queues, 'connection');
    $queueNames = array_column($queues, 'queue');
    expect($connections)->toContain('redis');
    expect($connections)->toContain('sqs');
    expect($queueNames)->toContain('default');
    expect($queueNames)->toContain('emails');
});

test('markQueueDiscovered does not create duplicates', function () {
    $this->repo->markQueueDiscovered('redis', 'default');
    $this->repo->markQueueDiscovered('redis', 'default');

    $queues = $this->repo->listQueues();
    expect($queues)->toHaveCount(1);
});

// --- cleanup ---

test('cleanup removes old snapshots', function () {
    // Create an old snapshot
    $oldKey = $this->store->key('queue_snapshot', 'redis', 'default');
    $this->store->setHash($oldKey, [
        'depth' => 5,
        'recorded_at' => now()->subDays(10)->timestamp,
    ]);

    // Create a recent snapshot
    $recentKey = $this->store->key('queue_snapshot', 'sqs', 'emails');
    $this->store->setHash($recentKey, [
        'depth' => 3,
        'recorded_at' => now()->timestamp,
    ]);

    $deleted = $this->repo->cleanup(86400); // Older than 1 day

    expect($deleted)->toBe(1);
    expect($this->store->getHash($oldKey))->toBe([]);
    expect($this->store->getHash($recentKey))->not->toBe([]);
});

test('cleanup returns zero when nothing to clean', function () {
    $key = $this->store->key('queue_snapshot', 'redis', 'default');
    $this->store->setHash($key, [
        'depth' => 3,
        'recorded_at' => now()->timestamp,
    ]);

    $deleted = $this->repo->cleanup(86400);
    expect($deleted)->toBe(0);
});

test('cleanup skips keys without recorded_at', function () {
    $key = $this->store->key('queue_snapshot', 'redis', 'default');
    $this->store->setHash($key, ['depth' => 1]);

    $deleted = $this->repo->cleanup(86400);
    expect($deleted)->toBe(0);
});
