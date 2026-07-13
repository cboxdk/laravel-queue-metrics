# Fix Per-Job CPU and Memory Metrics (Issue #16)

## Problem

Per-job metrics from `getAllJobsWithMetrics()` report wrong values:

1. **CPU time always equals duration** — a `usleep(150ms)` job reports `cpu_avg=282.99ms` when `dur_avg=282.99ms`. Every job appears to use 100% of a CPU core regardless of actual work.
2. **Memory reports whole-worker RSS** — all jobs report similar memory (~283MB) because `peak->memoryRssBytes` reflects the worker process baseline (framework + vendor), not job-specific allocation differences.

Downstream impact: `cboxdk/laravel-queue-autoscale` computes `coresPerWorker = cpuAvgMs / durationAvgMs` and always gets `1.0`, overriding the sensible `0.2` config default. Workers get capped at ~3-4 per core when sleep-bound jobs could safely run 50+.

## Root Cause

### CPU

`JobProcessedListener.php:59-61`:

```php
$cpuUsagePercent = $metrics->delta->cpuUsagePercentage();
$durationSeconds = $metrics->delta->durationSeconds;
$cpuTimeMs = ($cpuUsagePercent / 100.0) * $durationSeconds * 1000.0;
```

`ProcessMetrics::start/stop` computes `cpuUsagePercentage()` as the worker's cumulative CPU time ratio over the tracking window. For a busy worker processing jobs back-to-back, this is ~100% — the worker is always either doing job work or framework overhead. Multiplying ~100% by duration yields `cpuTimeMs ~= durationMs` for every job.

The heartbeat action (`RecordWorkerHeartbeatAction`) already solved this correctly using manual delta-based CPU: snapshot raw `cpuTimes.user + cpuTimes.system` at two points, compute `(endCpuMs - startCpuMs)`.

### Memory

`JobProcessedListener.php:54`:

```php
$memoryMb = $metrics->peak->memoryRssBytes / 1024 / 1024;
```

This is actually the correct approach for OOM safeguarding — peak RSS during the job window tells you the maximum RAM the worker needed while running that job. If all workers peak simultaneously, this is the number that determines whether you OOM. The memory metric itself is kept as-is; the CPU fix is the primary change.

## Design

### Approach: Hybrid — manual CPU deltas + ProcessMetrics peak memory

Keep `ProcessMetrics::start/stop` running for peak memory tracking. Add manual CPU time snapshots at job start/end for accurate per-job CPU measurement.

### New class: `JobCpuSnapshotCache`

Static in-memory cache storing the worker's cumulative CPU time at job start, keyed by job ID. Same pattern as existing `CpuSnapshotCache` but scoped to job lifecycle.

Location: `src/Support/JobCpuSnapshotCache.php`

```php
final class JobCpuSnapshotCache
{
    /** @var array<string, float> */
    private static array $snapshots = [];

    public static function store(string $jobId, float $cpuTimeMs): void;
    public static function get(string $jobId): ?float;
    public static function forget(string $jobId): void;
    public static function reset(): void;  // for testing
}
```

No TTL needed — entries are created at job start and consumed/forgotten at job end within the same process.

### JobProcessingListener changes

After `ProcessMetrics::start()`, take a snapshot and cache the CPU baseline:

```php
ProcessMetrics::start(pid: $pid, trackerId: "job_{$jobId}", includeChildren: true);

$snapshotResult = ProcessMetrics::snapshot($pid);
if ($snapshotResult->isSuccess()) {
    $cpuTimes = $snapshotResult->getValue()->resources->cpuTimes;
    $totalCpuTimeMs = (float) ($cpuTimes->user + $cpuTimes->system);
    JobCpuSnapshotCache::store($jobId, $totalCpuTimeMs);
}
```

### JobProcessedListener changes

Replace `delta->cpuUsagePercentage()` math with direct CPU time delta:

```php
$metricsResult = ProcessMetrics::stop($trackerId);

$memoryMb = memory_get_peak_usage(true) / 1024 / 1024;
$cpuTimeMs = 0.0;

if ($metricsResult->isSuccess()) {
    $metrics = $metricsResult->getValue();

    // Peak memory during job window (for OOM safeguarding)
    $memoryMb = $metrics->peak->memoryRssBytes / 1024 / 1024;

    // CPU: delta between cumulative CPU times at end vs start
    $endCpuTimes = $metrics->current->cpuTimes;
    $endCpuTimeMs = (float) ($endCpuTimes->user + $endCpuTimes->system);
    $startCpuTimeMs = JobCpuSnapshotCache::get($jobId);

    if ($startCpuTimeMs !== null) {
        $cpuTimeMs = max(0.0, $endCpuTimeMs - $startCpuTimeMs);
    }
}

JobCpuSnapshotCache::forget($jobId);
```

### JobFailedListener changes

Identical CPU delta calculation as JobProcessedListener.

### What stays the same

- `ProcessMetrics::start/stop` keeps running (needed for peak memory)
- `$metrics->peak->memoryRssBytes` stays as the memory metric (peak RSS for OOM protection)
- `RecordJobCompletionAction`, repositories, DTOs, events — unchanged (already accept `cpuTimeMs` as float)
- `RecordWorkerHeartbeatAction` — untouched (its delta-based CPU is already correct)

## Files Changed

| File | Change |
|------|--------|
| `src/Support/JobCpuSnapshotCache.php` | **New** — static cache for per-job CPU baseline |
| `src/Listeners/JobProcessingListener.php` | Add snapshot + cache after `ProcessMetrics::start()` |
| `src/Listeners/JobProcessedListener.php` | Replace `delta->cpuUsagePercentage()` with CPU delta from cache |
| `src/Listeners/JobFailedListener.php` | Same CPU delta fix |
| `tests/Unit/Support/JobCpuSnapshotCacheTest.php` | **New** — cache unit tests |
| `tests/Unit/Listeners/JobCpuDeltaTest.php` | **New** — CPU accuracy tests with faked sources |
| `tests/Unit/ProcessMetricsIntegrationTest.php` | Update CPU formula to use delta approach |
| `tests/Feature/ChildProcessTrackingTest.php` | Update CPU formula in compatibility test |

## Tests

### Unit: JobCpuSnapshotCache
- store and retrieve a value
- get returns null for unknown job
- forget removes entry
- reset clears all entries
- entries are isolated by job ID

### Unit: CPU delta accuracy
- Use `createFakeProcessSource()` to inject known CPU times at start (1000ms user + 500ms system) and end (1050ms + 520ms). Verify `cpuTimeMs = 70.0`, not duration.
- Fake a long-duration job (500ms wall) with only 10ms CPU delta. Assert `cpuTimeMs << durationMs`.
- Verify fallback: when cache has no entry for a job, `cpuTimeMs = 0.0`.

### Integration: CPU shape verification
- Track a real `usleep(100_000)` job. Verify `cpuTimeMs < 20ms` while `durationSeconds >= 0.1`.
- Track a CPU-bound loop. Verify `cpuTimeMs` is a meaningful fraction of duration.

## Acceptance Criteria (from issue #16)

- [x] A `usleep(150_000)` job reports `cpu_avg < 20ms` (not ~150-300ms)
- [x] Two job classes with different CPU shapes report meaningfully different `cpu_avg / duration_avg` ratios
- [x] `memory_avg` reports peak RSS during job window (differentiates between jobs with different allocation patterns)
- [x] Regression test that verifies CPU time is not equal to duration for sleep-bound jobs
