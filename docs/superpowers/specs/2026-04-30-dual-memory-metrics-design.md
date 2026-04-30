# Dual Memory Metrics: Peak RSS + Incremental Allocation

**Issue:** [#20](https://github.com/cboxdk/laravel-queue-metrics/issues/20)
**Date:** 2026-04-30
**Target version:** v3.2.0

## Problem

v3.1.0 changed `memoryMb` from "worker peak RSS during job" to "peak minus startRss" (incremental allocation). This fixed dashboard differentiation (job classes now report distinguishable shapes) but broke capacity consumers like `cboxdk/laravel-queue-autoscale`, which reads `memory.avg` to decide how many workers fit on a host. The autoscaler now sees ~6 MB for a typical Laravel worker that actually consumes ~50-80 MB, causing 10x over-provisioning and OOM kills.

## Solution

Ship both metrics. Revert `memoryMb` to peak RSS throughout the pipeline and add `memoryIncrementalMb` as a new additive field. Both values are already computed in the listener — the change is plumbing them through storage, aggregation, and output.

## Design

### Semantic change

| Field | Before (v3.1.0) | After (v3.2.0) |
|-------|-----------------|-----------------|
| `memoryMb` / `memory.avg` | incremental (peak − start) | peak RSS |
| `memoryIncrementalMb` / `memory.avg_incremental` | N/A | incremental (peak − start) |
| `memory.peak` | max incremental | max peak RSS |
| `memory.p95` | p95 incremental | p95 peak RSS |
| `memory.p99` | p99 incremental | p99 peak RSS |

### Output shape

```php
'memory' => [
    'avg'             => 52.3,   // peak RSS average — capacity planning input
    'avg_incremental' => 6.2,    // incremental average — job-class differentiation
    'peak'            => 78.1,   // max peak RSS observed
    'p95'             => 68.5,   // 95th percentile of peak RSS
    'p99'             => 75.2,   // 99th percentile of peak RSS
]
```

### Storage strategy

- **Memory sorted set** (`memory:*`): stores peak RSS samples (for avg, peak, p95, p99 percentile calculations)
- **Hash field** `total_memory_mb`: accumulates peak RSS sum (used alongside `total_processed` for avg)
- **Hash field** `total_memory_incremental_mb` (new): accumulates incremental sum (for `avg_incremental = total_incremental / total_processed`)
- No second sorted set — only `avg_incremental` is needed, not incremental percentiles

### Layer-by-layer changes

#### 1. Listeners (capture)

**`JobProcessedListener::handle()`** (src/Listeners/JobProcessedListener.php)

Current code computes both values but discards peak:
```php
$peakMemoryMb = $metrics->peak->memoryRssBytes / 1024 / 1024;
$startMemoryMb = JobMemorySnapshotCache::get($jobId);
$memoryMb = max(0.0, $peakMemoryMb - $startMemoryMb); // only keeps incremental
```

Change to:
```php
$peakMemoryMb = $metrics->peak->memoryRssBytes / 1024 / 1024;
$startMemoryMb = JobMemorySnapshotCache::get($jobId);
$memoryIncrementalMb = ($startMemoryMb !== null)
    ? max(0.0, $peakMemoryMb - $startMemoryMb)
    : $peakMemoryMb;
$memoryMb = $peakMemoryMb; // peak RSS for capacity consumers
```

Pass both to `RecordJobCompletionAction::execute()` and `JobMetricsCompleted::dispatch()`.

**`JobFailedListener::handle()`** (src/Listeners/JobFailedListener.php)

Same computation change. Pass both values to `JobMetricsFailed::dispatch()`. The failure recording path (`RecordJobFailureAction`) does not store memory, so no change there.

#### 2. Actions (recording)

**`RecordJobCompletionAction::execute()`** (src/Actions/RecordJobCompletionAction.php)

Add parameter:
```php
public function execute(
    // ... existing params ...
    float $memoryMb,
    float $memoryIncrementalMb = 0.0,  // new, optional
    // ... rest ...
): void
```

Pass through to `$this->repository->recordCompletion()`.

#### 3. Repository contract + implementations (storage)

**`JobMetricsRepository::recordCompletion()`** (src/Repositories/Contracts/JobMetricsRepository.php)

Add optional parameter:
```php
public function recordCompletion(
    // ... existing params ...
    float $memoryMb,
    float $cpuTimeMs,
    Carbon $completedAt,
    ?string $hostname = null,
    float $memoryIncrementalMb = 0.0,  // new, optional, at end
): void;
```

**`DatabaseJobMetricsRepository::recordCompletion()`** and **`RedisJobMetricsRepository::recordCompletion()`**

Both add:
- Increment hash field `total_memory_incremental_mb` by `$memoryIncrementalMb`
- Memory sorted set continues to store peak RSS samples (which `$memoryMb` now is)

Both repository `getMetrics()` methods add:
```php
'total_memory_incremental_mb' => (float) ($data['total_memory_incremental_mb'] ?? 0.0),
```

#### 4. DTO (data)

**`MemoryStats`** (src/DataTransferObjects/MemoryStats.php)

Add field:
```php
final readonly class MemoryStats
{
    public function __construct(
        public float $avg,
        public float $avgIncremental,  // new
        public float $peak,
        public float $p95,
        public float $p99,
    ) {}
}
```

`fromArray()` reads `avg_incremental` with `0.0` default. `toArray()` outputs `avg_incremental`.

#### 5. Aggregation (query)

**`CalculateJobMetricsAction::calculateMemory()`** (src/Actions/CalculateJobMetricsAction.php)

Currently only uses sorted set samples. Change to also read hash fields for incremental:

```php
private function calculateMemory(
    string $jobClass,
    string $connection,
    string $queue,
): MemoryStats {
    $samples = $this->repository->getMemorySamples($jobClass, $connection, $queue);
    $metrics = $this->repository->getMetrics($jobClass, $connection, $queue);

    if (empty($samples)) {
        return new MemoryStats(0.0, 0.0, 0.0, 0.0, 0.0);
    }

    $percentiles = $this->percentiles->calculateMultiple($samples, [95, 99]);
    $totalProcessed = (int) ($metrics['total_processed'] ?? 0);
    $totalIncrementalMb = (float) ($metrics['total_memory_incremental_mb'] ?? 0.0);

    return new MemoryStats(
        avg: array_sum($samples) / count($samples),
        avgIncremental: $totalProcessed > 0 ? $totalIncrementalMb / $totalProcessed : 0.0,
        peak: max($samples),
        p95: $percentiles['p95'],
        p99: $percentiles['p99'],
    );
}
```

Note: `calculateMemory()` already receives the `$metrics` hash data in the parent `execute()` method — we'll thread it through rather than making a second `getMetrics()` call.

#### 6. Events

**`JobMetricsCompleted`** (src/Events/JobMetricsCompleted.php)

Add optional parameter:
```php
public function __construct(
    // ... existing params ...
    public readonly ?float $workerMemoryLimitMb = null,
    public readonly float $memoryIncrementalMb = 0.0,  // new
) {}
```

**`JobMetricsFailed`** (src/Events/JobMetricsFailed.php)

Same addition.

#### 7. Prometheus

**`PrometheusService::exportJobMetrics()`** (src/Services/PrometheusService.php)

Add one gauge in the memory block:
```php
Prometheus::addGauge('job_memory_avg_incremental_megabytes')
    ->name('job_memory_avg_incremental_megabytes')
    ->namespace($namespace)
    ->label('job')
    ->label('queue')
    ->label('connection')
    ->helpText('Job incremental memory allocation in megabytes (peak minus baseline)')
    ->value($memory['avg_incremental'], [$job, $queue, $connection]);
```

Existing gauges (`job_memory_peak_megabytes`, `job_memory_p95_megabytes`, `job_memory_p99_megabytes`) now correctly reflect peak RSS.

### Backward compatibility

| Consumer | Impact |
|----------|--------|
| `queue-autoscale` reading `memory.avg` | Gets peak RSS again — correct capacity sizing, no code change needed |
| `queue-monitor` reading `memory.*` | Gets peak-based stats; can optionally display `avg_incremental` for differentiation |
| Custom `JobMetricsRepository` implementations | New optional parameter with default — no break |
| Serialized `MemoryStats` data | `fromArray()` defaults `avg_incremental` to 0.0 — safe |
| Event listeners | New optional constructor parameter — existing listeners unaffected |
| Prometheus scrapers | New gauge appears; existing gauges change semantic (incremental → peak) which is intentional and correct |

### Downstream impact on baselines

`CalculateBaselinesAction` calls `getMemorySamples()` to compute `memory_mb_per_job` for baseline data. After this change, those samples contain peak RSS instead of incremental. This is correct — baselines feed capacity planning, which needs peak RSS. No change required in the baselines action.

### Tests

1. **Update `JobMemoryDeltaTest`**: verify `recordCompletion` receives both `memoryMb` (peak) and `memoryIncrementalMb` (delta)
2. **Sleep job test**: peak RSS ≈ baseline (e.g. 64 MB), incremental ≈ 0
3. **Heavy job test**: peak RSS > baseline, incremental reflects allocation
4. **Missing baseline fallback**: both values fall back to peak RSS
5. **Negative delta clamping**: incremental clamped to 0, peak stays at peak RSS
6. **`MemoryStats` DTO test**: round-trip `avgIncremental` through `toArray()`/`fromArray()`
7. **`CalculateJobMetricsAction` test**: computes `avgIncremental` from hash fields

### Acceptance criteria (from issue #20)

- [x] `memory.avg` returns worker's peak RSS during job execution (50+ MB for typical workers)
- [x] `memory.avg_incremental` returns peak minus start (0-10 MB for sleep jobs, 100+ MB for heavy jobs)
- [x] queue-autoscale's measured memory estimate produces realistic per-worker capacity
- [x] queue-monitor can differentiate job classes via incremental field
- [x] Tests cover both dimensions for sleep and heavy allocation jobs
