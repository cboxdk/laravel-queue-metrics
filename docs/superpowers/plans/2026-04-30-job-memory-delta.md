# Per-Job Memory Delta Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Change `memoryMb` from peak worker RSS to incremental job allocation by mirroring the CPU delta pattern from v3.0.1.

**Architecture:** Snapshot memory RSS at job start (`JobProcessingListener`), snapshot peak RSS at job end (`JobProcessedListener` / `JobFailedListener`), report the delta. New `JobMemorySnapshotCache` class mirrors `JobCpuSnapshotCache` with identical TTL eviction. No DTO, event, or repository contract changes.

**Tech Stack:** PHP 8.4, Pest 4, cboxdk/system-metrics ^3.0 (`ProcessMetrics`, `ProcessSnapshot`, `ProcessResourceUsage`)

---

## File Map

| File | Action | Responsibility |
|------|--------|----------------|
| `src/Support/JobMemorySnapshotCache.php` | Create | In-memory cache for per-job memory baselines with TTL eviction |
| `tests/Unit/Support/JobMemorySnapshotCacheTest.php` | Create | Cache store/get/forget/reset/eviction tests |
| `src/Listeners/JobProcessingListener.php` | Modify | Add memory baseline snapshot alongside CPU snapshot |
| `src/Listeners/JobProcessedListener.php` | Modify | Compute memory delta instead of raw peak RSS |
| `src/Listeners/JobFailedListener.php` | Modify | Same memory delta logic as JobProcessedListener |
| `tests/Unit/Listeners/JobMemoryDeltaTest.php` | Create | Memory delta accuracy, fallback, cleanup tests |
| `CHANGELOG.md` | Modify | Breaking change entry for semantic shift |

---

### Task 1: JobMemorySnapshotCache

**Files:**
- Create: `src/Support/JobMemorySnapshotCache.php`
- Test: `tests/Unit/Support/JobMemorySnapshotCacheTest.php`

- [ ] **Step 1: Write the cache tests**

Create `tests/Unit/Support/JobMemorySnapshotCacheTest.php`:

```php
<?php

declare(strict_types=1);

use Cbox\LaravelQueueMetrics\Support\JobMemorySnapshotCache;

beforeEach(function () {
    JobMemorySnapshotCache::reset();
});

it('returns null for unknown job', function () {
    expect(JobMemorySnapshotCache::get('nonexistent'))->toBeNull();
})->group('functional');

it('stores and retrieves memory for a job', function () {
    JobMemorySnapshotCache::store('job-123', 64.5);

    expect(JobMemorySnapshotCache::get('job-123'))->toBe(64.5);
})->group('functional');

it('isolates entries by job id', function () {
    JobMemorySnapshotCache::store('job-a', 50.0);
    JobMemorySnapshotCache::store('job-b', 120.0);

    expect(JobMemorySnapshotCache::get('job-a'))->toBe(50.0);
    expect(JobMemorySnapshotCache::get('job-b'))->toBe(120.0);
})->group('functional');

it('forget removes a single entry', function () {
    JobMemorySnapshotCache::store('job-123', 64.5);
    JobMemorySnapshotCache::store('job-456', 80.0);

    JobMemorySnapshotCache::forget('job-123');

    expect(JobMemorySnapshotCache::get('job-123'))->toBeNull();
    expect(JobMemorySnapshotCache::get('job-456'))->toBe(80.0);
})->group('functional');

it('reset clears all entries', function () {
    JobMemorySnapshotCache::store('job-a', 50.0);
    JobMemorySnapshotCache::store('job-b', 120.0);

    JobMemorySnapshotCache::reset();

    expect(JobMemorySnapshotCache::get('job-a'))->toBeNull();
    expect(JobMemorySnapshotCache::get('job-b'))->toBeNull();
})->group('functional');
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/pest tests/Unit/Support/JobMemorySnapshotCacheTest.php`
Expected: FAIL — class `JobMemorySnapshotCache` not found.

- [ ] **Step 3: Write the implementation**

Create `src/Support/JobMemorySnapshotCache.php`:

```php
<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueMetrics\Support;

/**
 * In-memory cache of memory RSS snapshots at job start for per-job memory delta calculation.
 *
 * Stores the worker's RSS (in MB) when a job begins processing.
 * At job completion, the listener reads this value and computes
 * (peakMemoryMb - startMemoryMb) for the incremental memory allocated by the job.
 *
 * Entries are consumed via forget() when the job completes or fails.
 * Stale entries (from jobs that never completed, e.g. killed workers)
 * are evicted on every store() call after MAX_AGE_SECONDS.
 *
 * @internal
 */
final class JobMemorySnapshotCache
{
    private const MAX_AGE_SECONDS = 600;

    /** @var array<string, array{memory_rss_mb: float, stored_at: float}> */
    private static array $snapshots = [];

    /**
     * Get the cached memory RSS baseline for a job, or null if not tracked.
     */
    public static function get(string $jobId): ?float
    {
        return isset(self::$snapshots[$jobId])
            ? self::$snapshots[$jobId]['memory_rss_mb']
            : null;
    }

    /**
     * Store the RSS memory (MB) at job start and evict stale entries.
     */
    public static function store(string $jobId, float $memoryRssMb): void
    {
        $now = microtime(true);

        self::$snapshots[$jobId] = [
            'memory_rss_mb' => $memoryRssMb,
            'stored_at' => $now,
        ];

        $cutoff = $now - self::MAX_AGE_SECONDS;

        foreach (self::$snapshots as $id => $snapshot) {
            if ($snapshot['stored_at'] < $cutoff) {
                unset(self::$snapshots[$id]);
            }
        }
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

Run: `vendor/bin/pest tests/Unit/Support/JobMemorySnapshotCacheTest.php`
Expected: 5 tests pass.

- [ ] **Step 5: Commit**

```bash
git add src/Support/JobMemorySnapshotCache.php tests/Unit/Support/JobMemorySnapshotCacheTest.php
git commit -m "feat: add JobMemorySnapshotCache for per-job memory delta"
```

---

### Task 2: Modify JobProcessingListener to snapshot memory baseline

**Files:**
- Modify: `src/Listeners/JobProcessingListener.php:40-46`

- [ ] **Step 1: Add memory snapshot alongside CPU snapshot**

In `src/Listeners/JobProcessingListener.php`, add the `use` import for `JobMemorySnapshotCache`:

```php
use Cbox\LaravelQueueMetrics\Support\JobMemorySnapshotCache;
```

Then modify the snapshot block (lines 40-46) to also cache memory baseline. Replace:

```php
            // Cache CPU baseline for accurate per-job CPU time delta
            $snapshotResult = ProcessMetrics::snapshot($pid);
            if ($snapshotResult->isSuccess()) {
                $cpuTimes = $snapshotResult->getValue()->resources->cpuTimes;
                $totalCpuTimeMs = (float) ($cpuTimes->user + $cpuTimes->system);
                JobCpuSnapshotCache::store($jobId, $totalCpuTimeMs);
            }
```

With:

```php
            // Cache CPU and memory baselines for accurate per-job delta calculation
            $snapshotResult = ProcessMetrics::snapshot($pid);
            if ($snapshotResult->isSuccess()) {
                $resources = $snapshotResult->getValue()->resources;
                $cpuTimes = $resources->cpuTimes;
                $totalCpuTimeMs = (float) ($cpuTimes->user + $cpuTimes->system);
                JobCpuSnapshotCache::store($jobId, $totalCpuTimeMs);
                JobMemorySnapshotCache::store($jobId, $resources->memoryRssBytes / 1024 / 1024);
            }
```

- [ ] **Step 2: Run existing CPU delta tests to verify no regression**

Run: `vendor/bin/pest tests/Unit/Listeners/JobCpuDeltaTest.php`
Expected: All 4 tests pass.

- [ ] **Step 3: Commit**

```bash
git add src/Listeners/JobProcessingListener.php
git commit -m "feat: snapshot memory baseline at job start for delta calculation"
```

---

### Task 3: Modify JobProcessedListener to compute memory delta

**Files:**
- Modify: `src/Listeners/JobProcessedListener.php:48-67`

- [ ] **Step 1: Add memory delta logic**

In `src/Listeners/JobProcessedListener.php`, add the `use` import:

```php
use Cbox\LaravelQueueMetrics\Support\JobMemorySnapshotCache;
```

Then replace the metrics extraction block (lines 48-67). Replace:

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

With:

```php
        $memoryMb = memory_get_peak_usage(true) / 1024 / 1024;
        $cpuTimeMs = 0.0;

        if ($metricsResult->isSuccess()) {
            $metrics = $metricsResult->getValue();

            // Memory: incremental allocation by this job (peak RSS minus baseline)
            $peakMemoryMb = $metrics->peak->memoryRssBytes / 1024 / 1024;
            $startMemoryMb = JobMemorySnapshotCache::get($jobId);

            if ($startMemoryMb !== null) {
                $memoryMb = max(0.0, $peakMemoryMb - $startMemoryMb);
            } else {
                $memoryMb = $peakMemoryMb; // fallback for missing baseline
            }

            // CPU time: delta between cumulative CPU times at end vs start
            $endCpuTimes = $metrics->current->cpuTimes;
            $endCpuTimeMs = (float) ($endCpuTimes->user + $endCpuTimes->system);
            $startCpuTimeMs = JobCpuSnapshotCache::get($jobId);

            if ($startCpuTimeMs !== null) {
                $cpuTimeMs = max(0.0, $endCpuTimeMs - $startCpuTimeMs);
            }
        }

        JobCpuSnapshotCache::forget($jobId);
        JobMemorySnapshotCache::forget($jobId);
```

- [ ] **Step 2: Run existing CPU delta tests to verify no regression**

Run: `vendor/bin/pest tests/Unit/Listeners/JobCpuDeltaTest.php`
Expected: All 4 tests pass.

- [ ] **Step 3: Commit**

```bash
git add src/Listeners/JobProcessedListener.php
git commit -m "feat: compute memory delta in JobProcessedListener"
```

---

### Task 4: Modify JobFailedListener to compute memory delta

**Files:**
- Modify: `src/Listeners/JobFailedListener.php:41-60`

- [ ] **Step 1: Add memory delta logic (same pattern as JobProcessedListener)**

In `src/Listeners/JobFailedListener.php`, add the `use` import:

```php
use Cbox\LaravelQueueMetrics\Support\JobMemorySnapshotCache;
```

Then replace the metrics extraction block (lines 41-60). Replace:

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

With:

```php
        $memoryMb = memory_get_peak_usage(true) / 1024 / 1024;
        $cpuTimeMs = 0.0;

        if ($metricsResult->isSuccess()) {
            $metrics = $metricsResult->getValue();

            // Memory: incremental allocation by this job (peak RSS minus baseline)
            $peakMemoryMb = $metrics->peak->memoryRssBytes / 1024 / 1024;
            $startMemoryMb = JobMemorySnapshotCache::get($jobId);

            if ($startMemoryMb !== null) {
                $memoryMb = max(0.0, $peakMemoryMb - $startMemoryMb);
            } else {
                $memoryMb = $peakMemoryMb; // fallback for missing baseline
            }

            // CPU time: delta between cumulative CPU times at end vs start
            $endCpuTimes = $metrics->current->cpuTimes;
            $endCpuTimeMs = (float) ($endCpuTimes->user + $endCpuTimes->system);
            $startCpuTimeMs = JobCpuSnapshotCache::get($jobId);

            if ($startCpuTimeMs !== null) {
                $cpuTimeMs = max(0.0, $endCpuTimeMs - $startCpuTimeMs);
            }
        }

        JobCpuSnapshotCache::forget($jobId);
        JobMemorySnapshotCache::forget($jobId);
```

- [ ] **Step 2: Run all existing listener tests to verify no regression**

Run: `vendor/bin/pest tests/Unit/Listeners/`
Expected: All tests pass.

- [ ] **Step 3: Commit**

```bash
git add src/Listeners/JobFailedListener.php
git commit -m "feat: compute memory delta in JobFailedListener"
```

---

### Task 5: Memory delta tests

**Files:**
- Create: `tests/Unit/Listeners/JobMemoryDeltaTest.php`

- [ ] **Step 1: Write the memory delta tests**

Create `tests/Unit/Listeners/JobMemoryDeltaTest.php`. This file reuses the `createJobTestSource()` helper already defined in `JobCpuDeltaTest.php` — Pest loads all test files, so the helper is available. But to keep tests self-contained and avoid coupling, define a local helper:

```php
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
```

- [ ] **Step 2: Run the memory delta tests**

Run: `vendor/bin/pest tests/Unit/Listeners/JobMemoryDeltaTest.php`
Expected: All 6 tests pass.

- [ ] **Step 3: Run all listener tests to verify no regression**

Run: `vendor/bin/pest tests/Unit/Listeners/`
Expected: All tests pass (including existing CPU delta tests).

- [ ] **Step 4: Commit**

```bash
git add tests/Unit/Listeners/JobMemoryDeltaTest.php
git commit -m "test: add memory delta accuracy tests"
```

---

### Task 6: CHANGELOG entry

**Files:**
- Modify: `CHANGELOG.md:1-17`

- [ ] **Step 1: Add breaking change entry at top of changelog**

Insert after line 2 (`All notable changes...`) and before the v3.0.1 entry. Add:

```markdown

## v3.1.0 - Per-job memory now reports incremental allocation, not peak RSS - UNRELEASED

### Breaking Changes

- **`memoryMb` semantics changed** — per-job memory metric now reports incremental memory allocated by the job itself (peak RSS minus baseline RSS at job start), not the entire worker process's peak RSS. A `usleep(150ms)` job now correctly reports ~0 MB instead of ~50-200 MB. (#18)
- **Threshold-based alerts on `memoryMb` need recalibration** — values will be dramatically smaller. Worker-level peak memory remains available via `WorkerHeartbeat.peakMemoryUsageMb` for OOM safeguarding.
- **Same fix applied to `JobFailedListener`** — failed jobs now also report incremental memory. (#18)

### What's New

- **`JobMemorySnapshotCache`** — in-memory cache for per-job memory baselines, with 600-second TTL eviction matching `JobCpuSnapshotCache`. Prevents unbounded growth from never-completing jobs. (#18)

### Migration Guide

If you have alerts or dashboards that threshold on `memoryMb`:
- Old: `memoryMb ≈ 50-200 MB` (entire worker process RSS)
- New: `memoryMb ≈ 0-N MB` (only what the job allocated)
- For OOM monitoring, use `WorkerHeartbeat.peakMemoryUsageMb` instead

```

- [ ] **Step 2: Commit**

```bash
git add CHANGELOG.md
git commit -m "docs: add v3.1.0 CHANGELOG with breaking memory semantic change"
```

---

### Task 7: Final verification

- [ ] **Step 1: Run pint to fix formatting**

Run: `vendor/bin/pint --dirty`

If any files were changed, stage and commit:
```bash
git add -A
git commit -m "style: apply pint formatting"
```

- [ ] **Step 2: Run full test suite**

Run: `vendor/bin/pest`
Expected: All tests pass with no regressions.

- [ ] **Step 3: Verify all acceptance criteria**

Verify against issue #18 criteria:
1. Sleep-bound job reports `memoryMb < 10 MB` — covered by "reports near-zero memory for sleep-bound jobs" test
2. Two different-allocation jobs report distinguishable values — covered by "two jobs with different allocation sizes" test
3. `JobMemorySnapshotCache` has TTL eviction (600s) — implemented in cache class, matches `JobCpuSnapshotCache`
4. Cache cleanup after completion — covered by "cleans up memory cache entry" test
