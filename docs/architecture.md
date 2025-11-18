# Architecture Overview

Understanding how Laravel Queue Metrics works under the hood.

## High-Level Architecture

```
┌─────────────────────────────────────────────────────────┐
│                   Laravel Application                   │
├─────────────────────────────────────────────────────────┤
│                                                         │
│  ┌──────────────┐         ┌──────────────────────────┐  │
│  │   Job Queue  │────────▶│   Event Listeners        │  │
│  │              │         │  - JobProcessing         │  │
│  │ ProcessOrder │         │  - JobProcessed          │  │
│  │ SendEmail    │         │  - JobFailed             │  │
│  └──────────────┘         └──────────┬───────────────┘  │
│                                      │                  │
│                                      ▼                  │
│                           ┌───────────────────────┐     │
│                           │   Actions Layer       │     │
│                           │  - RecordJobStart     │     │
│                           │  - RecordCompletion   │     │
│                           │  - RecordFailure      │     │
│                           └───────┬───────────────┘     │
│                                   │                     │
│                                   ▼                     │
│                           ┌───────────────────────┐     │
│                           │   Hooks Pipeline      │     │
│                           │  before_record hooks  │     │
│                           └───────┬───────────────┘     │
│                                   │                     │
│                                   ▼                     │
│                           ┌───────────────────────┐     │
│                           │   Repository Layer    │     │
│                           │  - JobMetricsRepo     │     │
│                           │  - QueueMetricsRepo   │     │
│                           │  - WorkerRepo         │     │
│                           └───────┬───────────────┘     │
│                                   │                     │
│                                   ▼                     │
│                           ┌───────────────────────┐     │
│                           │  Storage Driver       │     │
│                           │  - Redis / Database   │     │
│                           └───────┬───────────────┘     │
│                                   │                     │
└───────────────────────────────────┼─────────────────────┘
                                    │
                    ┌───────────────┴───────────────┐
                    │                               │
                    ▼                               ▼
            ┌──────────────┐              ┌──────────────┐
            │  Redis       │              │  Database    │
            │              │              │              │
            │ queue_metrics│              │ queue_job_   │
            │ :jobs:*      │              │ metrics      │
            │ :queues:*    │              └──────────────┘
            │ :workers:*   │
            └──────────────┘
```

## Core Components

### 1. Event Listeners

**Purpose**: Capture Laravel queue events automatically

**Location**: `src/Listeners/`

**Key Classes**:
- `JobProcessingListener` - Captures job start
- `JobProcessedListener` - Captures job completion
- `JobFailedListener` - Captures job failures

**Flow**:

```php
// Laravel dispatches events
event(new JobProcessing($connection, $job));

// Listener captures event
JobProcessingListener::handle($event) {
    // Extract job data
    $jobId = $job->getJobId();
    $jobClass = $job->resolveName();

    // Delegate to Action
    RecordJobStartAction::execute($jobId, $jobClass, ...);
}
```

**Auto-Registration**: Listeners are automatically registered via the service provider.

### 2. Actions Layer

**Purpose**: Business logic for recording metrics

**Location**: `src/Actions/`

**Pattern**: Command pattern - each action does one thing

**Key Classes**:
- `RecordJobStartAction` - Record job start time
- `RecordJobCompletionAction` - Record successful completion
- `RecordJobFailureAction` - Record job failure
- `CalculateBaselineAction` - Calculate performance baseline
- `TransitionWorkerStateAction` - Update worker state

**Characteristics**:
- **Readonly classes**: Immutable, dependency injection only
- **Single responsibility**: Each action has one clear purpose
- **Hookable**: Use `Hookable` trait for pipeline hooks
- **Type-safe**: Full type declarations

**Example**:

```php
final readonly class RecordJobCompletionAction
{
    use Hookable;

    public function __construct(
        private JobMetricsRepository $repository
    ) {}

    public function execute(
        string $jobId,
        string $jobClass,
        string $connection,
        string $queue,
        float $durationMs,
        float $memoryMb,
        float $cpuTimeMs = 0.0,
        ?string $hostname = null,
    ): void {
        // Check if enabled
        if (!config('queue-metrics.enabled')) {
            return;
        }

        // Prepare data
        $data = [
            'job_id' => $jobId,
            'job_class' => $jobClass,
            // ...
        ];

        // Run before_record hooks (can transform data)
        $data = $this->runHooks('before_record', $data);

        // Type assertions for PHPStan
        assert(is_array($data));
        assert(is_string($data['job_id']));
        // ...

        // Record to storage
        $this->repository->recordCompletion(...);

        // Run after_record hooks (cannot transform)
        $this->runHooks('after_record', $data);
    }
}
```

### 3. Hooks System

**Purpose**: Allow data transformation before storage

**Location**: `src/Support/Concerns/Hookable.php`, `src/Support/HookManager.php`

**Pattern**: Pipeline pattern via Laravel Pipeline

**Flow**:

```php
// 1. Action calls runHooks()
$data = $this->runHooks('before_record', $data);

// 2. Hookable trait delegates to HookManager
protected function runHooks(string $context, mixed $payload): mixed {
    return app(HookManager::class)->execute($context, $payload);
}

// 3. HookManager executes via Pipeline
HookManager::execute($context, $payload) {
    $hooks = $this->getHooksForContext($context);

    return app(HookPipeline::class)
        ->send($payload)
        ->through($hooks)
        ->thenReturn();
}

// 4. Each hook transforms payload
Hook::handle($payload, $next) {
    $payload['tenant_id'] = tenant('id');
    return $next($payload);
}
```

**Key Features**:
- Priority-based execution (lower = earlier)
- Chainable transformations
- Can short-circuit pipeline
- Statamic-inspired trait pattern

See [Hooks](hooks.md) for detailed usage.

### 4. Repository Layer

**Purpose**: Data access abstraction

**Location**: `src/Repositories/`

**Pattern**: Repository pattern with contracts

**Key Interfaces**:
- `JobMetricsRepository` - Job execution data
- `QueueMetricsRepository` - Queue depth and health
- `WorkerRepository` - Worker status tracking
- `WorkerHeartbeatRepository` - Worker heartbeats
- `BaselineRepository` - Performance baselines

**Implementations**:
- `RedisJobMetricsRepository`
- `RedisQueueMetricsRepository`
- `RedisWorkerRepository`
- etc.

**Why Repositories?**:
- Decouples business logic from storage
- Enables storage driver swapping
- Testable with mocks
- Consistent API across drivers

**Example**:

```php
interface JobMetricsRepository
{
    public function recordStart(
        string $jobId,
        string $jobClass,
        string $connection,
        string $queue,
        Carbon $startedAt
    ): void;

    public function recordCompletion(
        string $jobId,
        string $jobClass,
        string $connection,
        string $queue,
        float $durationMs,
        float $memoryMb,
        float $cpuTimeMs,
        Carbon $completedAt,
        ?string $hostname
    ): void;

    // ...
}
```

### 5. Storage Drivers

**Purpose**: Pluggable storage backends

**Location**: `src/Storage/`

**Pattern**: Strategy pattern

**Available Drivers**:
- `RedisStorageDriver` - Fast, automatic TTL
- `DatabaseStorageDriver` - Persistent, queryable
- `NullStorageDriver` - No-op for testing

**Key Operations**:
- `get(string $key): mixed`
- `set(string $key, mixed $value, ?int $ttl): void`
- `increment(string $key, int $value, ?int $ttl): int`
- `scan(string $pattern): array`
- `transaction(Closure $callback): mixed`

**Driver Selection**:

```php
// config/queue-metrics.php
'storage' => [
    'driver' => 'redis', // 'redis', 'database', 'null'
],
```

**Why Separate Drivers?**:
- Different use cases (speed vs persistence)
- Easy testing with NullDriver
- Can add custom drivers (Memcached, DynamoDB, etc.)

### 6. Service Layer

**Purpose**: High-level business operations

**Location**: `src/Services/`

**Key Services**:
- `JobMetricsQueryService` - Job metrics queries
- `QueueMetricsQueryService` - Queue health queries
- `WorkerMetricsQueryService` - Worker queries
- `OverviewQueryService` - System-wide overview
- `TrendAnalysisService` - Trend calculations
- `PrometheusService` - Prometheus export
- `ServerMetricsService` - Server resource metrics

**Pattern**: Service layer on top of repositories

**Example**:

```php
class JobMetricsQueryService
{
    public function __construct(
        private JobMetricsRepository $repository,
        private BaselineRepository $baselineRepository,
        private TrendAnalysisService $trendService
    ) {}

    public function getJobMetrics(
        string $jobClass,
        string $connection = 'default',
        string $queue = 'default'
    ): JobMetricsData {
        // Get raw metrics from repository
        $metrics = $this->repository->getMetrics($jobClass, $connection, $queue);

        // Get baseline for comparison
        $baseline = $this->baselineRepository->getBaseline($connection, $queue, $jobClass);

        // Get trend analysis
        $trend = $this->trendService->analyzeJobTrend($jobClass, $connection, $queue);

        // Combine into DTO
        return new JobMetricsData(
            jobClass: $jobClass,
            connection: $connection,
            queue: $queue,
            metrics: $metrics,
            baseline: $baseline,
            trend: $trend,
        );
    }
}
```

### 7. Data Transfer Objects (DTOs)

**Purpose**: Type-safe, immutable data structures

**Location**: `src/DataTransferObjects/`

**Pattern**: Readonly DTOs

**Key DTOs**:
- `JobMetricsData` - Complete job metrics
- `QueueMetricsData` - Queue health data
- `DurationStatsDTO` - Duration statistics
- `MemoryStatsDTO` - Memory statistics
- `ThroughputStatsDTO` - Throughput metrics
- `HealthDataDTO` - Health assessment
- `TrendDataDTO` - Trend analysis
- `BaselineDataDTO` - Baseline comparison

**Characteristics**:
- **Readonly**: Immutable after creation
- **Type-safe**: Full type declarations
- **Self-documenting**: Clear property names
- **Serializable**: Can convert to/from arrays

**Example**:

```php
final readonly class JobMetricsData
{
    public function __construct(
        public string $jobClass,
        public string $connection,
        public string $queue,
        public int $totalProcessed,
        public int $totalFailed,
        public float $failureRate,
        public DurationStatsDTO $duration,
        public MemoryStatsDTO $memory,
        public ThroughputStatsDTO $throughput,
        public ?FailureInfoDTO $lastFailure,
        public ?TrendDataDTO $trend,
        public ?BaselineDataDTO $baseline,
    ) {}

    public function toArray(): array {
        return [
            'job_class' => $this->jobClass,
            'connection' => $this->connection,
            // ...
        ];
    }
}
```

### 8. Events System

**Purpose**: Notify about significant occurrences

**Location**: `src/Events/`

**Pattern**: Observer pattern via Laravel events

**Key Events**:
- `MetricsRecorded` - After metrics recorded
- `WorkerEfficiencyChanged` - Worker efficiency change
- `HealthScoreChanged` - Queue health change
- `BaselineRecalculated` - Baseline updated
- `QueueDepthThresholdExceeded` - Queue depth high

**Flow**:

```php
// After recording metrics
event(new MetricsRecorded($metricsData));

// Listeners react
class SendMetricsToDatadog {
    public function handle(MetricsRecorded $event) {
        Datadog::gauge('queue.duration', $event->metrics->duration->average);
    }
}
```

See [Events](events.md) for detailed usage.

## Data Flow

### Job Execution Flow

```
1. Job Dispatched
   └─▶ Laravel adds to queue

2. Worker Picks Up Job
   └─▶ JobProcessing event
       └─▶ JobProcessingListener
           └─▶ RecordJobStartAction
               └─▶ before_record hooks
                   └─▶ JobMetricsRepository::recordStart()
                       └─▶ Redis/Database storage

3. Job Executes
   └─▶ Your job code runs

4a. Job Succeeds
    └─▶ JobProcessed event
        └─▶ JobProcessedListener
            └─▶ RecordJobCompletionAction
                └─▶ before_record hooks
                    └─▶ JobMetricsRepository::recordCompletion()
                        └─▶ Storage
                        └─▶ after_record hooks
                        └─▶ MetricsRecorded event

4b. Job Fails
    └─▶ JobFailed event
        └─▶ JobFailedListener
            └─▶ RecordJobFailureAction
                └─▶ before_record hooks
                    └─▶ JobMetricsRepository::recordFailure()
                        └─▶ Storage
                        └─▶ after_record hooks
```

### Query Flow

```
1. Facade Call
   QueueMetrics::getJobMetrics($jobClass)
   └─▶ QueueMetrics facade
       └─▶ Routes to JobMetricsQueryService

2. Service Layer
   JobMetricsQueryService::getJobMetrics()
   ├─▶ JobMetricsRepository::getMetrics()
   │   └─▶ Storage driver fetch
   ├─▶ BaselineRepository::getBaseline()
   │   └─▶ Storage driver fetch
   └─▶ TrendAnalysisService::analyzeJobTrend()
       └─▶ Calculate trends

3. Data Assembly
   ├─▶ Create DTOs
   ├─▶ Calculate derived metrics
   └─▶ Return JobMetricsData

4. Response
   └─▶ Return to caller
```

## Queue Detection Strategy

The package uses a **3-layer fallback strategy** for maximum compatibility:

### Layer 1: Laravel 12.19+ Native API (Best)

```php
if (method_exists($queue, 'pendingSize')) {
    $pending = $queue->pendingSize();
    $delayed = $queue->delayedSize();
    $reserved = $queue->reservedSize();
}
```

### Layer 2: Driver-Specific (Good)

```php
// Redis
if ($driver === 'redis') {
    $pending = $redis->llen("queues:$queue");
    $delayed = $redis->zcard("queues:$queue:delayed");
    $reserved = $redis->zcard("queues:$queue:reserved");
}

// Database
if ($driver === 'database') {
    $pending = DB::table('jobs')
        ->where('queue', $queue)
        ->whereNull('reserved_at')
        ->count();
}
```

### Layer 3: Generic Fallback (Basic)

```php
// Use size() method (only pending count)
$size = $queue->size();
```

This ensures the package works across all Laravel versions and queue drivers.

## Storage Schema

### Redis Keys Structure

```
queue_metrics:{type}:{connection}:{queue}:{identifier}

Examples:
queue_metrics:jobs:redis:default:App\Jobs\ProcessOrder
queue_metrics:queues:redis:default
queue_metrics:workers:redis:default:worker-12345
queue_metrics:baselines:redis:default:App\Jobs\ProcessOrder
```

### Database Tables

```sql
-- Job metrics
queue_job_metrics:
  - id
  - job_id
  - job_class
  - connection
  - queue
  - started_at
  - completed_at
  - duration_ms
  - memory_mb
  - cpu_time_ms
  - status (completed/failed)
  - exception
  - created_at

-- Queue snapshots
queue_snapshots:
  - id
  - connection
  - queue
  - depth_pending
  - depth_delayed
  - depth_reserved
  - recorded_at
  - created_at

-- Worker heartbeats
worker_heartbeats:
  - id
  - worker_id
  - connection
  - queue
  - status
  - current_job
  - memory_mb
  - cpu_percent
  - last_heartbeat
  - created_at
```

## Extension Points

The architecture provides several extension points:

1. **Custom Storage Drivers**: Implement `StorageDriver` interface
2. **Custom Hooks**: Register via `QueueMetrics::hook()`
3. **Event Listeners**: React to domain events
4. **Custom Services**: Build on repository layer
5. **Custom DTOs**: Extend data structures

## Performance Characteristics

**Per-Job Overhead**:
- Event listener: ~0.1ms
- Action execution: ~0.5ms
- Hook pipeline: ~0.1-0.5ms per hook
- Repository storage: ~0.5-1ms (Redis), ~5-10ms (Database)
- **Total**: ~1-2ms per job (Redis), ~5-15ms (Database)

**Memory Overhead**:
- Package classes: ~5-10MB (loaded once)
- Per-job data: ~1-2KB in storage

**Query Performance**:
- Facade calls: ~1-5ms (Redis), ~10-50ms (Database)
- Overview generation: ~10-50ms depending on queue count
- Trend analysis: ~50-200ms depending on data volume

## Next Steps

- [Storage Drivers](storage-drivers.md) - Deep dive into storage
- [Performance Tuning](performance.md) - Optimize for scale
- [Custom Storage Drivers](custom-storage-drivers.md) - Build your own
- [Data Flow](data-flow.md) - Detailed flow diagrams
