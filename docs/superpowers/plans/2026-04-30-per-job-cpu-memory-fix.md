# Fix Per-Job CPU Metrics Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix per-job CPU time measurement so it reports actual CPU consumed (not wall-clock duration), enabling accurate downstream capacity planning in `cboxdk/laravel-queue-autoscale`.

**Architecture:** Replace the broken `delta->cpuUsagePercentage()` round-trip with direct CPU time deltas: snapshot cumulative `cpuTimes.user + cpuTimes.system` at job start, snapshot again at job end, report the difference. Keep `ProcessMetrics::start/stop` running for peak memory tracking (unchanged). New `JobCpuSnapshotCache` stores per-job CPU baselines in-process.

**Tech Stack:** PHP 8.3+, Pest 4.0 (test framework), `cboxdk/system-metrics` v3.0 (`ProcessMetrics` API)

---

## File Structure

| File | Role |
|------|------|
| `src/Support/JobCpuSnapshotCache.php` | **New.** Static in-memory cache storing cumulative CPU time at job start, keyed by job ID. Consumed and cleaned up at job end. |
| `src/Listeners/JobProcessingListener.php` | **Modify.** After `ProcessMetrics::start()`, take a snapshot and cache the CPU baseline via `JobCpuSnapshotCache`. |
| `src/Listeners/JobProcessedListener.php` | **Modify.** Replace `delta->cpuUsagePercentage()` math with direct CPU time delta from cache. |
| `src/Listeners/JobFailedListener.php` | **Modify.** Same CPU delta fix as `JobProcessedListener`. |
| `tests/Unit/Support/JobCpuSnapshotCacheTest.php` | **New.** Unit tests for the cache class. |
| `tests/Unit/Listeners/JobCpuDeltaTest.php` | **New.** Tests proving CPU time != duration, using faked ProcessMetrics sources. |
| `tests/Unit/ProcessMetricsIntegrationTest.php` | **Modify.** Update the CPU calculation test to use the new delta approach. |
| `tests/Feature/ChildProcessTrackingTest.php` | **Modify.** Update CPU formula in the compatibility test. |

---

### Task 1: Create `JobCpuSnapshotCache`

**Files:**
- Create: `src/Support/JobCpuSnapshotCache.php`
- Test: `tests/Unit/Support/JobCpuSnapshotCacheTest.php`

- [ ] **Step 1: Write the failing tests**

Create `tests/Unit/Support/JobCpuSnapshotCacheTest.php`:

```php
<?php

declare(strict_types=1);

use Cbox\LaravelQueueMetrics\Support\JobCpuSnapshotCache;

beforeEach(function () {
    JobCpuSnapshotCache::reset();
});

it('returns null for unknown job', function () {
    expect(JobCpuSnapshotCache::get('nonexistent'))->toBeNull();
})->group('functional');

it('stores and retrieves cpu time for a job', function () {
    JobCpuSnapshotCache::store('job-123', 4500.0);

    expect(JobCpuSnapshotCache::get('job-123'))->toBe(4500.0);
})->group('functional');

it('isolates entries by job id', function () {
    JobCpuSnapshotCache::store('job-a', 1000.0);
    JobCpuSnapshotCache::store('job-b', 2000.0);

    expect(JobCpuSnapshotCache::get('job-a'))->toBe(1000.0);
    expect(JobCpuSnapshotCache::get('job-b'))->toBe(2000.0);
})->group('functional');

it('forget removes a single entry', function () {
    JobCpuSnapshotCache::store('job-123', 4500.0);
    JobCpuSnapshotCache::store('job-456', 5500.0);

    JobCpuSnapshotCache::forget('job-123');

    expect(JobCpuSnapshotCache::get('job-123'))->toBeNull();
    expect(JobCpuSnapshotCache::get('job-456'))->toBe(5500.0);
})->group('functional');

it('reset clears all entries', function () {
    JobCpuSnapshotCache::store('job-a', 1000.0);
    JobCpuSnapshotCache::store('job-b', 2000.0);

    JobCpuSnapshotCache::reset();

    expect(JobCpuSnapshotCache::get('job-a'))->toBeNull();
    expect(JobCpuSnapshotCache::get('job-b'))->toBeNull();
})->group('functional');
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/pest tests/Unit/Support/JobCpuSnapshotCacheTest.php`
Expected: FAIL — class `JobCpuSnapshotCache` not found.

- [ ] **Step 3: Implement `JobCpuSnapshotCache`**

Create `src/Support/JobCpuSnapshotCache.php`:

```php
<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueMetrics\Support;

/**
 * In-memory cache of CPU time snapshots at job start for per-job CPU delta calculation.
 *
 * Stores the worker's cumulative CPU time (user + system) when a job begins processing.
 * At job completion, the listener reads this value and computes
 * (endCpuTimeMs - startCpuTimeMs) for the actual CPU time consumed by the job.
 *
 * Entries are consumed via forget() when the job completes or fails.
 * No TTL needed — job lifecycle is bounded within a single process.
 *
 * @internal
 */
final class JobCpuSnapshotCache
{
    /** @var array<string, float> */
    private static array $snapshots = [];

    /**
     * Get the cached CPU time baseline for a job, or null if not tracked.
     */
    public static function get(string $jobId): ?float
    {
        return self::$snapshots[$jobId] ?? null;
    }

    /**
     * Store the cumulative CPU time (ms) at job start.
     */
    public static function store(string $jobId, float $cpuTimeMs): void
    {
        self::$snapshots[$jobId] = $cpuTimeMs;
    }

    /**
     * Remove the entry for a completed/failed job.
     */
    public static function forget(string $jobId): void
    {
        unset(self::$snapshots[$jobId]);
    }

    /**
     * Clear all entries. For use in tests only.
     */
    public static function reset(): void
    {
        self::$snapshots = [];
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/pest tests/Unit/Support/JobCpuSnapshotCacheTest.php`
Expected: All 5 tests PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Support/JobCpuSnapshotCache.php tests/Unit/Support/JobCpuSnapshotCacheTest.php
git commit -m "feat: add JobCpuSnapshotCache for per-job CPU delta tracking (#16)"
```

---

### Task 2: Update `JobProcessingListener` to cache CPU baseline

**Files:**
- Modify: `src/Listeners/JobProcessingListener.php:30-38`

- [ ] **Step 1: Modify `JobProcessingListener` to snapshot CPU at job start**

In `src/Listeners/JobProcessingListener.php`, add the import for `JobCpuSnapshotCache` and `ProcessMetrics::snapshot()` call after the existing `ProcessMetrics::start()` block.

Add import at the top (after the existing `ProcessMetrics` import on line 10):

```php
use Cbox\LaravelQueueMetrics\Support\JobCpuSnapshotCache;
```

Replace lines 30-38 (the `ProcessMetrics::start` block) with:

```php
        // Start tracking process metrics for this job (including child processes)
        $pid = getmypid();
        if ($pid !== false) {
            ProcessMetrics::start(
                pid: $pid,
                trackerId: "job_{$jobId}",
                includeChildren: true
            );

            // Cache CPU baseline for accurate per-job CPU time delta
            $snapshotResult = ProcessMetrics::snapshot($pid);
            if ($snapshotResult->isSuccess()) {
                $cpuTimes = $snapshotResult->getValue()->resources->cpuTimes;
                $totalCpuTimeMs = (float) ($cpuTimes->user + $cpuTimes->system);
                JobCpuSnapshotCache::store($jobId, $totalCpuTimeMs);
            }
        }
```

- [ ] **Step 2: Run existing listener tests to verify no regressions**

Run: `vendor/bin/pest tests/Unit/Listeners/ tests/Feature/EventListenersTest.php`
Expected: All existing tests PASS (no behavior change for downstream code).

- [ ] **Step 3: Commit**

```bash
git add src/Listeners/JobProcessingListener.php
git commit -m "feat: cache CPU baseline at job start for delta measurement (#16)"
```

---

### Task 3: Fix CPU calculation in `JobProcessedListener`

**Files:**
- Modify: `src/Listeners/JobProcessedListener.php:47-62`

- [ ] **Step 1: Update `JobProcessedListener` CPU calculation**

Add import at top (after line 12, the `DebouncedJobTracker` import):

```php
use Cbox\LaravelQueueMetrics\Support\JobCpuSnapshotCache;
```

Replace lines 47-62 (the metrics extraction block) with:

```php
        $memoryMb = memory_get_peak_usage(true) / 1024 / 1024;
        $cpuTimeMs = 0.0;

        if ($metricsResult->isSuccess()) {
            $metrics = $metricsResult->getValue();

            // Peak memory during job window (for OOM safeguarding)
            $memoryMb = $metrics->peak->memoryRssBytes / 1024 / 1024;

            // CPU time: delta between cumulative CPU times at end vs start
            $endCpuTimes = $metrics->current->cpuTimes;
            $endCpuTimeMs = (float) ($endCpuTimes->user + $endCpuTimes->system);
            $startCpuTimeMs = JobCpuSnapshotCache::get($jobId);

            if ($startCpuTimeMs !== null) {
                $cpuTimeMs = max(0.0, $endCpuTimeMs - $startCpuTimeMs);
            }
        }

        JobCpuSnapshotCache::forget($jobId);
```

- [ ] **Step 2: Run existing listener tests**

Run: `vendor/bin/pest tests/Unit/Listeners/`
Expected: All existing tests PASS.

- [ ] **Step 3: Commit**

```bash
git add src/Listeners/JobProcessedListener.php
git commit -m "fix: use CPU time delta instead of percentage for per-job metrics (#16)"
```

---

### Task 4: Fix CPU calculation in `JobFailedListener`

**Files:**
- Modify: `src/Listeners/JobFailedListener.php:40-51`

- [ ] **Step 1: Update `JobFailedListener` CPU calculation**

Add import at top (after line 12, the `HorizonDetector` import):

```php
use Cbox\LaravelQueueMetrics\Support\JobCpuSnapshotCache;
```

Replace lines 40-51 (the metrics extraction block) with:

```php
        $memoryMb = memory_get_peak_usage(true) / 1024 / 1024;
        $cpuTimeMs = 0.0;

        if ($metricsResult->isSuccess()) {
            $metrics = $metricsResult->getValue();

            // Peak memory during job window (for OOM safeguarding)
            $memoryMb = $metrics->peak->memoryRssBytes / 1024 / 1024;

            // CPU time: delta between cumulative CPU times at end vs start
            $endCpuTimes = $metrics->current->cpuTimes;
            $endCpuTimeMs = (float) ($endCpuTimes->user + $endCpuTimes->system);
            $startCpuTimeMs = JobCpuSnapshotCache::get($jobId);

            if ($startCpuTimeMs !== null) {
                $cpuTimeMs = max(0.0, $endCpuTimeMs - $startCpuTimeMs);
            }
        }

        JobCpuSnapshotCache::forget($jobId);
```

- [ ] **Step 2: Run all listener tests**

Run: `vendor/bin/pest tests/Unit/Listeners/`
Expected: All tests PASS.

- [ ] **Step 3: Commit**

```bash
git add src/Listeners/JobFailedListener.php
git commit -m "fix: use CPU time delta in JobFailedListener (#16)"
```

---

### Task 5: Write CPU delta accuracy tests

**Files:**
- Create: `tests/Unit/Listeners/JobCpuDeltaTest.php`

- [ ] **Step 1: Write the CPU delta accuracy tests**

Create `tests/Unit/Listeners/JobCpuDeltaTest.php`:

```php
<?php

declare(strict_types=1);

use Cbox\LaravelQueueMetrics\Actions\RecordJobCompletionAction;
use Cbox\LaravelQueueMetrics\Actions\RecordWorkerHeartbeatAction;
use Cbox\LaravelQueueMetrics\Events\JobMetricsCompleted;
use Cbox\LaravelQueueMetrics\Listeners\JobProcessedListener;
use Cbox\LaravelQueueMetrics\Listeners\JobProcessingListener;
use Cbox\LaravelQueueMetrics\Actions\RecordJobStartAction;
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
use Illuminate\Queue\Events\JobProcessing;
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

    // We need to start a tracker so stop() succeeds
    ProcessMetrics::start(pid: getmypid(), trackerId: 'job_job-42', includeChildren: true);
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
    ProcessMetrics::start(pid: getmypid(), trackerId: 'job_job-99', includeChildren: true);
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
    ProcessMetrics::start(pid: getmypid(), trackerId: 'job_job-77', includeChildren: true);
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
    ProcessMetrics::start(pid: getmypid(), trackerId: 'job_job-sleep', includeChildren: true);
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
```

- [ ] **Step 2: Run the tests**

Run: `vendor/bin/pest tests/Unit/Listeners/JobCpuDeltaTest.php`
Expected: All 4 tests PASS.

- [ ] **Step 3: Commit**

```bash
git add tests/Unit/Listeners/JobCpuDeltaTest.php
git commit -m "test: add CPU delta accuracy tests for per-job metrics (#16)"
```

---

### Task 6: Update existing tests that use the old CPU formula

**Files:**
- Modify: `tests/Unit/ProcessMetricsIntegrationTest.php:78-102`
- Modify: `tests/Feature/ChildProcessTrackingTest.php:47-55,160-176`

- [ ] **Step 1: Update `ProcessMetricsIntegrationTest` CPU calculation test**

In `tests/Unit/ProcessMetricsIntegrationTest.php`, replace lines 78-102 (the `'calculates CPU time correctly from delta metrics'` test) with:

```php
it('calculates CPU time correctly using direct delta', function () {
    $pid = getmypid();
    $trackerId = 'cpu_test_'.uniqid();

    // Take start snapshot for CPU baseline
    $startSnapshot = ProcessMetrics::snapshot($pid);
    expect($startSnapshot->isSuccess())->toBeTrue();
    $startCpuTimes = $startSnapshot->getValue()->resources->cpuTimes;
    $startCpuTimeMs = (float) ($startCpuTimes->user + $startCpuTimes->system);

    ProcessMetrics::start(pid: $pid, trackerId: $trackerId, includeChildren: true);

    // Simulate CPU-intensive work
    $sum = 0;
    for ($i = 0; $i < 100000; $i++) {
        $sum += $i;
    }

    $statsResult = ProcessMetrics::stop($trackerId);
    expect($statsResult->isSuccess())->toBeTrue();

    $stats = $statsResult->getValue();

    // Calculate CPU time using the new delta approach (same as fixed JobProcessedListener)
    $endCpuTimes = $stats->current->cpuTimes;
    $endCpuTimeMs = (float) ($endCpuTimes->user + $endCpuTimes->system);
    $cpuTimeMs = max(0.0, $endCpuTimeMs - $startCpuTimeMs);

    expect($cpuTimeMs)->toBeGreaterThanOrEqual(0.0);
    expect($endCpuTimeMs)->toBeGreaterThanOrEqual($startCpuTimeMs);
})->group('functional');
```

- [ ] **Step 2: Update `ChildProcessTrackingTest` — first CPU formula (lines 47-55)**

In `tests/Feature/ChildProcessTrackingTest.php`, the test `'tracks child processes when job spawns subprocesses'` uses the old formula on lines 48-55. Replace those lines with:

```php
    $memoryMb = (float) ($stats->peak->memoryRssBytes / 1024 / 1024);
    expect($memoryMb)->toBeGreaterThan(0.0);

    // CPU time using direct delta (same as fixed JobProcessedListener)
    $endCpuTimes = $stats->current->cpuTimes;
    $endCpuTimeMs = (float) ($endCpuTimes->user + $endCpuTimes->system);
    // Note: in this test we don't have a start snapshot cached, so just verify the value is valid
    expect($endCpuTimeMs)->toBeGreaterThanOrEqual(0.0);
```

Also remove the now-unused `$cpuUsagePercent`, `$durationSeconds`, and `$cpuTimeMs` variables from that block. The assertions on lines 55-56 (`expect($cpuTimeMs)...` and `expect($durationSeconds)...`) should be replaced with:

```php
    expect($stats->delta->durationSeconds)->toBeGreaterThanOrEqual(0.0);
```

- [ ] **Step 3: Update `ChildProcessTrackingTest` — compatibility test (lines 160-176)**

In the test `'provides process resource usage compatible with JobProcessedListener calculations'`, replace lines 163-176 with:

```php
    // This is how the fixed JobProcessedListener uses the metrics:
    $memoryMb = (float) ($stats->peak->memoryRssBytes / 1024 / 1024);

    // CPU time via direct delta (start snapshot would be cached in real usage)
    $endCpuTimes = $stats->current->cpuTimes;
    $endCpuTimeMs = (float) ($endCpuTimes->user + $endCpuTimes->system);

    // Verify all values are usable
    expect($memoryMb)->toBeFloat()->toBeGreaterThan(0.0);
    expect($endCpuTimeMs)->toBeFloat()->toBeGreaterThanOrEqual(0.0);

    // These values would be passed to RecordJobCompletionAction
    expect($memoryMb)->toBeLessThan(10000.0); // Sanity check (< 10GB)
    expect($endCpuTimeMs)->toBeLessThan(3600000.0); // Sanity check (< 1 hour cumulative)

    unset($data);
```

- [ ] **Step 4: Run all updated tests**

Run: `vendor/bin/pest tests/Unit/ProcessMetricsIntegrationTest.php tests/Feature/ChildProcessTrackingTest.php`
Expected: All tests PASS.

- [ ] **Step 5: Commit**

```bash
git add tests/Unit/ProcessMetricsIntegrationTest.php tests/Feature/ChildProcessTrackingTest.php
git commit -m "test: update existing tests to use CPU delta approach (#16)"
```

---

### Task 7: Run full test suite and format

- [ ] **Step 1: Run pint to fix formatting**

Run: `vendor/bin/pint --dirty`

- [ ] **Step 2: Commit any formatting changes**

```bash
git add -u
git commit -m "style: apply pint formatting (#16)"
```

(Skip this commit if pint made no changes.)

- [ ] **Step 3: Run the full test suite**

Run: `vendor/bin/pest --exclude-group=redis`
Expected: All tests PASS. No regressions.

If any tests fail, investigate and fix before proceeding.
