<?php

declare(strict_types=1);

use Cbox\LaravelQueueMetrics\Actions\RecordJobCompletionAction;
use Cbox\LaravelQueueMetrics\Actions\RecordWorkerHeartbeatAction;
use Cbox\LaravelQueueMetrics\Events\JobMetricsCompleted;
use Cbox\LaravelQueueMetrics\Listeners\JobProcessedListener;
use Cbox\LaravelQueueMetrics\Listeners\JobProcessingListener;
use Cbox\LaravelQueueMetrics\Repositories\Contracts\JobMetricsRepository;
use Cbox\LaravelQueueMetrics\Repositories\Contracts\WorkerHeartbeatRepository;
use Cbox\LaravelQueueMetrics\Support\JobCpuSnapshotCache;
use Cbox\SystemMetrics\Contracts\ProcessMetricsSource;
use Cbox\SystemMetrics\DTO\Metrics\Cpu\CpuTimes;
use Cbox\SystemMetrics\DTO\Metrics\Process\ProcessResourceUsage;
use Cbox\SystemMetrics\DTO\Metrics\Process\ProcessSnapshot;
use Cbox\SystemMetrics\DTO\Result;
use Cbox\SystemMetrics\Exceptions\SystemMetricsException;
use Cbox\SystemMetrics\ProcessMetrics;
use Illuminate\Contracts\Queue\Job as QueueJob;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Event;

/**
 * Helper to create a fake ProcessMetricsSource returning known CPU times.
 */
function createJobTestSource(int $userCpuMs, int $systemCpuMs, int $memoryRssBytes = 64 * 1024 * 1024): ProcessMetricsSource
{
    return new class($userCpuMs, $systemCpuMs, $memoryRssBytes) implements ProcessMetricsSource
    {
        public function __construct(
            private readonly int $userCpuMs,
            private readonly int $systemCpuMs,
            private readonly int $memoryRssBytes,
        ) {}

        public function read(int $pid): Result
        {
            return Result::success(new ProcessSnapshot(
                pid: $pid,
                parentPid: 1,
                resources: new ProcessResourceUsage(
                    cpuTimes: new CpuTimes(
                        user: $this->userCpuMs,
                        nice: 0,
                        system: $this->systemCpuMs,
                        idle: 0,
                        iowait: 0,
                        irq: 0,
                        softirq: 0,
                        steal: 0,
                    ),
                    memoryRssBytes: $this->memoryRssBytes,
                    memoryVmsBytes: $this->memoryRssBytes * 2,
                    threadCount: 1,
                    openFileDescriptors: 10,
                ),
                timestamp: new DateTimeImmutable,
            ));
        }

        public function readProcessGroup(int $rootPid): Result
        {
            return Result::failure(
                new SystemMetricsException('Not implemented in test fake')
            );
        }
    };
}

beforeEach(function () {
    JobCpuSnapshotCache::reset();

    $this->jobMetricsRepository = Mockery::mock(JobMetricsRepository::class);
    $this->workerHeartbeatRepository = Mockery::mock(WorkerHeartbeatRepository::class);
    $this->jobStartRepository = Mockery::mock(JobMetricsRepository::class);

    $this->recordCompletion = new RecordJobCompletionAction($this->jobMetricsRepository);
    $this->recordHeartbeat = new RecordWorkerHeartbeatAction($this->workerHeartbeatRepository);

    $this->processedListener = new JobProcessedListener(
        $this->recordCompletion,
        $this->recordHeartbeat,
    );

    config([
        'queue-metrics.enabled' => true,
        'queue-metrics.persistence.enabled' => true,
    ]);
});

afterEach(function () {
    ProcessMetrics::setSource(null);
    JobCpuSnapshotCache::reset();
    Mockery::close();
});

it('reports cpu time as delta between start and end snapshots, not duration', function () {
    Event::fake([JobMetricsCompleted::class]);

    // Simulate: at job start, worker has accumulated 1000ms user + 500ms system = 1500ms total
    // At job end, worker has accumulated 1050ms user + 520ms system = 1570ms total
    // Job CPU delta should be 70ms, NOT the wall-clock duration

    // Step 1: Cache the start CPU baseline (as JobProcessingListener would do)
    JobCpuSnapshotCache::store('job-42', 1500.0);

    // Step 2: Set up end-state source (1050 user + 520 system = 1570)
    $endSource = createJobTestSource(userCpuMs: 1050, systemCpuMs: 520);
    ProcessMetrics::setSource($endSource);

    // We need to start a tracker so stop() succeeds (use includeChildren: false so read() is used,
    // since our fake source returns failure for readProcessGroup)
    ProcessMetrics::start(pid: getmypid(), trackerId: 'job_job-42', includeChildren: false);
    usleep(10_000); // 10ms for measurable tracking window

    $capturedCpuTimeMs = null;
    $this->jobMetricsRepository->shouldReceive('recordCompletion')
        ->once()
        ->withArgs(function (
            string|int $jobId,
            string $jobClass,
            string $connection,
            string $queue,
            float $durationMs,
            float $memoryMb,
            float $cpuTimeMs,
        ) use (&$capturedCpuTimeMs) {
            $capturedCpuTimeMs = $cpuTimeMs;

            return true;
        });

    $this->workerHeartbeatRepository->shouldReceive('recordHeartbeat')->once();

    $job = Mockery::mock(QueueJob::class);
    $job->shouldReceive('getJobId')->andReturn('job-42');
    $job->shouldReceive('payload')->andReturn([
        'displayName' => 'App\\Jobs\\FastJob',
        'pushedAt' => microtime(true) - 0.5, // 500ms ago (simulated duration)
    ]);
    $job->shouldReceive('getQueue')->andReturn('default');

    $this->processedListener->handle(new JobProcessed('redis', $job));

    // CPU time should be 70ms (1570 - 1500), NOT ~500ms (the wall-clock duration)
    expect($capturedCpuTimeMs)->toBe(70.0);
})->group('functional');

it('reports zero cpu when no baseline was cached', function () {
    Event::fake([JobMetricsCompleted::class]);

    // No JobCpuSnapshotCache::store() — simulates missing baseline

    $source = createJobTestSource(userCpuMs: 5000, systemCpuMs: 1000);
    ProcessMetrics::setSource($source);
    ProcessMetrics::start(pid: getmypid(), trackerId: 'job_job-99', includeChildren: false);
    usleep(10_000);

    $capturedCpuTimeMs = null;
    $this->jobMetricsRepository->shouldReceive('recordCompletion')
        ->once()
        ->withArgs(function (
            string|int $jobId,
            string $jobClass,
            string $connection,
            string $queue,
            float $durationMs,
            float $memoryMb,
            float $cpuTimeMs,
        ) use (&$capturedCpuTimeMs) {
            $capturedCpuTimeMs = $cpuTimeMs;

            return true;
        });

    $this->workerHeartbeatRepository->shouldReceive('recordHeartbeat')->once();

    $job = Mockery::mock(QueueJob::class);
    $job->shouldReceive('getJobId')->andReturn('job-99');
    $job->shouldReceive('payload')->andReturn([
        'displayName' => 'App\\Jobs\\SomeJob',
        'pushedAt' => microtime(true),
    ]);
    $job->shouldReceive('getQueue')->andReturn('default');

    $this->processedListener->handle(new JobProcessed('redis', $job));

    expect($capturedCpuTimeMs)->toBe(0.0);
})->group('functional');

it('cleans up cache entry after job completion', function () {
    Event::fake([JobMetricsCompleted::class]);

    JobCpuSnapshotCache::store('job-77', 2000.0);

    $source = createJobTestSource(userCpuMs: 2100, systemCpuMs: 50);
    ProcessMetrics::setSource($source);
    ProcessMetrics::start(pid: getmypid(), trackerId: 'job_job-77', includeChildren: false);
    usleep(10_000);

    $this->jobMetricsRepository->shouldReceive('recordCompletion')->once();
    $this->workerHeartbeatRepository->shouldReceive('recordHeartbeat')->once();

    $job = Mockery::mock(QueueJob::class);
    $job->shouldReceive('getJobId')->andReturn('job-77');
    $job->shouldReceive('payload')->andReturn([
        'displayName' => 'App\\Jobs\\CleanupJob',
        'pushedAt' => microtime(true),
    ]);
    $job->shouldReceive('getQueue')->andReturn('default');

    $this->processedListener->handle(new JobProcessed('redis', $job));

    // Cache entry should be cleaned up
    expect(JobCpuSnapshotCache::get('job-77'))->toBeNull();
})->group('functional');

it('cpu time is much less than duration for sleep-bound jobs', function () {
    Event::fake([JobMetricsCompleted::class]);

    // Simulate: worker had 3000ms CPU at start, 3005ms at end (only 5ms CPU used)
    // But wall-clock duration is 500ms (sleep-bound job)
    JobCpuSnapshotCache::store('job-sleep', 3000.0);

    $source = createJobTestSource(userCpuMs: 3003, systemCpuMs: 2);
    ProcessMetrics::setSource($source);
    ProcessMetrics::start(pid: getmypid(), trackerId: 'job_job-sleep', includeChildren: false);
    usleep(10_000);

    $capturedCpuTimeMs = null;
    $capturedDurationMs = null;
    $this->jobMetricsRepository->shouldReceive('recordCompletion')
        ->once()
        ->withArgs(function (
            string|int $jobId,
            string $jobClass,
            string $connection,
            string $queue,
            float $durationMs,
            float $memoryMb,
            float $cpuTimeMs,
        ) use (&$capturedCpuTimeMs, &$capturedDurationMs) {
            $capturedCpuTimeMs = $cpuTimeMs;
            $capturedDurationMs = $durationMs;

            return true;
        });

    $this->workerHeartbeatRepository->shouldReceive('recordHeartbeat')->once();

    $job = Mockery::mock(QueueJob::class);
    $job->shouldReceive('getJobId')->andReturn('job-sleep');
    $job->shouldReceive('payload')->andReturn([
        'displayName' => 'App\\Jobs\\SleepJob',
        'pushedAt' => microtime(true) - 0.5, // 500ms wall-clock duration
    ]);
    $job->shouldReceive('getQueue')->andReturn('default');

    $this->processedListener->handle(new JobProcessed('redis', $job));

    // CPU time should be 5ms (3005 - 3000), far less than ~500ms duration
    expect($capturedCpuTimeMs)->toBe(5.0);
    expect($capturedDurationMs)->toBeGreaterThan(400.0); // ~500ms wall
    expect($capturedCpuTimeMs)->toBeLessThan($capturedDurationMs * 0.1); // CPU < 10% of duration
})->group('functional');
