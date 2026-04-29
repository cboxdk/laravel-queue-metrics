<?php

declare(strict_types=1);

use Cbox\LaravelQueueMetrics\Actions\RecordWorkerHeartbeatAction;
use Cbox\LaravelQueueMetrics\Enums\WorkerState;
use Cbox\LaravelQueueMetrics\Repositories\Contracts\WorkerHeartbeatRepository;
use Cbox\LaravelQueueMetrics\Support\CpuSnapshotCache;
use Cbox\SystemMetrics\Contracts\ProcessMetricsSource;
use Cbox\SystemMetrics\DTO\Metrics\Cpu\CpuTimes;
use Cbox\SystemMetrics\DTO\Metrics\Process\ProcessResourceUsage;
use Cbox\SystemMetrics\DTO\Metrics\Process\ProcessSnapshot;
use Cbox\SystemMetrics\DTO\Result;
use Cbox\SystemMetrics\Exceptions\SystemMetricsException;
use Cbox\SystemMetrics\ProcessMetrics;

beforeEach(function () {
    $this->repository = Mockery::mock(WorkerHeartbeatRepository::class);
    $this->action = new RecordWorkerHeartbeatAction($this->repository);

    config(['queue-metrics.enabled' => true]);

    CpuSnapshotCache::reset();
});

afterEach(function () {
    ProcessMetrics::setSource(null);
    CpuSnapshotCache::reset();
    Mockery::close();
});

it('reports 0% cpu on first heartbeat (establishing baseline)', function () {
    $source = createFakeProcessSource(userCpuMs: 5000, systemCpuMs: 2000);
    ProcessMetrics::setSource($source);

    $this->repository->shouldReceive('recordHeartbeat')
        ->once()
        ->withArgs(function (
            string $workerId,
            string $connection,
            string $queue,
            WorkerState $state,
            $currentJobId,
            $currentJobClass,
            int $pid,
            string $hostname,
            float $memoryUsageMb,
            float $cpuUsagePercent,
        ) {
            // First heartbeat has no previous snapshot to compare against
            expect($cpuUsagePercent)->toBe(0.0);

            return true;
        });

    $this->action->execute(
        workerId: 'worker-1',
        connection: 'redis',
        queue: 'default',
        state: WorkerState::IDLE,
    );
})->group('functional');

it('calculates cpu percentage as delta between heartbeats', function () {
    // First heartbeat: 1000ms user + 500ms system = 1500ms total CPU
    $source = createFakeProcessSource(userCpuMs: 1000, systemCpuMs: 500);
    ProcessMetrics::setSource($source);

    $this->repository->shouldReceive('recordHeartbeat')->once();
    $this->action->execute(
        workerId: 'worker-1',
        connection: 'redis',
        queue: 'default',
        state: WorkerState::IDLE,
    );

    // Simulate 2 seconds of wall time passing
    usleep(100_000); // 100ms minimum for measurable delta

    // Second heartbeat: 1200ms user + 600ms system = 1800ms total CPU
    // Delta = 300ms CPU in ~100ms wall time
    $source2 = createFakeProcessSource(userCpuMs: 1200, systemCpuMs: 600);
    ProcessMetrics::setSource($source2);

    $this->repository->shouldReceive('recordHeartbeat')
        ->once()
        ->withArgs(function (
            string $workerId,
            string $connection,
            string $queue,
            WorkerState $state,
            $currentJobId,
            $currentJobClass,
            int $pid,
            string $hostname,
            float $memoryUsageMb,
            float $cpuUsagePercent,
        ) {
            // Delta: 300ms CPU / ~100ms wall = ~300% (multi-core)
            // Exact value depends on actual wall time, just verify it's > 0
            expect($cpuUsagePercent)->toBeGreaterThan(0.0);

            return true;
        });

    $this->action->execute(
        workerId: 'worker-1',
        connection: 'redis',
        queue: 'default',
        state: WorkerState::BUSY,
    );
})->group('functional');

it('does not cap cpu usage at 100% for container burst scenarios', function () {
    // First heartbeat: baseline
    $source = createFakeProcessSource(userCpuMs: 0, systemCpuMs: 0);
    ProcessMetrics::setSource($source);

    $this->repository->shouldReceive('recordHeartbeat')->once();
    $this->action->execute(
        workerId: 'worker-burst',
        connection: 'redis',
        queue: 'default',
        state: WorkerState::IDLE,
    );

    usleep(50_000); // 50ms wall time

    // Second heartbeat: heavy multi-core usage
    // 800ms user + 200ms system = 1000ms CPU in ~50ms wall → ~2000%
    $source2 = createFakeProcessSource(userCpuMs: 800, systemCpuMs: 200);
    ProcessMetrics::setSource($source2);

    $this->repository->shouldReceive('recordHeartbeat')
        ->once()
        ->withArgs(function (
            string $workerId,
            string $connection,
            string $queue,
            WorkerState $state,
            $currentJobId,
            $currentJobClass,
            int $pid,
            string $hostname,
            float $memoryUsageMb,
            float $cpuUsagePercent,
        ) {
            // Must exceed 100% — the old cap was hiding a bug
            expect($cpuUsagePercent)->toBeGreaterThan(100.0);

            return true;
        });

    $this->action->execute(
        workerId: 'worker-burst',
        connection: 'redis',
        queue: 'default',
        state: WorkerState::BUSY,
    );
})->group('functional');

it('tracks separate deltas per worker id', function () {
    // Worker A baseline: 1000ms CPU
    $sourceA = createFakeProcessSource(userCpuMs: 1000, systemCpuMs: 0);
    ProcessMetrics::setSource($sourceA);

    $this->repository->shouldReceive('recordHeartbeat')->once();
    $this->action->execute(
        workerId: 'worker-a',
        connection: 'redis',
        queue: 'default',
        state: WorkerState::IDLE,
    );

    // Worker B baseline: 5000ms CPU
    $sourceB = createFakeProcessSource(userCpuMs: 5000, systemCpuMs: 0);
    ProcessMetrics::setSource($sourceB);

    $this->repository->shouldReceive('recordHeartbeat')->once();
    $this->action->execute(
        workerId: 'worker-b',
        connection: 'redis',
        queue: 'default',
        state: WorkerState::IDLE,
    );

    usleep(50_000);

    // Worker A second heartbeat: 1100ms CPU (delta = 100ms)
    $sourceA2 = createFakeProcessSource(userCpuMs: 1100, systemCpuMs: 0);
    ProcessMetrics::setSource($sourceA2);

    $capturedCpuA = null;
    $this->repository->shouldReceive('recordHeartbeat')
        ->once()
        ->withArgs(function (
            string $workerId,
            string $connection,
            string $queue,
            WorkerState $state,
            $currentJobId,
            $currentJobClass,
            int $pid,
            string $hostname,
            float $memoryUsageMb,
            float $cpuUsagePercent,
        ) use (&$capturedCpuA) {
            $capturedCpuA = $cpuUsagePercent;

            return true;
        });

    $this->action->execute(
        workerId: 'worker-a',
        connection: 'redis',
        queue: 'default',
        state: WorkerState::BUSY,
    );

    // Worker B second heartbeat: 5500ms CPU (delta = 500ms)
    $sourceB2 = createFakeProcessSource(userCpuMs: 5500, systemCpuMs: 0);
    ProcessMetrics::setSource($sourceB2);

    $capturedCpuB = null;
    $this->repository->shouldReceive('recordHeartbeat')
        ->once()
        ->withArgs(function (
            string $workerId,
            string $connection,
            string $queue,
            WorkerState $state,
            $currentJobId,
            $currentJobClass,
            int $pid,
            string $hostname,
            float $memoryUsageMb,
            float $cpuUsagePercent,
        ) use (&$capturedCpuB) {
            $capturedCpuB = $cpuUsagePercent;

            return true;
        });

    $this->action->execute(
        workerId: 'worker-b',
        connection: 'redis',
        queue: 'default',
        state: WorkerState::BUSY,
    );

    // Worker B should have ~5x higher CPU usage than worker A
    // (500ms delta vs 100ms delta in same wall time)
    expect($capturedCpuB)->toBeGreaterThan($capturedCpuA);
})->group('functional');

/**
 * Create a fake ProcessMetricsSource that returns a snapshot with given CPU times.
 */
function createFakeProcessSource(int $userCpuMs, int $systemCpuMs): ProcessMetricsSource
{
    return new class($userCpuMs, $systemCpuMs) implements ProcessMetricsSource
    {
        public function __construct(
            private readonly int $userCpuMs,
            private readonly int $systemCpuMs,
        ) {}

        public function read(int $pid): Result
        {
            $snapshot = new ProcessSnapshot(
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
                    memoryRssBytes: 64 * 1024 * 1024,
                    memoryVmsBytes: 128 * 1024 * 1024,
                    threadCount: 1,
                    openFileDescriptors: 10,
                ),
                timestamp: new DateTimeImmutable,
            );

            return Result::success($snapshot);
        }

        public function readProcessGroup(int $rootPid): Result
        {
            return Result::failure(
                new SystemMetricsException('Not implemented in test fake')
            );
        }
    };
}
