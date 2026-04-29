# Changelog

All notable changes to `laravel-queue-metrics` will be documented in this file.

## v3.0.0 - system-metrics v3.0 with float CPU cores - 2026-04-29

### What's Changed

- **Upgraded `cboxdk/system-metrics` to `^3.0`** — CPU core counts are now `float` instead of `int`, enabling millicore support for containerized environments (e.g. 200m = 0.2 cores, 1500m = 1.5 cores)
- **Fixed worker CPU measurement** — replaced broken cumulative-time indicator with true delta-based CPU percentage between heartbeats. Values above 100% are valid in multi-core containers
- **Updated health status formatting** — CPU core count displayed with decimal precision for fractional quotas

### Breaking Changes

- Requires `cboxdk/system-metrics ^3.0` (up from `^2.0`)
- CPU core values in API responses (`cores`, `count` fields) are now `float` instead of `int`

## v2.8.0 - CPU time statistics - 2026-04-29

### What's New

#### CPU Time Statistics

Job metrics now include CPU time statistics alongside the existing duration and memory metrics. The new `CpuStats` DTO tracks average, peak, P95, and P99 CPU time in milliseconds for each job class.

```php
$metrics = QueueMetrics::getJobMetrics(ProcessOrder::class);

$metrics->cpu->avg;   // Average CPU time in ms
$metrics->cpu->peak;  // Peak CPU time
$metrics->cpu->p95;   // 95th percentile
$metrics->cpu->p99;   // 99th percentile
```

CPU time is also available in the HTTP API via the `avg_cpu_time_ms` field on job metrics endpoints.

## v2.7.0 - Debounce metrics tracking - 2026-04-27

### What's New

#### Debounce Metrics Tracking (Laravel 13.6+)

Laravel 13.6 introduced debounceable queued jobs via the `#[DebounceFor]` attribute. When a debounced job is superseded by a newer dispatch, it's silently discarded at execution time — but Laravel still fires `JobProcessing` and `JobProcessed` events, which would pollute your performance metrics with ghost ~0ms completions.

This release adds proper handling:

- **Debounced jobs are excluded** from duration/memory/CPU percentiles and throughput — they never actually executed
- **`total_debounced` counter** tracked per job class, visible in API responses and Prometheus (`job_debounced_total`)
- **`JobMetricsDebounced` event** fired for downstream consumers
- **Fully backward compatible** — the listener is conditionally registered only when `Illuminate\Queue\Events\JobDebounced` exists. No impact on Laravel 11/12.

#### New Prometheus Metric

```
laravel_queue_job_debounced_total{job="App\\Jobs\\SyncData",queue="default",connection="redis"} 23

```
#### How It Works

When Laravel fires `JobDebounced` (between `JobProcessing` and `JobProcessed`), the new `JobDebouncedListener`:

1. Marks the job via `DebouncedJobTracker` so `JobProcessedListener` skips performance metrics
2. Records `total_debounced` counter in the repository
3. Fires `JobMetricsDebounced` event for downstream consumers

#### Bug Fixes

- Fixed `toBeFloat()` assertion failure in `ChildProcessTrackingTest` when memory division yields whole number
- Fixed PHPStan level 9 errors (redundant null coalesce on non-nullable `WorkerHeartbeat::$hostname`)

## v2.6.0 - Persistence toggle - 2026-04-09

### What's Changed

* feat: Add `persistence.enabled` config option (`QUEUE_METRICS_PERSISTENCE` env) — when `false`, listeners still instrument jobs and fire `JobMetricsCompleted`/`JobMetricsFailed` events but skip all repository writes; scheduled tasks are not registered; no Redis or database connection is required
* tests: Add 11 tests covering persistence-disabled behavior (events fire, no repository calls, no scheduled tasks)
* docs: Document `persistence.enabled` in configuration reference and events docs

**Full Changelog**: https://github.com/cboxdk/laravel-queue-metrics/compare/v2.5.0...v2.6.0

## v2.5.0 - Database storage driver - 2026-04-09

### What's Changed

* feat: Add database storage driver as alternative to Redis — stores metrics in 4 Eloquent-backed tables (`queue_metrics_keys`, `queue_metrics_hashes`, `queue_metrics_sets`, `queue_metrics_sorted_sets`)
* feat: Add 5 database repository implementations (`DatabaseWorkerRepository`, `DatabaseWorkerHeartbeatRepository`, `DatabaseJobMetricsRepository`, `DatabaseQueueMetricsRepository`, `DatabaseBaselineRepository`) mirroring Redis behavior
* feat: Add `DatabaseMetricsStore` providing the same API as `RedisMetricsStore` with Eloquent
* feat: Add `queue-metrics:cleanup-database` command for expired data removal and sorted set trimming
* feat: Driver-based repository resolution — set `QUEUE_METRICS_STORAGE=database` to auto-bind all database repositories; explicit overrides still take precedence
* feat: Add `max_samples_per_key` and `cleanup_chunk_size` config options
* perf: Bulk `incrementHashFields` method reduces lock contention (4 transactions → 1 per job completion)
* perf: Heartbeat throttling for database driver — skips writes if same state and <10s since last write (~90% reduction in heartbeat queries)
* fix: Handle `-inf`/`+inf` in sorted set score queries for database driver
* fix: Resolve all PHPStan errors in database driver (122 errors → 0)

**Full Changelog**: https://github.com/cboxdk/laravel-queue-metrics/compare/v2.4.0...v2.5.0

## v2.4.0 - Expose worker memory limit in metrics events - 2026-04-07

### What's Changed

* feat: Add `workerMemoryLimitMb` to `JobMetricsCompleted` and `JobMetricsFailed` events — enables downstream consumers to calculate memory utilization percentage (e.g. 256/512 MB = 50%)
* refactor: Extract duplicated `getWorkerMemoryLimitMb()` into shared `MemoryLimitParser` utility
* fix: Resolve PHPStan level 9 errors — `ini_get()` never returns `false` for known settings
* tests: Add comprehensive `MemoryLimitParser` tests (M/G/K/bytes/lowercase/unlimited)
* tests: Update event tests to cover `workerMemoryLimitMb` property
* docs: Document `workerMemoryLimitMb` in events reference

**Full Changelog**: https://github.com/cboxdk/laravel-queue-metrics/compare/v2.3.0...v2.4.0

## v2.3.0 - Add JobMetricsFailed event and fix ProcessMetrics leak - 2026-04-05

### What's Changed

* feat: Add `JobMetricsFailed` event with per-job metrics (duration, memory, CPU, exception) for downstream consumers like queue-monitor
* feat: Add `JobMetricsCompleted` event (existed but was never committed)
* fix: Stop ProcessMetrics tracker on job failure — `ProcessMetrics::start()` was called in `JobProcessingListener` but `ProcessMetrics::stop()` was only called on success, leaking trackers on failure
* fix: Resolve 17 pre-existing PHPStan errors from named args in `Dispatchable::dispatch()` calls
* docs: Document `JobMetricsCompleted` and `JobMetricsFailed` events

**Full Changelog**: https://github.com/cboxdk/laravel-queue-metrics/compare/v2.2.0...v2.3.0

## v2.2.0 - Add Laravel 13 support - 2026-04-05

### What's Changed

* feat: Add Laravel 13 support with orchestra/testbench ^11.0
* ci: Add Laravel 13 to test matrix (unit + Redis integration)
* docs: Update version references in README and documentation

**Full Changelog**: https://github.com/cboxdk/laravel-queue-metrics/compare/v2.1.1...v2.2.0

## v2.1.1 - Fix addToSet type error & CI hardening - 2026-04-04

### What's Changed

* fix: Wrap `$serverKey` in array for `PipelineWrapper::addToSet()` in `recordHostnameMetrics()` — caused `TypeError` in production
* fix: Resolve PHPStan level 9 errors (`new static` → `new self`, typed array extraction in `PrometheusService`)
* ci: Add `pull_request` triggers to PHPStan, Pint, and test workflows so they gate PRs
* ci: Limit `push` triggers to `main` branch to prevent duplicate CI runs
* ci: Add concurrency groups to cancel stale workflow runs

**Full Changelog**: https://github.com/cboxdk/laravel-queue-metrics/compare/v2.1.0...v2.1.1

## v2.1.0 - Fix type casting in artisan commands - 2026-04-04

### What's Changed

* fix: Cast CLI option and config values to int in `CleanupStaleWorkersCommand` — `$this->option()` returns `string|null`, but `cleanupStaleWorkers()` requires `int`
* fix: Extract depth integer from nested array in `RecordTrendDataCommand` — `getAllQueuesWithMetrics()` returns depth as `['total' => ..., 'pending' => ...]`, but `execute()` expects `int`
* tests: Add unit tests for both commands

**Full Changelog**: https://github.com/cboxdk/laravel-queue-metrics/compare/v2.0.0...v2.1.0

## v2.0.0 - Rebranded to Cbox & Quality of Life Improvements - 2026-01-20

### What's Changed

* chore(deps): bump dependabot/fetch-metadata from 2.4.0 to 2.5.0 by @dependabot[bot] in https://github.com/cboxdk/laravel-queue-metrics/pull/2

### New Contributors

* @dependabot[bot] made their first contribution in https://github.com/cboxdk/laravel-queue-metrics/pull/2

**Full Changelog**: https://github.com/cboxdk/laravel-queue-metrics/commits/v2.0.0

## v1.5.0 - 2026-01-09

### What's Changed

* fix: Cast job IDs to string in all listeners for database queue driver compatibility
  - Laravel's database queue driver returns int job IDs while Redis/SQS return strings
  - All listeners now cast `$job->getJobId()` to string for consistent handling
  - Repository interfaces accept `string|int` with internal string casting
  

**Full Changelog**: https://github.com/cboxdk/laravel-queue-metrics/compare/v1.4.0...v1.5.0

## v1.4.2 - 2026-01-08

### What's Changed

* fix: Cast job IDs to string in all listeners for consistency
  - Ensures consistent string type for job IDs throughout listener logic
  - Fixes potential type issues with database queue driver returning int IDs
  

**Full Changelog**: https://github.com/cboxdk/laravel-queue-metrics/compare/v1.4.1...v1.4.2

## v1.4.1 - 2026-01-08

### What's Changed

* fix: Accept both string and int job IDs from Laravel queue drivers
  - Laravel's database queue driver returns int job IDs while Redis/SQS drivers return string IDs
  - Changed `$jobId` parameter type from `string` to `string|int` in all actions and repository interfaces
  

**Full Changelog**: https://github.com/cboxdk/laravel-queue-metrics/compare/v1.4.0...v1.4.1

## v1.4.0 - 2026-01-02

### What's Changed

* feat: Add PHP 8.5 support
* Update all dependencies to latest versions

**Full Changelog**: https://github.com/cboxdk/laravel-queue-metrics/compare/v1.3.1...v1.4.0

## v1.3.1 - 2026-01-01

### What's Changed

* fix: Achieve PHPStan Level 9 compliance with empty baseline
  - Remove redundant type checks and operators
  - Add proper type annotations and validation guards
  - Fix Carbon timestamp casting for binary operations
  - Clear baseline from 32 errors to 0
  

**Full Changelog**: https://github.com/cboxdk/laravel-queue-metrics/compare/v1.3.0...v1.3.1

## v1.3.0 - 2025-11-20

### What's Changed

* refactor!: Restructure metrics response for clear abstraction separation by @sylvesterdamgaard in https://github.com/cboxdk/laravel-queue-metrics/pull/3

**Full Changelog**: https://github.com/cboxdk/laravel-queue-metrics/compare/v1.2.0...v1.3.0

## v1.2.0 - 2025-11-20

### What's Changed

* Fix race conditions and implement queue metrics aggregation by @sylvesterdamgaard in https://github.com/cboxdk/laravel-queue-metrics/pull/2

**Full Changelog**: https://github.com/cboxdk/laravel-queue-metrics/compare/v1.1.0...v1.2.0

## v1.1.0 - 2025-11-19

### What's Changed

* fix(redis): use spread operator for variadic Redis set operations by @sylvesterdamgaard in https://github.com/cboxdk/laravel-queue-metrics/pull/1

### New Contributors

* @sylvesterdamgaard made their first contribution in https://github.com/cboxdk/laravel-queue-metrics/pull/1

**Full Changelog**: https://github.com/cboxdk/laravel-queue-metrics/compare/v0.0.1...v1.1.0

## v1.0.0 - 2025-11-19

**Full Changelog**: https://github.com/cboxdk/laravel-queue-metrics/commits/v1.0.0
