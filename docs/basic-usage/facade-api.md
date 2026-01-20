---
title: "Facade API"
description: "PHP facade interface for programmatic access to queue metrics"
weight: 10
---

# Facade API

The `QueueMetrics` facade provides a clean, developer-friendly interface for accessing queue metrics. It's the recommended way to interact with the package programmatically.

## Overview

```php
use Cbox\LaravelQueueMetrics\Facades\QueueMetrics;

// Get job metrics
$metrics = QueueMetrics::getJobMetrics(
    jobClass: \App\Jobs\ProcessOrder::class,
    connection: 'redis',
    queue: 'default'
);

// Get queue health
$queue = QueueMetrics::getQueueMetrics('redis', 'default');

// Get system overview
$overview = QueueMetrics::getOverview();
```

## Job Metrics

### getJobMetrics()

Retrieve detailed metrics for a specific job class.

```php
$metrics = QueueMetrics::getJobMetrics(
    jobClass: \App\Jobs\ProcessOrder::class,
    connection: 'redis',  // optional, defaults to 'default'
    queue: 'default'      // optional, defaults to 'default'
);
```

**Returns**: `JobMetricsData` DTO

**Available properties**:

```php
// Basic information
$metrics->jobClass;          // string: 'App\\Jobs\\ProcessOrder'
$metrics->connection;        // string: 'redis'
$metrics->queue;             // string: 'default'
$metrics->calculatedAt;      // Carbon: When metrics were calculated

// Execution statistics (JobExecutionData)
$metrics->execution->totalProcessed;  // int: Total jobs completed
$metrics->execution->totalFailed;     // int: Total jobs failed
$metrics->execution->successRate;     // float: Success percentage (0-100)
$metrics->execution->failureRate;     // float: Failure percentage (0-100)

// Duration statistics (DurationStats)
$metrics->duration->avg;     // float: Average duration in milliseconds
$metrics->duration->min;     // float: Fastest execution
$metrics->duration->max;     // float: Slowest execution
$metrics->duration->p50;     // float: 50th percentile (median)
$metrics->duration->p95;     // float: 95th percentile
$metrics->duration->p99;     // float: 99th percentile
$metrics->duration->stddev;  // float: Standard deviation

// Memory usage (MemoryStats)
$metrics->memory->avg;       // float: Average memory in MB
$metrics->memory->peak;      // float: Peak memory usage
$metrics->memory->p95;       // float: 95th percentile memory
$metrics->memory->p99;       // float: 99th percentile memory

// Throughput (ThroughputStats)
$metrics->throughput->perMinute;  // float: Jobs per minute
$metrics->throughput->perHour;    // float: Jobs per hour
$metrics->throughput->perDay;     // float: Jobs per day

// Failure information (FailureInfo)
$metrics->failures->count;          // int: Total failures
$metrics->failures->rate;           // float: Failure rate percentage
$metrics->failures->lastFailedAt;   // ?Carbon: When last failure occurred
$metrics->failures->lastException;  // ?string: Last exception message

// Window statistics
$metrics->windowStats;       // array<WindowStats>: Time-windowed metrics
```

**Example - Performance monitoring**:

```php
$metrics = QueueMetrics::getJobMetrics(ProcessOrder::class);

if ($metrics->duration->p95 > 5000) { // P95 >5 seconds
    Slack::send("⚠️ {$metrics->jobClass} is slow: P95 {$metrics->duration->p95}ms");
}

if ($metrics->execution->failureRate > 5) { // >5% failure rate
    PagerDuty::alert("{$metrics->jobClass} failure rate: {$metrics->execution->failureRate}%");
}

// Check last failure
if ($metrics->failures->lastFailedAt) {
    Log::warning("Recent job failures detected", [
        'job' => $metrics->jobClass,
        'last_failure' => $metrics->failures->lastFailedAt,
        'exception' => $metrics->failures->lastException,
    ]);
}
```

### getAllJobsWithMetrics()

Get metrics for all discovered job classes.

```php
$allJobs = QueueMetrics::getAllJobsWithMetrics();
```

**Returns**: `array<string, array<string, mixed>>`

**Structure**:

```php
[
    'App\\Jobs\\ProcessOrder' => [
        'job_class' => 'App\\Jobs\\ProcessOrder',
        'connection' => 'redis',
        'queue' => 'default',
        'execution' => [...],
        'duration' => [...],
        'memory' => [...],
        'throughput' => [...],
        'failures' => [...],
        'window_stats' => [...],
        'calculated_at' => '2024-01-15T10:30:00Z',
        'baseline' => [...] // if available
    ],
    'App\\Jobs\\SendEmail' => [...],
]
```

**Example - Dashboard overview**:

```php
$allJobs = QueueMetrics::getAllJobsWithMetrics();

foreach ($allJobs as $jobClass => $metricsArray) {
    echo "{$jobClass}:\n";
    echo "  Queue: {$metricsArray['connection']}:{$metricsArray['queue']}\n";
    echo "  Processed: {$metricsArray['execution']['total_processed']}\n";
    echo "  Failed: {$metricsArray['execution']['total_failed']}\n";
    echo "  Failure Rate: {$metricsArray['execution']['failure_rate']}%\n";
    echo "  Avg Duration: {$metricsArray['duration']['avg']}ms\n";
}
```

## Queue Metrics

### getQueueMetrics()

Retrieve health and performance metrics for a specific queue.

```php
$queue = QueueMetrics::getQueueMetrics(
    connection: 'redis',  // defaults to 'default'
    queue: 'default'      // defaults to 'default'
);
```

**Returns**: `QueueMetricsData` DTO

**Available properties**:

```php
// Queue identification
$queue->connection;          // string: 'redis'
$queue->queue;               // string: 'default'

// Queue depth (QueueDepthDataDTO)
$queue->depth->pending;      // int: Jobs ready to process
$queue->depth->delayed;      // int: Jobs scheduled for future
$queue->depth->reserved;     // int: Jobs currently processing
$queue->depth->total;        // int: Sum of all states
$queue->depth->oldestJobAgeSeconds; // int|null: Age of oldest job

// Processing metrics
$queue->processingRate;      // float: Jobs per minute
$queue->failureRate;         // float: Failure percentage
$queue->activeWorkerCount;   // int: Workers processing this queue

// Health assessment (HealthDataDTO)
$queue->health->score;       // float: 0-100 health score
$queue->health->status;      // 'healthy'|'warning'|'critical'
$queue->health->issues;      // array: Detected problems

// Trend (TrendDataDTO|null)
$queue->trend?->direction;   // 'up'|'down'|'stable'
$queue->trend?->forecast;    // float: Predicted next value
```

**Example - Health monitoring**:

```php
$queue = QueueMetrics::getQueueMetrics('redis', 'default');

// Check health status
if ($queue->health->status === 'critical') {
    PagerDuty::alert("Queue {$queue->queue} is in critical state", [
        'score' => $queue->health->score,
        'issues' => $queue->health->issues,
    ]);
}

// Check depth
if ($queue->depth->total > 10000) {
    Log::warning("Queue depth high", [
        'queue' => $queue->queue,
        'depth' => $queue->depth->total,
        'oldest_job_age' => $queue->depth->oldestJobAgeSeconds,
    ]);
}

// Check processing rate
if ($queue->processingRate < 10) { // <10 jobs/minute
    Slack::send("⚠️ Queue {$queue->queue} processing rate low: {$queue->processingRate} jobs/min");
}
```

### getQueueDepth()

Get detailed queue depth information.

```php
$depth = QueueMetrics::getQueueDepth('redis', 'default');

echo "Pending: {$depth->pending}\n";
echo "Delayed: {$depth->delayed}\n";
echo "Reserved: {$depth->reserved}\n";
echo "Total: {$depth->total}\n";
```

### getAllQueuesWithMetrics()

Get metrics for all discovered queues.

```php
$allQueues = QueueMetrics::getAllQueuesWithMetrics();
```

**Returns**: `array<string, array<string, mixed>>`

**Structure**:

```php
[
    'redis' => [
        'default' => QueueMetricsData,
        'high-priority' => QueueMetricsData,
        'emails' => QueueMetricsData,
    ],
    'database' => [
        'default' => QueueMetricsData,
    ],
]
```

## Worker Metrics

### getActiveWorkers()

Get information about active queue workers.

```php
// All workers
$workers = QueueMetrics::getActiveWorkers();

// Specific connection
$workers = QueueMetrics::getActiveWorkers(connection: 'redis');

// Specific queue
$workers = QueueMetrics::getActiveWorkers(connection: 'redis', queue: 'default');
```

**Returns**: `Collection<WorkerHeartbeat>`

**Worker heartbeat properties**:

```php
foreach ($workers as $worker) {
    $worker->workerId;           // string: Unique worker ID
    $worker->pid;                // int: Process ID
    $worker->connection;         // string: Queue connection
    $worker->queue;              // string: Queue name
    $worker->status;             // WorkerState: idle|busy|paused|terminated
    $worker->currentJob;         // string|null: Current job class
    $worker->jobsProcessed;      // int: Total jobs processed
    $worker->jobsFailed;         // int: Total jobs failed
    $worker->memoryMb;           // float: Current memory usage
    $worker->cpuPercent;         // float: CPU usage percentage
    $worker->lastHeartbeat;      // Carbon: Last heartbeat timestamp
}
```

**Example - Worker monitoring**:

```php
$workers = QueueMetrics::getActiveWorkers('redis', 'default');

$busyWorkers = $workers->filter(fn($w) => $w->status === WorkerState::busy);
$idleWorkers = $workers->filter(fn($w) => $w->status === WorkerState::idle);

echo "Active workers: " . $workers->count() . "\n";
echo "Busy: " . $busyWorkers->count() . "\n";
echo "Idle: " . $idleWorkers->count() . "\n";

// Check for resource-intensive workers
$highMemory = $workers->filter(fn($w) => $w->memoryMb > 512);
if ($highMemory->isNotEmpty()) {
    Log::warning("High memory workers detected", [
        'workers' => $highMemory->pluck('workerId'),
    ]);
}
```

### detectStaledWorkers()

Find workers that haven't sent heartbeats recently.

```php
$staleCount = QueueMetrics::detectStaledWorkers(
    thresholdSeconds: 60  // defaults to config value
);

echo "Found {$staleCount} stale workers\n";
```

## System Overview

### getOverview()

Get a system-wide summary of all queues, jobs, and workers.

```php
$overview = QueueMetrics::getOverview();
```

**Returns**: `array<string, mixed>`

**Structure**:

```php
[
    // Summary statistics
    'total_queues' => 5,
    'total_jobs_processed' => 123456,
    'total_jobs_failed' => 234,
    'total_active_workers' => 12,
    'health_score' => 87.5,

    // Detailed breakdowns
    'queues' => [
        'redis:default' => [...],
        'redis:high-priority' => [...],
    ],

    'jobs' => [
        'App\\Jobs\\ProcessOrder' => [...],
        'App\\Jobs\\SendEmail' => [...],
    ],

    'workers' => [
        'worker-1' => [...],
        'worker-2' => [...],
    ],

    'servers' => [
        'web-1' => [...],
        'web-2' => [...],
    ],

    'metadata' => [
        'generated_at' => '2024-01-15T10:30:00Z',
        'package_version' => '1.0.0',
    ],
]
```

**Example - Dashboard API**:

```php
Route::get('/api/metrics/dashboard', function () {
    $overview = QueueMetrics::getOverview();

    return response()->json([
        'summary' => [
            'health_score' => $overview['health_score'],
            'total_queues' => $overview['total_queues'],
            'active_workers' => $overview['total_active_workers'],
            'jobs_processed_24h' => $overview['total_jobs_processed'],
        ],
        'queues' => $overview['queues'],
        'top_jobs' => array_slice($overview['jobs'], 0, 10),
    ]);
});
```

### healthCheck()

Perform a comprehensive health check.

```php
$health = QueueMetrics::healthCheck();
```

**Returns**: `array<string, mixed>`

**Structure**:

```php
[
    'status' => 'healthy',  // 'healthy'|'warning'|'critical'
    'score' => 87.5,        // 0-100
    'checks' => [
        'queue_depth' => ['status' => 'ok', 'details' => [...]],
        'failure_rate' => ['status' => 'warning', 'details' => [...]],
        'worker_health' => ['status' => 'ok', 'details' => [...]],
    ],
]
```

## Best Practices

### Caching Expensive Queries

```php
// Cache overview for 60 seconds
$overview = Cache::remember('queue_metrics:overview', 60, function () {
    return QueueMetrics::getOverview();
});
```

### Error Handling

```php
try {
    $metrics = QueueMetrics::getJobMetrics(ProcessOrder::class);
} catch (\Exception $e) {
    Log::error('Failed to fetch metrics', [
        'job' => ProcessOrder::class,
        'error' => $e->getMessage(),
    ]);

    // Fallback to default values
    $metrics = null;
}
```

### Selective Data Access

```php
// Only fetch what you need
$metrics = QueueMetrics::getJobMetrics(ProcessOrder::class);

// Extract specific data
$summary = [
    'processed' => $metrics->totalProcessed,
    'failed' => $metrics->totalFailed,
    'avg_duration' => $metrics->duration->average,
];
```

### Type Safety

```php
use Cbox\LaravelQueueMetrics\DataTransferObjects\JobMetricsData;

function analyzeJob(string $jobClass): array
{
    $metrics = QueueMetrics::getJobMetrics($jobClass);

    // IDE autocomplete works perfectly
    return [
        'performance' => $metrics->duration->average,
        'reliability' => 100 - $metrics->failureRate,
        'throughput' => $metrics->throughput->perMinute,
    ];
}
```

## Next Steps

- See [HTTP API](api-endpoints) for REST endpoints
- Learn about [Events](../advanced-usage/events) for reacting to metrics
