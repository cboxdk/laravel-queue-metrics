# Dual Memory Metrics Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship both peak RSS and incremental memory metrics so capacity consumers get correct OOM-aware sizing while dashboard differentiation is preserved.

**Architecture:** Revert `memoryMb` to peak RSS throughout the pipeline, add `memoryIncrementalMb` as a new additive field. Peak RSS samples go into the existing sorted set for percentile calculations. Incremental totals go into a new hash field for computing `avg_incremental`.

**Tech Stack:** PHP 8.4, Laravel 12, Pest PHP testing framework, Mockery

---

### Task 1: Update `MemoryStats` DTO

**Files:**
- Modify: `src/DataTransferObjects/MemoryStats.php`
- Modify: `tests/Unit/DataTransferObjects/MemoryStatsTest.php`

- [ ] **Step 1: Write the failing test for `avgIncremental` field**

Add these test cases to `tests/Unit/DataTransferObjects/MemoryStatsTest.php`:

```php
it('can be created with avgIncremental property', function () {
    $stats = new MemoryStats(
        avg: 128.5,
        avgIncremental: 12.3,
        peak: 256.0,
        p95: 240.0,
        p99: 250.0,
    );

    expect($stats->avg)->toBe(128.5)
        ->and($stats->avgIncremental)->toBe(12.3)
        ->and($stats->peak)->toBe(256.0)
        ->and($stats->p95)->toBe(240.0)
        ->and($stats->p99)->toBe(250.0);
});

it('includes avg_incremental in array output', function () {
    $stats = new MemoryStats(
        avg: 128.5,
        avgIncremental: 12.3,
        peak: 256.0,
        p95: 240.0,
        p99: 250.0,
    );

    expect($stats->toArray())->toBe([
        'avg' => 128.5,
        'avg_incremental' => 12.3,
        'peak' => 256.0,
        'p95' => 240.0,
        'p99' => 250.0,
    ]);
});

it('can be created from array with avg_incremental', function () {
    $data = [
        'avg' => 128.5,
        'avg_incremental' => 12.3,
        'peak' => 256.0,
        'p95' => 240.0,
        'p99' => 250.0,
    ];

    $stats = MemoryStats::fromArray($data);

    expect($stats->avgIncremental)->toBe(12.3);
});

it('defaults avg_incremental to zero when missing from array', function () {
    $data = [
        'avg' => 128.5,
        'peak' => 256.0,
        'p95' => 240.0,
        'p99' => 250.0,
    ];

    $stats = MemoryStats::fromArray($data);

    expect($stats->avgIncremental)->toBe(0.0);
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test tests/Unit/DataTransferObjects/MemoryStatsTest.php`
Expected: FAIL — constructor doesn't accept `avgIncremental` parameter.

- [ ] **Step 3: Update existing tests to include `avgIncremental`**

Update the existing tests in `tests/Unit/DataTransferObjects/MemoryStatsTest.php`. Every `new MemoryStats(...)` call needs the new parameter. Update the `'can be created with all properties'` test:

```php
it('can be created with all properties', function () {
    $stats = new MemoryStats(
        avg: 128.5,
        avgIncremental: 0.0,
        peak: 256.0,
        p95: 240.0,
        p99: 250.0,
    );

    expect($stats->avg)->toBe(128.5)
        ->and($stats->avgIncremental)->toBe(0.0)
        ->and($stats->peak)->toBe(256.0)
        ->and($stats->p95)->toBe(240.0)
        ->and($stats->p99)->toBe(250.0);
});
```

Update the `'can be converted to array'` test:

```php
it('can be converted to array', function () {
    $stats = new MemoryStats(
        avg: 128.5,
        avgIncremental: 0.0,
        peak: 256.0,
        p95: 240.0,
        p99: 250.0,
    );

    expect($stats->toArray())->toBe([
        'avg' => 128.5,
        'avg_incremental' => 0.0,
        'peak' => 256.0,
        'p95' => 240.0,
        'p99' => 250.0,
    ]);
});
```

Update the `'can be created from array'` test:

```php
it('can be created from array', function () {
    $data = [
        'avg' => 128.5,
        'avg_incremental' => 0.0,
        'peak' => 256.0,
        'p95' => 240.0,
        'p99' => 250.0,
    ];

    $stats = MemoryStats::fromArray($data);

    expect($stats)->toBeInstanceOf(MemoryStats::class)
        ->and($stats->avg)->toBe(128.5)
        ->and($stats->peak)->toBe(256.0);
});
```

Update the `'is readonly and immutable'` test:

```php
it('is readonly and immutable', function () {
    $stats = new MemoryStats(
        avg: 128.5,
        avgIncremental: 0.0,
        peak: 256.0,
        p95: 240.0,
        p99: 250.0,
    );

    expect(fn () => $stats->avg = 200.0)
        ->toThrow(Error::class);
});
```

- [ ] **Step 4: Implement `MemoryStats` changes**

Update `src/DataTransferObjects/MemoryStats.php`:

```php
<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueMetrics\DataTransferObjects;

/**
 * Memory usage statistics in megabytes.
 */
final readonly class MemoryStats
{
    public function __construct(
        public float $avg,
        public float $avgIncremental,
        public float $peak,
        public float $p95,
        public float $p99,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $avg = $data['avg'] ?? 0.0;
        $avgIncremental = $data['avg_incremental'] ?? 0.0;
        $peak = $data['peak'] ?? 0.0;
        $p95 = $data['p95'] ?? 0.0;
        $p99 = $data['p99'] ?? 0.0;

        return new self(
            avg: is_numeric($avg) ? (float) $avg : 0.0,
            avgIncremental: is_numeric($avgIncremental) ? (float) $avgIncremental : 0.0,
            peak: is_numeric($peak) ? (float) $peak : 0.0,
            p95: is_numeric($p95) ? (float) $p95 : 0.0,
            p99: is_numeric($p99) ? (float) $p99 : 0.0,
        );
    }

    /**
     * @return array<string, float>
     */
    public function toArray(): array
    {
        return [
            'avg' => $this->avg,
            'avg_incremental' => $this->avgIncremental,
            'peak' => $this->peak,
            'p95' => $this->p95,
            'p99' => $this->p99,
        ];
    }
}
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test tests/Unit/DataTransferObjects/MemoryStatsTest.php`
Expected: All tests PASS.

- [ ] **Step 6: Commit**

```bash
git add src/DataTransferObjects/MemoryStats.php tests/Unit/DataTransferObjects/MemoryStatsTest.php
git commit -m "feat: add avgIncremental field to MemoryStats DTO"
```

---

### Task 2: Update repository contract and implementations

**Files:**
- Modify: `src/Repositories/Contracts/JobMetricsRepository.php`
- Modify: `src/Repositories/DatabaseJobMetricsRepository.php`
- Modify: `src/Repositories/RedisJobMetricsRepository.php`
- Modify: `tests/Feature/Repositories/DatabaseJobMetricsRepositoryTest.php`

- [ ] **Step 1: Write the failing test for incremental memory storage**

Add to `tests/Feature/Repositories/DatabaseJobMetricsRepositoryTest.php`:

```php
test('recordCompletion stores incremental memory in hash', function () {
    $this->repo->recordCompletion(
        jobId: 'job-inc-1',
        jobClass: 'App\\Jobs\\SendEmail',
        connection: 'redis',
        queue: 'default',
        durationMs: 150.0,
        memoryMb: 64.0,
        cpuTimeMs: 45.0,
        completedAt: now(),
        hostname: null,
        memoryIncrementalMb: 8.5,
    );

    $metrics = $this->repo->getMetrics('App\\Jobs\\SendEmail', 'redis', 'default');
    expect((float) $metrics['total_memory_mb'])->toBe(64.0)
        ->and((float) $metrics['total_memory_incremental_mb'])->toBe(8.5);
});

test('recordCompletion accumulates incremental memory across multiple calls', function () {
    $this->repo->recordCompletion('j1', 'App\\Jobs\\SendEmail', 'redis', 'default', 100.0, 60.0, 5.0, now(), null, 6.0);
    $this->repo->recordCompletion('j2', 'App\\Jobs\\SendEmail', 'redis', 'default', 200.0, 65.0, 10.0, now(), null, 12.0);

    $metrics = $this->repo->getMetrics('App\\Jobs\\SendEmail', 'redis', 'default');
    expect($metrics['total_processed'])->toBe(2)
        ->and((float) $metrics['total_memory_mb'])->toBe(125.0)
        ->and((float) $metrics['total_memory_incremental_mb'])->toBe(18.0);
});

test('recordCompletion defaults incremental memory to zero', function () {
    $this->repo->recordCompletion(
        jobId: 'job-no-inc',
        jobClass: 'App\\Jobs\\SendEmail',
        connection: 'redis',
        queue: 'default',
        durationMs: 150.0,
        memoryMb: 64.0,
        cpuTimeMs: 45.0,
        completedAt: now(),
    );

    $metrics = $this->repo->getMetrics('App\\Jobs\\SendEmail', 'redis', 'default');
    expect((float) $metrics['total_memory_mb'])->toBe(64.0)
        ->and((float) $metrics['total_memory_incremental_mb'])->toBe(0.0);
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test tests/Feature/Repositories/DatabaseJobMetricsRepositoryTest.php --filter="incremental memory"`
Expected: FAIL — parameter `memoryIncrementalMb` doesn't exist on interface.

- [ ] **Step 3: Update the repository contract**

In `src/Repositories/Contracts/JobMetricsRepository.php`, update the `recordCompletion` signature:

```php
/**
 * Record a job completion event.
 */
public function recordCompletion(
    string|int $jobId,
    string $jobClass,
    string $connection,
    string $queue,
    float $durationMs,
    float $memoryMb,
    float $cpuTimeMs,
    Carbon $completedAt,
    ?string $hostname = null,
    float $memoryIncrementalMb = 0.0,
): void;
```

- [ ] **Step 4: Update `DatabaseJobMetricsRepository::recordCompletion()`**

In `src/Repositories/DatabaseJobMetricsRepository.php`, update the method signature to accept `float $memoryIncrementalMb = 0.0`. Then add the hash field storage inside the transaction closure.

Update the method signature (line 74):

```php
public function recordCompletion(
    string|int $jobId,
    string $jobClass,
    string $connection,
    string $queue,
    float $durationMs,
    float $memoryMb,
    float $cpuTimeMs,
    Carbon $completedAt,
    ?string $hostname = null,
    float $memoryIncrementalMb = 0.0,
): void {
```

Update the `use` clause of the transaction closure to include `$memoryIncrementalMb`:

```php
$this->store->transaction(function () use (
    $driver,
    $metricsKey,
    $durationKey,
    $memoryKey,
    $cpuKey,
    $durationMs,
    $memoryMb,
    $memoryIncrementalMb,
    $cpuTimeMs,
    $completedAt,
    $ttl,
    $jobId
) {
```

Update the `incrementHashFields` call to include the incremental field:

```php
$driver->incrementHashFields($metricsKey, [
    'total_processed' => 1,
    'total_duration_ms' => $durationMs,
    'total_memory_mb' => $memoryMb,
    'total_memory_incremental_mb' => $memoryIncrementalMb,
    'total_cpu_time_ms' => $cpuTimeMs,
]);
```

- [ ] **Step 5: Update `DatabaseJobMetricsRepository::getMetrics()`**

Add the new field to the return array in `getMetrics()`:

```php
'total_memory_incremental_mb' => (float) ($data['total_memory_incremental_mb'] ?? 0.0),
```

Place it after the `'total_memory_mb'` line.

- [ ] **Step 6: Update `RedisJobMetricsRepository::recordCompletion()`**

In `src/Repositories/RedisJobMetricsRepository.php`, apply the same changes:

Update method signature (line 71):

```php
public function recordCompletion(
    string|int $jobId,
    string $jobClass,
    string $connection,
    string $queue,
    float $durationMs,
    float $memoryMb,
    float $cpuTimeMs,
    Carbon $completedAt,
    ?string $hostname = null,
    float $memoryIncrementalMb = 0.0,
): void {
```

Update the `use` clause of the transaction closure to include `$memoryIncrementalMb`:

```php
$this->redis->transaction(function ($pipe) use (
    $metricsKey,
    $durationKey,
    $memoryKey,
    $cpuKey,
    $durationMs,
    $memoryMb,
    $memoryIncrementalMb,
    $cpuTimeMs,
    $completedAt,
    $ttl,
    $jobId
) {
```

Add after the existing `incrementHashField` calls for `total_cpu_time_ms` (line 104):

```php
$pipe->incrementHashField($metricsKey, 'total_memory_incremental_mb', $memoryIncrementalMb);
```

- [ ] **Step 7: Update `RedisJobMetricsRepository::getMetrics()`**

Add the new field to the return array:

```php
'total_memory_incremental_mb' => (float) ($data['total_memory_incremental_mb'] ?? 0.0),
```

Place it after the `'total_memory_mb'` line.

- [ ] **Step 8: Run tests to verify they pass**

Run: `php artisan test tests/Feature/Repositories/DatabaseJobMetricsRepositoryTest.php`
Expected: All tests PASS including new incremental tests.

- [ ] **Step 9: Commit**

```bash
git add src/Repositories/Contracts/JobMetricsRepository.php src/Repositories/DatabaseJobMetricsRepository.php src/Repositories/RedisJobMetricsRepository.php tests/Feature/Repositories/DatabaseJobMetricsRepositoryTest.php
git commit -m "feat: add memoryIncrementalMb to repository contract and implementations"
```

---

### Task 3: Update `RecordJobCompletionAction`

**Files:**
- Modify: `src/Actions/RecordJobCompletionAction.php`
- Modify: `tests/Unit/Actions/RecordJobCompletionActionTest.php`

- [ ] **Step 1: Write the failing test for incremental memory pass-through**

Add to `tests/Unit/Actions/RecordJobCompletionActionTest.php`:

```php
it('passes incremental memory to repository', function () {
    $this->repository->shouldReceive('recordCompletion')
        ->once()
        ->with(
            'job-inc',
            'App\Jobs\ProcessOrder',
            'redis',
            'default',
            1500.5,
            64.0,
            300.0,
            Mockery::type(Carbon::class),
            null,
            8.5,
        );

    $this->action->execute(
        jobId: 'job-inc',
        jobClass: 'App\Jobs\ProcessOrder',
        connection: 'redis',
        queue: 'default',
        durationMs: 1500.5,
        memoryMb: 64.0,
        cpuTimeMs: 300.0,
        memoryIncrementalMb: 8.5,
    );
})->group('functional');

it('defaults incremental memory to zero', function () {
    $this->repository->shouldReceive('recordCompletion')
        ->once()
        ->with(
            'job-no-inc',
            'App\Jobs\ProcessOrder',
            'redis',
            'default',
            1500.5,
            64.0,
            300.0,
            Mockery::type(Carbon::class),
            null,
            0.0,
        );

    $this->action->execute(
        jobId: 'job-no-inc',
        jobClass: 'App\Jobs\ProcessOrder',
        connection: 'redis',
        queue: 'default',
        durationMs: 1500.5,
        memoryMb: 64.0,
        cpuTimeMs: 300.0,
    );
})->group('functional');
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test tests/Unit/Actions/RecordJobCompletionActionTest.php --filter="incremental memory"`
Expected: FAIL — parameter doesn't exist.

- [ ] **Step 3: Update existing tests for new parameter**

All existing `shouldReceive('recordCompletion')->with(...)` calls need the `0.0` incremental parameter appended. Update each test's `->with(...)` block to add `0.0` as the final argument. There are 7 existing tests — each one's `->with(...)` gets `0.0` appended after the final `null`.

For example, the first test becomes:

```php
$this->repository->shouldReceive('recordCompletion')
    ->once()
    ->with(
        'job-123',
        'App\Jobs\ProcessOrder',
        'redis',
        'default',
        1500.5,
        25.3,
        300.0,
        Mockery::type(Carbon::class),
        null,
        0.0,
    );
```

Apply the same pattern to all 7 existing tests.

- [ ] **Step 4: Implement `RecordJobCompletionAction` changes**

Update `src/Actions/RecordJobCompletionAction.php`:

```php
public function execute(
    string|int $jobId,
    string $jobClass,
    string $connection,
    string $queue,
    float $durationMs,
    float $memoryMb,
    float $memoryIncrementalMb = 0.0,
    float $cpuTimeMs = 0.0,
    ?string $hostname = null,
): void {
    if (! config('queue-metrics.enabled', true)) {
        return;
    }

    $this->repository->recordCompletion(
        jobId: $jobId,
        jobClass: $jobClass,
        connection: $connection,
        queue: $queue,
        durationMs: $durationMs,
        memoryMb: $memoryMb,
        cpuTimeMs: $cpuTimeMs,
        completedAt: Carbon::now(),
        hostname: $hostname,
        memoryIncrementalMb: $memoryIncrementalMb,
    );
}
```

Note: `memoryIncrementalMb` is placed before `cpuTimeMs` in the action's execute signature (both have defaults, so callers using named arguments are unaffected). This puts the two memory parameters together logically. The repository call uses named arguments so order doesn't matter there.

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test tests/Unit/Actions/RecordJobCompletionActionTest.php`
Expected: All tests PASS.

- [ ] **Step 6: Commit**

```bash
git add src/Actions/RecordJobCompletionAction.php tests/Unit/Actions/RecordJobCompletionActionTest.php
git commit -m "feat: add memoryIncrementalMb to RecordJobCompletionAction"
```

---

### Task 4: Update events

**Files:**
- Modify: `src/Events/JobMetricsCompleted.php`
- Modify: `src/Events/JobMetricsFailed.php`

- [ ] **Step 1: Update `JobMetricsCompleted`**

In `src/Events/JobMetricsCompleted.php`, add the new parameter:

```php
public function __construct(
    public readonly string $jobId,
    public readonly string $jobClass,
    public readonly string $connection,
    public readonly string $queue,
    public readonly float $durationMs,
    public readonly float $memoryMb,
    public readonly float $cpuTimeMs,
    public readonly ?string $hostname = null,
    public readonly ?float $workerMemoryLimitMb = null,
    public readonly float $memoryIncrementalMb = 0.0,
) {}
```

- [ ] **Step 2: Update `JobMetricsFailed`**

In `src/Events/JobMetricsFailed.php`, add the new parameter:

```php
public function __construct(
    public readonly string $jobId,
    public readonly string $jobClass,
    public readonly string $connection,
    public readonly string $queue,
    public readonly float $durationMs,
    public readonly float $memoryMb,
    public readonly float $cpuTimeMs,
    public readonly string $exceptionMessage,
    public readonly ?string $hostname = null,
    public readonly ?float $workerMemoryLimitMb = null,
    public readonly float $memoryIncrementalMb = 0.0,
) {}
```

- [ ] **Step 3: Run the full test suite to verify no breakage**

Run: `php artisan test`
Expected: All existing tests PASS (new parameters have defaults).

- [ ] **Step 4: Commit**

```bash
git add src/Events/JobMetricsCompleted.php src/Events/JobMetricsFailed.php
git commit -m "feat: add memoryIncrementalMb to job metrics events"
```

---

### Task 5: Update listeners to compute and pass both metrics

**Files:**
- Modify: `src/Listeners/JobProcessedListener.php`
- Modify: `src/Listeners/JobFailedListener.php`
- Modify: `tests/Unit/Listeners/JobMemoryDeltaTest.php`

- [ ] **Step 1: Write failing tests for dual memory values**

Replace the existing tests in `tests/Unit/Listeners/JobMemoryDeltaTest.php`. The key change: `recordCompletion` mock now captures both `memoryMb` (peak RSS) and `memoryIncrementalMb` (delta), and the event dispatch also carries both.

First, update the `withArgs` closure pattern used in all tests. Every `shouldReceive('recordCompletion')` block changes to capture both values. Here's the updated first test as the pattern:

```php
it('reports peak RSS as memoryMb and incremental as memoryIncrementalMb', function () {
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
    $capturedMemoryIncrementalMb = null;
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
            $completedAt,
            ?string $hostname,
            float $memoryIncrementalMb,
        ) use (&$capturedMemoryMb, &$capturedMemoryIncrementalMb) {
            $capturedMemoryMb = $memoryMb;
            $capturedMemoryIncrementalMb = $memoryIncrementalMb;

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

    // Peak RSS: 72 MB, Incremental: 8 MB (72 - 64)
    expect($capturedMemoryMb)->toBe(72.0)
        ->and($capturedMemoryIncrementalMb)->toBe(8.0);
})->group('functional');
```

Update the sleep job test:

```php
it('reports peak RSS for sleep jobs with near-zero incremental', function () {
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
    $capturedMemoryIncrementalMb = null;
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
            $completedAt,
            ?string $hostname,
            float $memoryIncrementalMb,
        ) use (&$capturedMemoryMb, &$capturedMemoryIncrementalMb) {
            $capturedMemoryMb = $memoryMb;
            $capturedMemoryIncrementalMb = $memoryIncrementalMb;

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

    // Peak RSS: 64 MB (full worker cost), Incremental: 0 MB
    expect($capturedMemoryMb)->toBe(64.0)
        ->and($capturedMemoryIncrementalMb)->toBe(0.0);
})->group('functional');
```

Update the fallback test:

```php
it('falls back to peak RSS for both values when baseline is missing', function () {
    Event::fake([JobMetricsCompleted::class]);

    // CPU baseline exists but memory baseline does NOT
    JobCpuSnapshotCache::store('job-no-mem-baseline', 1000.0);

    $source = createMemoryTestSource(
        memoryRssBytes: 80 * 1024 * 1024,
        userCpuMs: 1010,
        systemCpuMs: 500,
    );
    ProcessMetrics::setSource($source);
    ProcessMetrics::start(pid: getmypid(), trackerId: 'job_job-no-mem-baseline', includeChildren: false);
    usleep(10_000);

    $capturedMemoryMb = null;
    $capturedMemoryIncrementalMb = null;
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
            $completedAt,
            ?string $hostname,
            float $memoryIncrementalMb,
        ) use (&$capturedMemoryMb, &$capturedMemoryIncrementalMb) {
            $capturedMemoryMb = $memoryMb;
            $capturedMemoryIncrementalMb = $memoryIncrementalMb;

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

    // Both fall back to peak RSS: 80 MB
    expect($capturedMemoryMb)->toBe(80.0)
        ->and($capturedMemoryIncrementalMb)->toBe(80.0);
})->group('functional');
```

Update the cleanup test (this one doesn't check memory values, just use `->once()` without `withArgs`):

```php
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
```

Update the distinguishable values test:

```php
it('two jobs with different allocation sizes report distinguishable values', function () {
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

    $capturedSmallPeak = null;
    $capturedSmallIncremental = null;
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
            $completedAt,
            ?string $hostname,
            float $memoryIncrementalMb,
        ) use (&$capturedSmallPeak, &$capturedSmallIncremental) {
            $capturedSmallPeak = $memoryMb;
            $capturedSmallIncremental = $memoryIncrementalMb;

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

    $capturedLargePeak = null;
    $capturedLargeIncremental = null;
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
            $completedAt,
            ?string $hostname,
            float $memoryIncrementalMb,
        ) use (&$capturedLargePeak, &$capturedLargeIncremental) {
            $capturedLargePeak = $memoryMb;
            $capturedLargeIncremental = $memoryIncrementalMb;

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

    // Peak RSS: similar baseline (72 vs 264), but incremental differs hugely
    expect($capturedSmallPeak)->toBe(72.0)
        ->and($capturedSmallIncremental)->toBe(8.0)
        ->and($capturedLargePeak)->toBe(264.0)
        ->and($capturedLargeIncremental)->toBe(200.0);

    // Incremental values are distinguishable (the whole point of v3.1.0's fix)
    expect($capturedLargeIncremental)->toBeGreaterThan($capturedSmallIncremental * 10);
})->group('functional');
```

Update the negative delta clamping test:

```php
it('clamps negative incremental to zero while peak RSS stays at actual peak', function () {
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
    $capturedMemoryIncrementalMb = null;
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
            $completedAt,
            ?string $hostname,
            float $memoryIncrementalMb,
        ) use (&$capturedMemoryMb, &$capturedMemoryIncrementalMb) {
            $capturedMemoryMb = $memoryMb;
            $capturedMemoryIncrementalMb = $memoryIncrementalMb;

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

    // Peak RSS: 72 MB (actual peak). Incremental: clamped to 0 (not negative)
    expect($capturedMemoryMb)->toBe(72.0)
        ->and($capturedMemoryIncrementalMb)->toBe(0.0);
})->group('functional');
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test tests/Unit/Listeners/JobMemoryDeltaTest.php`
Expected: FAIL — listener still passes only incremental as `memoryMb`.

- [ ] **Step 3: Update `JobProcessedListener::handle()`**

In `src/Listeners/JobProcessedListener.php`, replace the memory calculation block (lines 49-63) and the recording/event dispatch:

Replace the memory section:

```php
$memoryMb = memory_get_peak_usage(true) / 1024 / 1024;
$memoryIncrementalMb = $memoryMb;
$cpuTimeMs = 0.0;

if ($metricsResult->isSuccess()) {
    $metrics = $metricsResult->getValue();

    // Memory: peak RSS is the capacity-planning metric
    $peakMemoryMb = $metrics->peak->memoryRssBytes / 1024 / 1024;
    $startMemoryMb = JobMemorySnapshotCache::get($jobId);

    // Incremental allocation is the differentiation metric
    if ($startMemoryMb !== null) {
        $memoryIncrementalMb = max(0.0, $peakMemoryMb - $startMemoryMb);
    } else {
        $memoryIncrementalMb = $peakMemoryMb; // fallback for missing baseline
    }

    $memoryMb = $peakMemoryMb;

    // CPU time: delta between cumulative CPU times at end vs start
    $endCpuTimes = $metrics->current->cpuTimes;
    $endCpuTimeMs = (float) ($endCpuTimes->user + $endCpuTimes->system);
    $startCpuTimeMs = JobCpuSnapshotCache::get($jobId);

    if ($startCpuTimeMs !== null) {
        $cpuTimeMs = max(0.0, $endCpuTimeMs - $startCpuTimeMs);
    }
}
```

Update the `recordJobCompletion->execute()` call:

```php
$this->recordJobCompletion->execute(
    jobId: $jobId,
    jobClass: $jobClass,
    connection: $connection,
    queue: $queue,
    durationMs: $durationMs,
    memoryMb: $memoryMb,
    memoryIncrementalMb: $memoryIncrementalMb,
    cpuTimeMs: $cpuTimeMs,
    hostname: $hostname,
);
```

Update the `JobMetricsCompleted::dispatch()` call:

```php
JobMetricsCompleted::dispatch(
    $jobId,
    $jobClass,
    $connection,
    $queue,
    $durationMs,
    $memoryMb,
    $cpuTimeMs,
    $hostname,
    MemoryLimitParser::getCurrentLimitMb(),
    $memoryIncrementalMb,
);
```

- [ ] **Step 4: Update `JobFailedListener::handle()`**

In `src/Listeners/JobFailedListener.php`, apply the same memory calculation change. Replace lines 42-66:

```php
$memoryMb = memory_get_peak_usage(true) / 1024 / 1024;
$memoryIncrementalMb = $memoryMb;
$cpuTimeMs = 0.0;

if ($metricsResult->isSuccess()) {
    $metrics = $metricsResult->getValue();

    // Memory: peak RSS is the capacity-planning metric
    $peakMemoryMb = $metrics->peak->memoryRssBytes / 1024 / 1024;
    $startMemoryMb = JobMemorySnapshotCache::get($jobId);

    // Incremental allocation is the differentiation metric
    if ($startMemoryMb !== null) {
        $memoryIncrementalMb = max(0.0, $peakMemoryMb - $startMemoryMb);
    } else {
        $memoryIncrementalMb = $peakMemoryMb; // fallback for missing baseline
    }

    $memoryMb = $peakMemoryMb;

    // CPU time: delta between cumulative CPU times at end vs start
    $endCpuTimes = $metrics->current->cpuTimes;
    $endCpuTimeMs = (float) ($endCpuTimes->user + $endCpuTimes->system);
    $startCpuTimeMs = JobCpuSnapshotCache::get($jobId);

    if ($startCpuTimeMs !== null) {
        $cpuTimeMs = max(0.0, $endCpuTimeMs - $startCpuTimeMs);
    }
}
```

Update the `JobMetricsFailed::dispatch()` call:

```php
JobMetricsFailed::dispatch(
    $jobId,
    $jobClass,
    $connection,
    $queue,
    $durationMs,
    $memoryMb,
    $cpuTimeMs,
    $exceptionMessage,
    $hostname,
    MemoryLimitParser::getCurrentLimitMb(),
    $memoryIncrementalMb,
);
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test tests/Unit/Listeners/JobMemoryDeltaTest.php`
Expected: All tests PASS.

- [ ] **Step 6: Commit**

```bash
git add src/Listeners/JobProcessedListener.php src/Listeners/JobFailedListener.php tests/Unit/Listeners/JobMemoryDeltaTest.php
git commit -m "feat: listeners report peak RSS as memoryMb and delta as memoryIncrementalMb"
```

---

### Task 6: Update `CalculateJobMetricsAction` to compute `avgIncremental`

**Files:**
- Modify: `src/Actions/CalculateJobMetricsAction.php`

- [ ] **Step 1: Update `calculateMemory()` to accept and use hash metrics**

In `src/Actions/CalculateJobMetricsAction.php`, update the `execute()` method to pass `$metrics` to `calculateMemory()`:

```php
memory: $this->calculateMemory($jobClass, $connection, $queue, $metrics),
```

Update the `calculateMemory()` method signature and implementation:

```php
/**
 * @param  array<string, mixed>  $metrics
 */
private function calculateMemory(
    string $jobClass,
    string $connection,
    string $queue,
    array $metrics,
): MemoryStats {
    $samples = $this->repository->getMemorySamples($jobClass, $connection, $queue);

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

- [ ] **Step 2: Run the full test suite**

Run: `php artisan test`
Expected: All tests PASS.

- [ ] **Step 3: Commit**

```bash
git add src/Actions/CalculateJobMetricsAction.php
git commit -m "feat: compute avgIncremental from hash totals in CalculateJobMetricsAction"
```

---

### Task 7: Update Prometheus export

**Files:**
- Modify: `src/Services/PrometheusService.php`

- [ ] **Step 1: Add incremental memory gauge**

In `src/Services/PrometheusService.php`, inside the `exportJobMetrics()` method, within the `if ($memory !== null)` block (around line 225), add after the existing `job_memory_p99_megabytes` gauge:

```php
// Incremental memory (job allocation above baseline)
Prometheus::addGauge('job_memory_avg_incremental_megabytes')
    ->name('job_memory_avg_incremental_megabytes')
    ->namespace($namespace)
    ->label('job')
    ->label('queue')
    ->label('connection')
    ->helpText('Job incremental memory allocation in megabytes (peak minus baseline)')
    ->value($memory['avg_incremental'] ?? 0.0, [$job, $queue, $connection]);
```

- [ ] **Step 2: Run the full test suite**

Run: `php artisan test`
Expected: All tests PASS.

- [ ] **Step 3: Commit**

```bash
git add src/Services/PrometheusService.php
git commit -m "feat: export job_memory_avg_incremental_megabytes Prometheus gauge"
```

---

### Task 8: Run Pint and full test suite

**Files:**
- All modified files

- [ ] **Step 1: Run Pint code formatter**

Run: `vendor/bin/pint --dirty`
Expected: All files formatted (or already clean).

- [ ] **Step 2: Run the full test suite**

Run: `php artisan test`
Expected: All tests PASS.

- [ ] **Step 3: Commit any formatting fixes**

If Pint made changes:

```bash
git add -u
git commit -m "style: apply Pint formatting"
```

---

### Task 9: Update CHANGELOG

**Files:**
- Modify: `CHANGELOG.md`

- [ ] **Step 1: Add v3.2.0 entry**

Add a new section at the top of `CHANGELOG.md`:

```markdown
## v3.2.0 - Unreleased

### Added
- `memory.avg_incremental` field in job metrics output — reports incremental memory allocation (peak minus baseline) for job-class differentiation
- `memoryIncrementalMb` parameter on `RecordJobCompletionAction::execute()`, repository contract, and job events
- `job_memory_avg_incremental_megabytes` Prometheus gauge

### Changed
- `memory.avg`, `memory.peak`, `memory.p95`, `memory.p99` now report peak RSS (worker's actual memory footprint during job) instead of incremental allocation — restores correct capacity-planning semantics for consumers like queue-autoscale
- `memoryMb` in `JobMetricsCompleted` and `JobMetricsFailed` events now carries peak RSS instead of incremental

### Fixed
- queue-autoscale capacity estimates were ~10x too low because `memory.avg` reported incremental allocation (~6 MB) instead of actual worker footprint (~50-80 MB) (#20)
```

- [ ] **Step 2: Commit**

```bash
git add CHANGELOG.md
git commit -m "docs: add v3.2.0 changelog entry for dual memory metrics"
```
