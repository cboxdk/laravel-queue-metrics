# Per-Job Memory Delta (Issue #18)

## Problem

`memoryMb` reports the worker process's peak RSS during the job window, not the memory cost of the job itself. Every job class reports ~50-200 MB regardless of actual allocation. queue-autoscale v3.5.0's `ResourceEstimateResolver` now consumes this data for capacity planning, producing wildly wrong worker placement.

## Decision

**Option A: Replace in place.** Mirror the CPU delta pattern from v3.0.1. Change `memoryMb` semantics from "peak worker RSS" to "incremental job allocation." No DTO/event/repository contract changes.

Rationale:
- v3.0.1 set the precedent (CPU replaced in place, not shipped alongside)
- Worker-level peak RSS already available via `WorkerHeartbeat.peakMemoryUsageMb`
- Option B (ship both) would touch ~15 files for a metric available elsewhere

## Implementation

### 1. `JobMemorySnapshotCache` (new file, mirrors `JobCpuSnapshotCache`)
- Static in-memory cache: `jobId -> {memory_rss_mb: float, stored_at: float}`
- TTL eviction: MAX_AGE_SECONDS = 600 (matches CPU cache)
- Methods: `store()`, `get()`, `forget()`, `reset()`

### 2. `JobProcessingListener` (modify)
- After existing CPU snapshot, also snapshot memory baseline:
  ```
  $resources->memoryRssBytes / 1024 / 1024 -> JobMemorySnapshotCache::store()
  ```

### 3. `JobProcessedListener` (modify)
- Retrieve baseline from `JobMemorySnapshotCache::get()`
- Compute incremental: `max(0.0, peakMemoryMb - startMemoryMb)`
- Fallback to peak RSS if baseline missing
- `JobMemorySnapshotCache::forget()` after use

### 4. `JobFailedListener` (modify)
- Same delta logic as JobProcessedListener

### 5. Tests
- `JobMemorySnapshotCacheTest` — mirrors `JobCpuSnapshotCacheTest`
- `JobMemoryDeltaTest` — mirrors `JobCpuDeltaTest`, validates:
  - Sleep-bound job reports < 10 MB incremental
  - Missing baseline falls back to peak RSS
  - Cache cleanup after completion

### 6. CHANGELOG
- Explicit BREAKING entry documenting semantic shift
- Guidance on alert recalibration
- Pointer to `WorkerHeartbeat.peakMemoryUsageMb` for OOM use case

## Files Changed

| File | Change |
|------|--------|
| `src/Support/JobMemorySnapshotCache.php` | New — mirrors JobCpuSnapshotCache |
| `src/Listeners/JobProcessingListener.php` | Add memory baseline snapshot |
| `src/Listeners/JobProcessedListener.php` | Compute memory delta |
| `src/Listeners/JobFailedListener.php` | Compute memory delta |
| `tests/Unit/Support/JobMemorySnapshotCacheTest.php` | New — cache tests |
| `tests/Unit/Listeners/JobMemoryDeltaTest.php` | New — delta accuracy tests |
| `CHANGELOG.md` | Breaking change entry |

## Not Changed

- `RecordJobCompletionAction` — same `float $memoryMb` parameter
- `JobMetricsCompleted` / `JobMetricsFailed` events — same `float $memoryMb` field
- `JobMetricsRepository` contract — same `float $memoryMb` parameter
- All DTOs, Prometheus gauges, baseline calculations — unchanged

## Acceptance Criteria (from issue)

- [ ] `usleep(150_000)` job reports `memoryMb < 10 MB`
- [ ] Two jobs with different allocation sizes report distinguishable values
- [ ] `JobMemorySnapshotCache` has TTL eviction (600s, matching CPU cache)
- [ ] Fallback to peak RSS when baseline is missing
