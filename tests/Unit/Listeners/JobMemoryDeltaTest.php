<?php

declare(strict_types=1);

use Cbox\LaravelQueueMetrics\Actions\RecordJobCompletionAction;
use Cbox\LaravelQueueMetrics\Actions\RecordWorkerHeartbeatAction;
use Cbox\LaravelQueueMetrics\Events\JobMetricsCompleted;
use Cbox\LaravelQueueMetrics\Listeners\JobProcessedListener;
use Cbox\LaravelQueueMetrics\Repositories\Contracts\JobMetricsRepository;
use Cbox\LaravelQueueMetrics\Repositories\Contracts\WorkerHeartbeatRepository;
use Cbox\LaravelQueueMetrics\Support\JobCpuSnapshotCache;
use Cbox\LaravelQueueMetrics\Support\JobMemorySnapshotCache;
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
 * Helper to create a fake ProcessMetricsSource with known memory values.
 */
function createMemoryTestSource(int $memoryRssBytes, int $userCpuMs = 1000, int $systemCpuMs = 500): ProcessMetricsSource
{
    return new class($memoryRssBytes, $userCpuMs, $systemCpuMs) implements ProcessMetricsSource
    {
        public function __construct(
            private readonly int $memoryRssBytes,
            private readonly int $userCpuMs,
            private readonly int $systemCpuMs,
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
    JobMemorySnapshotCache::reset();

    $this->jobMetricsRepository = Mockery::mock(JobMetricsRepository::class);
    $this->workerHeartbeatRepository = Mockery::mock(WorkerHeartbeatRepository::class);

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
    JobMemorySnapshotCache::reset();
    Mockery::close();
});

it('reports memory as delta between peak and baseline, not raw peak RSS', function () {
    Event::fake([JobMetricsCompleted::class]);

    // Baseline: worker process at 64 MB RSS when job starts
    JobCpuSnapshotCache::store('job-mem-1', 1000.0);
    JobMemorySnapshotCache::store('job-mem-1', 64.0);

    // Peak RSS during job: 72 MB (job allocated ~8 MB)
    $source = createMemoryTestSource(
        memoryRssBytes: 72 * 1024 * 1024,
        userCpuMs: 1050,
        systemCpuMs: 500,
    );
    ProcessMetrics::setSource($source);
    ProcessMetrics::start(pid: getmypid(), trackerId: 'job_job-mem-1', includeChildren: false);
    usleep(10_000);

    $capturedMemoryMb = null;
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
        ) use (&$capturedMemoryMb) {
            $capturedMemoryMb = $memoryMb;

            return true;
        });

    $this->workerHeartbeatRepository->shouldReceive('recordHeartbeat')->once();

    $job = Mockery::mock(QueueJob::class);
    $job->shouldReceive('getJobId')->andReturn('job-mem-1');
    $job->shouldReceive('payload')->andReturn([
        'displayName' => 'App\\Jobs\\SmallAllocationJob',
        'pushedAt' => microtime(true),
    ]);
    $job->shouldReceive('getQueue')->andReturn('default');

    $this->processedListener->handle(new JobProcessed('redis', $job));

    // Memory should be 8 MB (72 - 64), NOT 72 MB (raw peak RSS)
    expect($capturedMemoryMb)->toBe(8.0);
})->group('functional');

it('reports near-zero memory for sleep-bound jobs', function () {
    Event::fake([JobMetricsCompleted::class]);

    // Baseline and peak are both 64 MB — job allocated nothing
    JobCpuSnapshotCache::store('job-sleep-mem', 3000.0);
    JobMemorySnapshotCache::store('job-sleep-mem', 64.0);

    $source = createMemoryTestSource(
        memoryRssBytes: 64 * 1024 * 1024,
        userCpuMs: 3003,
        systemCpuMs: 2,
    );
    ProcessMetrics::setSource($source);
    ProcessMetrics::start(pid: getmypid(), trackerId: 'job_job-sleep-mem', includeChildren: false);
    usleep(10_000);

    $capturedMemoryMb = null;
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
        ) use (&$capturedMemoryMb) {
            $capturedMemoryMb = $memoryMb;

            return true;
        });

    $this->workerHeartbeatRepository->shouldReceive('recordHeartbeat')->once();

    $job = Mockery::mock(QueueJob::class);
    $job->shouldReceive('getJobId')->andReturn('job-sleep-mem');
    $job->shouldReceive('payload')->andReturn([
        'displayName' => 'App\\Jobs\\SleepJob',
        'pushedAt' => microtime(true) - 0.15,
    ]);
    $job->shouldReceive('getQueue')->andReturn('default');

    $this->processedListener->handle(new JobProcessed('redis', $job));

    // Sleep job: 0 MB incremental (64 - 64)
    expect($capturedMemoryMb)->toBe(0.0);
})->group('functional');

it('falls back to peak RSS when memory baseline is missing', function () {
    Event::fake([JobMetricsCompleted::class]);

    // CPU baseline exists but memory baseline does NOT
    JobCpuSnapshotCache::store('job-no-mem-baseline', 1000.0);
    // No JobMemorySnapshotCache::store() call

    $source = createMemoryTestSource(
        memoryRssBytes: 80 * 1024 * 1024,
        userCpuMs: 1010,
        systemCpuMs: 500,
    );
    ProcessMetrics::setSource($source);
    ProcessMetrics::start(pid: getmypid(), trackerId: 'job_job-no-mem-baseline', includeChildren: false);
    usleep(10_000);

    $capturedMemoryMb = null;
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
        ) use (&$capturedMemoryMb) {
            $capturedMemoryMb = $memoryMb;

            return true;
        });

    $this->workerHeartbeatRepository->shouldReceive('recordHeartbeat')->once();

    $job = Mockery::mock(QueueJob::class);
    $job->shouldReceive('getJobId')->andReturn('job-no-mem-baseline');
    $job->shouldReceive('payload')->andReturn([
        'displayName' => 'App\\Jobs\\FallbackJob',
        'pushedAt' => microtime(true),
    ]);
    $job->shouldReceive('getQueue')->andReturn('default');

    $this->processedListener->handle(new JobProcessed('redis', $job));

    // Falls back to raw peak RSS: 80 MB
    expect($capturedMemoryMb)->toBe(80.0);
})->group('functional');

it('cleans up memory cache entry after job completion', function () {
    Event::fake([JobMetricsCompleted::class]);

    JobCpuSnapshotCache::store('job-cleanup', 2000.0);
    JobMemorySnapshotCache::store('job-cleanup', 64.0);

    $source = createMemoryTestSource(
        memoryRssBytes: 70 * 1024 * 1024,
        userCpuMs: 2100,
        systemCpuMs: 50,
    );
    ProcessMetrics::setSource($source);
    ProcessMetrics::start(pid: getmypid(), trackerId: 'job_job-cleanup', includeChildren: false);
    usleep(10_000);

    $this->jobMetricsRepository->shouldReceive('recordCompletion')->once();
    $this->workerHeartbeatRepository->shouldReceive('recordHeartbeat')->once();

    $job = Mockery::mock(QueueJob::class);
    $job->shouldReceive('getJobId')->andReturn('job-cleanup');
    $job->shouldReceive('payload')->andReturn([
        'displayName' => 'App\\Jobs\\CleanupJob',
        'pushedAt' => microtime(true),
    ]);
    $job->shouldReceive('getQueue')->andReturn('default');

    $this->processedListener->handle(new JobProcessed('redis', $job));

    // Both caches should be cleaned up
    expect(JobCpuSnapshotCache::get('job-cleanup'))->toBeNull();
    expect(JobMemorySnapshotCache::get('job-cleanup'))->toBeNull();
})->group('functional');

it('two jobs with different allocation sizes report distinguishable memory values', function () {
    Event::fake([JobMetricsCompleted::class]);

    // Job A: allocates ~8 MB (baseline 64, peak 72)
    JobCpuSnapshotCache::store('job-small', 1000.0);
    JobMemorySnapshotCache::store('job-small', 64.0);

    $sourceSmall = createMemoryTestSource(
        memoryRssBytes: 72 * 1024 * 1024,
        userCpuMs: 1010,
        systemCpuMs: 500,
    );
    ProcessMetrics::setSource($sourceSmall);
    ProcessMetrics::start(pid: getmypid(), trackerId: 'job_job-small', includeChildren: false);
    usleep(10_000);

    $capturedMemorySmall = null;
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
        ) use (&$capturedMemorySmall) {
            $capturedMemorySmall = $memoryMb;

            return true;
        });

    $this->workerHeartbeatRepository->shouldReceive('recordHeartbeat')->once();

    $jobSmall = Mockery::mock(QueueJob::class);
    $jobSmall->shouldReceive('getJobId')->andReturn('job-small');
    $jobSmall->shouldReceive('payload')->andReturn([
        'displayName' => 'App\\Jobs\\SmallJob',
        'pushedAt' => microtime(true),
    ]);
    $jobSmall->shouldReceive('getQueue')->andReturn('default');

    $this->processedListener->handle(new JobProcessed('redis', $jobSmall));

    // Reset for Job B
    ProcessMetrics::setSource(null);

    // Job B: allocates ~200 MB (baseline 64, peak 264)
    JobCpuSnapshotCache::store('job-large', 1000.0);
    JobMemorySnapshotCache::store('job-large', 64.0);

    $sourceLarge = createMemoryTestSource(
        memoryRssBytes: 264 * 1024 * 1024,
        userCpuMs: 1500,
        systemCpuMs: 800,
    );
    ProcessMetrics::setSource($sourceLarge);
    ProcessMetrics::start(pid: getmypid(), trackerId: 'job_job-large', includeChildren: false);
    usleep(10_000);

    $capturedMemoryLarge = null;
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
        ) use (&$capturedMemoryLarge) {
            $capturedMemoryLarge = $memoryMb;

            return true;
        });

    $this->workerHeartbeatRepository->shouldReceive('recordHeartbeat')->once();

    $jobLarge = Mockery::mock(QueueJob::class);
    $jobLarge->shouldReceive('getJobId')->andReturn('job-large');
    $jobLarge->shouldReceive('payload')->andReturn([
        'displayName' => 'App\\Jobs\\LargeJob',
        'pushedAt' => microtime(true),
    ]);
    $jobLarge->shouldReceive('getQueue')->andReturn('default');

    $this->processedListener->handle(new JobProcessed('redis', $jobLarge));

    // 8 MB vs 200 MB — clearly distinguishable
    expect($capturedMemorySmall)->toBe(8.0);
    expect($capturedMemoryLarge)->toBe(200.0);
    expect($capturedMemoryLarge)->toBeGreaterThan($capturedMemorySmall * 10);
})->group('functional');

it('clamps negative memory delta to zero', function () {
    Event::fake([JobMetricsCompleted::class]);

    // Edge case: RSS decreases during job (GC freed memory)
    // Baseline 80 MB, peak during job window is 72 MB
    JobCpuSnapshotCache::store('job-gc', 1000.0);
    JobMemorySnapshotCache::store('job-gc', 80.0);

    $source = createMemoryTestSource(
        memoryRssBytes: 72 * 1024 * 1024,
        userCpuMs: 1010,
        systemCpuMs: 500,
    );
    ProcessMetrics::setSource($source);
    ProcessMetrics::start(pid: getmypid(), trackerId: 'job_job-gc', includeChildren: false);
    usleep(10_000);

    $capturedMemoryMb = null;
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
        ) use (&$capturedMemoryMb) {
            $capturedMemoryMb = $memoryMb;

            return true;
        });

    $this->workerHeartbeatRepository->shouldReceive('recordHeartbeat')->once();

    $job = Mockery::mock(QueueJob::class);
    $job->shouldReceive('getJobId')->andReturn('job-gc');
    $job->shouldReceive('payload')->andReturn([
        'displayName' => 'App\\Jobs\\GcJob',
        'pushedAt' => microtime(true),
    ]);
    $job->shouldReceive('getQueue')->andReturn('default');

    $this->processedListener->handle(new JobProcessed('redis', $job));

    // Clamped to 0, not negative
    expect($capturedMemoryMb)->toBe(0.0);
})->group('functional');
