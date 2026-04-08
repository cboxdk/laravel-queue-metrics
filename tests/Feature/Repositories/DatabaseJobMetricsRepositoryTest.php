<?php

declare(strict_types=1);

use Cbox\LaravelQueueMetrics\Repositories\DatabaseJobMetricsRepository;
use Cbox\LaravelQueueMetrics\Support\DatabaseMetricsStore;

beforeEach(function () {
    config()->set('queue-metrics.storage.connection', null);

    $migration = include __DIR__.'/../../../database/migrations/2024_01_01_000001_create_queue_metrics_storage_tables.php';
    $migration->up();

    $this->store = new DatabaseMetricsStore;
    $this->repo = new DatabaseJobMetricsRepository($this->store);
});

// --- recordStart ---

test('recordStart stores job data and increments total_queued', function () {
    $this->repo->recordStart('job-1', 'App\\Jobs\\SendEmail', 'redis', 'default', now());

    $metrics = $this->repo->getMetrics('App\\Jobs\\SendEmail', 'redis', 'default');
    expect($metrics['total_processed'])->toBe(0);

    // Verify total_queued was incremented
    $metricsKey = $this->store->key('jobs', 'redis', 'default', 'App\\Jobs\\SendEmail');
    $data = $this->store->getHash($metricsKey);
    expect($data['total_queued'])->toBe(1);

    // Verify job hash was stored
    $jobData = $this->store->getHash('job:job-1');
    expect($jobData['job_class'])->toBe('App\\Jobs\\SendEmail');
    expect($jobData['connection'])->toBe('redis');
    expect($jobData['queue'])->toBe('default');
});

test('recordStart registers job in discovery set', function () {
    $this->repo->recordStart('job-1', 'App\\Jobs\\SendEmail', 'redis', 'default', now());

    $jobs = $this->repo->listJobs();
    expect($jobs)->toHaveCount(1);
    expect($jobs[0]['connection'])->toBe('redis');
    expect($jobs[0]['queue'])->toBe('default');
    expect($jobs[0]['jobClass'])->toBe('App\\Jobs\\SendEmail');
});

test('recordStart registers queue in discovery set', function () {
    $this->repo->recordStart('job-1', 'App\\Jobs\\SendEmail', 'redis', 'default', now());

    $members = $this->store->getSetMembers('discovery:queues');
    expect($members)->toContain('redis:default');
});

// --- recordCompletion ---

test('recordCompletion stores metrics and samples', function () {
    $this->repo->recordCompletion(
        jobId: 'job-1',
        jobClass: 'App\\Jobs\\SendEmail',
        connection: 'redis',
        queue: 'default',
        durationMs: 150.0,
        memoryMb: 32.5,
        cpuTimeMs: 45.0,
        completedAt: now(),
    );

    $metrics = $this->repo->getMetrics('App\\Jobs\\SendEmail', 'redis', 'default');
    expect($metrics['total_processed'])->toBe(1);
    expect((float) $metrics['total_duration_ms'])->toBe(150.0);
    expect((float) $metrics['total_memory_mb'])->toBe(32.5);
    expect((float) $metrics['total_cpu_time_ms'])->toBe(45.0);

    $durations = $this->repo->getDurationSamples('App\\Jobs\\SendEmail', 'redis', 'default', 100);
    expect($durations)->toHaveCount(1);
    expect($durations[0])->toBe(150.0);
});

test('recordCompletion accumulates metrics across multiple calls', function () {
    $this->repo->recordCompletion('j1', 'App\\Jobs\\SendEmail', 'redis', 'default', 100.0, 10.0, 5.0, now());
    $this->repo->recordCompletion('j2', 'App\\Jobs\\SendEmail', 'redis', 'default', 200.0, 20.0, 10.0, now());

    $metrics = $this->repo->getMetrics('App\\Jobs\\SendEmail', 'redis', 'default');
    expect($metrics['total_processed'])->toBe(2);
    expect((float) $metrics['total_duration_ms'])->toBe(300.0);
    expect((float) $metrics['total_memory_mb'])->toBe(30.0);
    expect((float) $metrics['total_cpu_time_ms'])->toBe(15.0);
});

test('recordCompletion cleans up job tracking key', function () {
    $this->repo->recordStart('job-1', 'App\\Jobs\\SendEmail', 'redis', 'default', now());

    $this->repo->recordCompletion('job-1', 'App\\Jobs\\SendEmail', 'redis', 'default', 150.0, 32.5, 45.0, now());

    $jobData = $this->store->getHash('job:job-1');
    expect($jobData)->toBe([]);
});

test('recordCompletion stores hostname metrics when provided', function () {
    $this->repo->recordCompletion(
        'job-1', 'App\\Jobs\\SendEmail', 'redis', 'default',
        150.0, 32.5, 45.0, now(), 'prod-01'
    );

    $hostnameMetrics = $this->repo->getHostnameJobMetrics('prod-01');
    expect($hostnameMetrics)->not->toBeEmpty();

    $firstKey = array_key_first($hostnameMetrics);
    expect($hostnameMetrics[$firstKey]['total_processed'])->toBe(1);
    expect($hostnameMetrics[$firstKey]['total_duration_ms'])->toBe(150.0);
});

// --- recordFailure ---

test('recordFailure increments failure counter and stores exception', function () {
    $this->repo->recordFailure(
        'job-1', 'App\\Jobs\\SendEmail', 'redis', 'default',
        'RuntimeException: Something went wrong', now()
    );

    $metrics = $this->repo->getMetrics('App\\Jobs\\SendEmail', 'redis', 'default');
    expect($metrics['total_failed'])->toBe(1);
    expect($metrics['last_exception'])->toBe('RuntimeException: Something went wrong');
    expect($metrics['last_failed_at'])->not->toBeNull();
});

test('recordFailure stores hostname failure metrics when provided', function () {
    $this->repo->recordFailure(
        'job-1', 'App\\Jobs\\SendEmail', 'redis', 'default',
        'RuntimeException: fail', now(), 'prod-01'
    );

    $hostnameMetrics = $this->repo->getHostnameJobMetrics('prod-01');
    expect($hostnameMetrics)->not->toBeEmpty();

    $firstKey = array_key_first($hostnameMetrics);
    expect($hostnameMetrics[$firstKey]['total_failed'])->toBe(1);
});

test('recordFailure cleans up job tracking key', function () {
    $this->repo->recordStart('job-1', 'App\\Jobs\\SendEmail', 'redis', 'default', now());

    $this->repo->recordFailure(
        'job-1', 'App\\Jobs\\SendEmail', 'redis', 'default',
        'RuntimeException: fail', now()
    );

    $jobData = $this->store->getHash('job:job-1');
    expect($jobData)->toBe([]);
});

test('recordFailure truncates long exception messages', function () {
    $longException = str_repeat('x', 2000);
    $this->repo->recordFailure(
        'job-1', 'App\\Jobs\\SendEmail', 'redis', 'default',
        $longException, now()
    );

    $metrics = $this->repo->getMetrics('App\\Jobs\\SendEmail', 'redis', 'default');
    expect(strlen($metrics['last_exception']))->toBeLessThanOrEqual(1000);
});

// --- getDurationSamples ---

test('getDurationSamples returns parsed values in chronological order', function () {
    $this->repo->recordCompletion('j1', 'Job', 'redis', 'default', 100.0, 10.0, 5.0, now()->subSeconds(3));
    $this->repo->recordCompletion('j2', 'Job', 'redis', 'default', 200.0, 20.0, 10.0, now()->subSeconds(2));
    $this->repo->recordCompletion('j3', 'Job', 'redis', 'default', 300.0, 30.0, 15.0, now()->subSeconds(1));

    $durations = $this->repo->getDurationSamples('Job', 'redis', 'default', 100);
    expect($durations)->toBe([100.0, 200.0, 300.0]);
});

test('getDurationSamples respects limit parameter', function () {
    $this->repo->recordCompletion('j1', 'Job', 'redis', 'default', 100.0, 10.0, 5.0, now()->subSeconds(3));
    $this->repo->recordCompletion('j2', 'Job', 'redis', 'default', 200.0, 20.0, 10.0, now()->subSeconds(2));
    $this->repo->recordCompletion('j3', 'Job', 'redis', 'default', 300.0, 30.0, 15.0, now()->subSeconds(1));

    $durations = $this->repo->getDurationSamples('Job', 'redis', 'default', 2);
    expect($durations)->toHaveCount(2);
    expect($durations)->toBe([200.0, 300.0]);
});

test('getDurationSamples returns empty array when no samples', function () {
    $durations = $this->repo->getDurationSamples('Job', 'redis', 'default', 100);
    expect($durations)->toBe([]);
});

// --- getMemorySamples ---

test('getMemorySamples returns parsed values', function () {
    $this->repo->recordCompletion('j1', 'Job', 'redis', 'default', 100.0, 32.5, 5.0, now()->subSeconds(2));
    $this->repo->recordCompletion('j2', 'Job', 'redis', 'default', 200.0, 64.0, 10.0, now()->subSeconds(1));

    $samples = $this->repo->getMemorySamples('Job', 'redis', 'default', 100);
    expect($samples)->toBe([32.5, 64.0]);
});

// --- getCpuTimeSamples ---

test('getCpuTimeSamples returns parsed values', function () {
    $this->repo->recordCompletion('j1', 'Job', 'redis', 'default', 100.0, 10.0, 45.0, now()->subSeconds(2));
    $this->repo->recordCompletion('j2', 'Job', 'redis', 'default', 200.0, 20.0, 90.0, now()->subSeconds(1));

    $samples = $this->repo->getCpuTimeSamples('Job', 'redis', 'default', 100);
    expect($samples)->toBe([45.0, 90.0]);
});

// --- getThroughput ---

test('getThroughput counts completions within window', function () {
    $this->repo->recordCompletion('j1', 'Job', 'redis', 'default', 100, 10, 5, now());
    $this->repo->recordCompletion('j2', 'Job', 'redis', 'default', 100, 10, 5, now());
    $this->repo->recordCompletion('j3', 'Job', 'redis', 'default', 100, 10, 5, now()->subHours(2));

    expect($this->repo->getThroughput('Job', 'redis', 'default', 3600))->toBe(2);
});

test('getThroughput returns zero when no completions', function () {
    expect($this->repo->getThroughput('Job', 'redis', 'default', 3600))->toBe(0);
});

test('getThroughput returns all within large window', function () {
    $this->repo->recordCompletion('j1', 'Job', 'redis', 'default', 100, 10, 5, now()->subHours(2));
    $this->repo->recordCompletion('j2', 'Job', 'redis', 'default', 100, 10, 5, now()->subHours(1));
    $this->repo->recordCompletion('j3', 'Job', 'redis', 'default', 100, 10, 5, now());

    expect($this->repo->getThroughput('Job', 'redis', 'default', 86400))->toBe(3);
});

// --- getAverageDurationInWindow ---

test('getAverageDurationInWindow calculates average for completions within window', function () {
    $this->repo->recordCompletion('j1', 'Job', 'redis', 'default', 100.0, 10, 5, now());
    $this->repo->recordCompletion('j2', 'Job', 'redis', 'default', 200.0, 10, 5, now());
    $this->repo->recordCompletion('j3', 'Job', 'redis', 'default', 900.0, 10, 5, now()->subHours(2));

    $avg = $this->repo->getAverageDurationInWindow('Job', 'redis', 'default', 3600);
    expect($avg)->toBe(150.0);
});

test('getAverageDurationInWindow returns zero when no completions', function () {
    expect($this->repo->getAverageDurationInWindow('Job', 'redis', 'default', 3600))->toBe(0.0);
});

// --- getHostnameJobMetrics ---

test('getHostnameJobMetrics returns metrics for hostname', function () {
    $this->repo->recordCompletion('j1', 'App\\Jobs\\A', 'redis', 'default', 100.0, 10, 5, now(), 'prod-01');
    $this->repo->recordCompletion('j2', 'App\\Jobs\\A', 'redis', 'default', 200.0, 10, 5, now(), 'prod-01');
    $this->repo->recordFailure('j3', 'App\\Jobs\\A', 'redis', 'default', 'Error', now(), 'prod-01');

    $metrics = $this->repo->getHostnameJobMetrics('prod-01');
    expect($metrics)->not->toBeEmpty();

    $firstKey = array_key_first($metrics);
    expect($metrics[$firstKey]['total_processed'])->toBe(2);
    expect($metrics[$firstKey]['total_failed'])->toBe(1);
    expect($metrics[$firstKey]['total_duration_ms'])->toBe(300.0);
    expect($metrics[$firstKey]['failure_rate'])->toBe(33.33);
    expect($metrics[$firstKey]['avg_duration_ms'])->toBe(150.0);
});

test('getHostnameJobMetrics returns empty array for unknown hostname', function () {
    expect($this->repo->getHostnameJobMetrics('unknown-host'))->toBe([]);
});

// --- listJobs and markJobDiscovered ---

test('listJobs returns empty array initially', function () {
    expect($this->repo->listJobs())->toBe([]);
});

test('markJobDiscovered registers job and listJobs returns it', function () {
    $this->repo->markJobDiscovered('redis', 'default', 'App\\Jobs\\SendEmail');
    $this->repo->markJobDiscovered('sqs', 'emails', 'App\\Jobs\\ProcessOrder');

    $jobs = $this->repo->listJobs();
    expect($jobs)->toHaveCount(2);

    $jobClasses = array_column($jobs, 'jobClass');
    expect($jobClasses)->toContain('App\\Jobs\\SendEmail');
    expect($jobClasses)->toContain('App\\Jobs\\ProcessOrder');
});

test('markJobDiscovered does not create duplicates', function () {
    $this->repo->markJobDiscovered('redis', 'default', 'App\\Jobs\\SendEmail');
    $this->repo->markJobDiscovered('redis', 'default', 'App\\Jobs\\SendEmail');

    $jobs = $this->repo->listJobs();
    expect($jobs)->toHaveCount(1);
});

// --- recordQueuedAt ---

test('recordQueuedAt stores timestamp', function () {
    $queuedAt = now();
    $this->repo->recordQueuedAt('App\\Jobs\\SendEmail', 'redis', 'default', $queuedAt);

    // Verify via sorted set - queued entries should exist
    $key = $this->store->key('queued', 'redis', 'default', 'App\\Jobs\\SendEmail');
    $members = $this->store->getSortedSetByScore($key, '-inf', '+inf');
    expect($members)->toHaveCount(1);
});

// --- recordRetryRequested ---

test('recordRetryRequested increments retry counter', function () {
    $this->repo->recordRetryRequested('job-1', 'App\\Jobs\\SendEmail', 'redis', 'default', now(), 2);

    $metricsKey = $this->store->key('jobs', 'redis', 'default', 'App\\Jobs\\SendEmail');
    $data = $this->store->getHash($metricsKey);
    expect($data['total_retries'])->toBe(1);
});

test('recordRetryRequested stores retry event in sorted set', function () {
    $this->repo->recordRetryRequested('job-1', 'App\\Jobs\\SendEmail', 'redis', 'default', now(), 2);

    $retryKey = $this->store->key('retries', 'redis', 'default', 'App\\Jobs\\SendEmail');
    $members = $this->store->getSortedSetByScore($retryKey, '-inf', '+inf');
    expect($members)->toHaveCount(1);
    expect($members[0])->toContain('"job_id":"job-1"');
});

// --- recordTimeout ---

test('recordTimeout increments timeout counter', function () {
    $this->repo->recordTimeout('job-1', 'App\\Jobs\\SendEmail', 'redis', 'default', now());

    $metricsKey = $this->store->key('jobs', 'redis', 'default', 'App\\Jobs\\SendEmail');
    $data = $this->store->getHash($metricsKey);
    expect($data['total_timeouts'])->toBe(1);
});

test('recordTimeout stores last timeout timestamp', function () {
    $timedOutAt = now();
    $this->repo->recordTimeout('job-1', 'App\\Jobs\\SendEmail', 'redis', 'default', $timedOutAt);

    $metricsKey = $this->store->key('jobs', 'redis', 'default', 'App\\Jobs\\SendEmail');
    $data = $this->store->getHash($metricsKey);
    expect($data['last_timeout_at'])->toBe($timedOutAt->timestamp);
});

// --- recordException ---

test('recordException increments exception counter', function () {
    $this->repo->recordException(
        'job-1', 'App\\Jobs\\SendEmail', 'redis', 'default',
        'RuntimeException', 'Something failed', now()
    );

    $metricsKey = $this->store->key('jobs', 'redis', 'default', 'App\\Jobs\\SendEmail');
    $data = $this->store->getHash($metricsKey);
    expect($data['total_exceptions'])->toBe(1);
});

test('recordException tracks exception class counts', function () {
    $this->repo->recordException(
        'job-1', 'App\\Jobs\\SendEmail', 'redis', 'default',
        'RuntimeException', 'fail 1', now()
    );
    $this->repo->recordException(
        'job-2', 'App\\Jobs\\SendEmail', 'redis', 'default',
        'RuntimeException', 'fail 2', now()
    );
    $this->repo->recordException(
        'job-3', 'App\\Jobs\\SendEmail', 'redis', 'default',
        'InvalidArgumentException', 'bad arg', now()
    );

    $exceptionsKey = $this->store->key('exceptions', 'redis', 'default', 'App\\Jobs\\SendEmail');
    $data = $this->store->getHash($exceptionsKey);
    expect($data['RuntimeException'])->toBe(2);
    expect($data['InvalidArgumentException'])->toBe(1);
});

// --- cleanup ---

test('cleanup removes old job metrics', function () {
    // Create a job that was processed long ago
    $oldKey = $this->store->key('jobs', 'redis', 'default', 'App\\Jobs\\OldJob');
    $this->store->setHash($oldKey, [
        'total_processed' => 5,
        'last_processed_at' => now()->subDays(10)->timestamp,
    ]);

    // Create a recent job
    $recentKey = $this->store->key('jobs', 'redis', 'default', 'App\\Jobs\\RecentJob');
    $this->store->setHash($recentKey, [
        'total_processed' => 3,
        'last_processed_at' => now()->timestamp,
    ]);

    $deleted = $this->repo->cleanup(86400); // Older than 1 day

    expect($deleted)->toBe(1);
    expect($this->store->getHash($oldKey))->toBe([]);
    expect($this->store->getHash($recentKey))->not->toBe([]);
});

test('cleanup returns zero when nothing to clean', function () {
    $key = $this->store->key('jobs', 'redis', 'default', 'App\\Jobs\\RecentJob');
    $this->store->setHash($key, [
        'total_processed' => 3,
        'last_processed_at' => now()->timestamp,
    ]);

    $deleted = $this->repo->cleanup(86400);
    expect($deleted)->toBe(0);
});

test('cleanup skips keys without last_processed_at', function () {
    $key = $this->store->key('jobs', 'redis', 'default', 'App\\Jobs\\NoTimestamp');
    $this->store->setHash($key, ['total_processed' => 1]);

    $deleted = $this->repo->cleanup(86400);
    expect($deleted)->toBe(0);
});

// --- getMetrics ---

test('getMetrics returns defaults for non-existent job', function () {
    $metrics = $this->repo->getMetrics('NonExistent', 'redis', 'default');

    expect($metrics['total_processed'])->toBe(0);
    expect($metrics['total_failed'])->toBe(0);
    expect($metrics['total_duration_ms'])->toBe(0.0);
    expect($metrics['total_memory_mb'])->toBe(0.0);
    expect($metrics['total_cpu_time_ms'])->toBe(0.0);
    expect($metrics['last_processed_at'])->toBeNull();
    expect($metrics['last_failed_at'])->toBeNull();
    expect($metrics['last_exception'])->toBeNull();
});
