---
title: "Events System"
description: "React to queue metrics changes and lifecycle events in your application"
weight: 32
---

# Events System

Laravel Queue Metrics dispatches domain events that allow you to react to significant occurrences in your queue system. Events are notifications that enable integrations, alerts, and side-effects.

## Overview

Events follow Laravel's event system and are perfect for:
- Sending notifications (Slack, email, SMS)
- Triggering auto-scaling decisions
- Logging to external systems
- Updating dashboards in real-time
- Integrating with monitoring tools

### Events vs Hooks

| Feature | Events | Hooks |
|---------|--------|-------|
| **Purpose** | React to completed actions | Transform/mutate data |
| **Timing** | After recording | Before/during recording |
| **Can modify data** | ❌ No | ✅ Yes |
| **Pattern** | Observer | Pipeline |
| **Execution** | Async listeners | Synchronous chain |
| **Use case** | Notifications, side-effects | Data enrichment, filtering |

## Available Events

### MetricsRecorded

**Dispatched**: After job metrics are recorded
**Frequency**: High (every job completion)
**Purpose**: Real-time monitoring and alerting

```php
use Cbox\LaravelQueueMetrics\Events\MetricsRecorded;

Event::listen(MetricsRecorded::class, function (MetricsRecorded $event) {
    $metrics = $event->metrics; // JobMetricsData DTO

    // Access job information
    $jobClass = $metrics->jobClass;
    $totalProcessed = $metrics->totalProcessed;
    $failureRate = $metrics->failureRate;

    // Performance metrics
    $avgDuration = $metrics->duration->average;
    $p95Duration = $metrics->duration->p95;

    // Alert on anomalies
    if ($metrics->failureRate > 10) {
        Slack::send("⚠️ High failure rate for {$jobClass}: {$failureRate}%");
    }
});
```

**Available data:**

```php
$event->metrics->jobClass;          // string
$event->metrics->connection;        // string
$event->metrics->queue;             // string
$event->metrics->totalProcessed;    // int
$event->metrics->totalFailed;       // int
$event->metrics->failureRate;       // float (percentage)
$event->metrics->duration;          // DurationStatsDTO
$event->metrics->memory;            // MemoryStatsDTO
$event->metrics->throughput;        // ThroughputStatsDTO
$event->metrics->lastFailure;       // ?FailureInfoDTO
```

### WorkerEfficiencyChanged

**Dispatched**: When worker efficiency changes significantly
**Frequency**: Low (periodic checks)
**Purpose**: Auto-scaling and capacity planning

```php
use Cbox\LaravelQueueMetrics\Events\WorkerEfficiencyChanged;

Event::listen(WorkerEfficiencyChanged::class, function (WorkerEfficiencyChanged $event) {
    $recommendation = $event->getScalingRecommendation();

    match ($recommendation) {
        'scale_up' => $this->scaleUp($event),
        'scale_down' => $this->scaleDown($event),
        'maintain' => logger('Worker capacity optimal'),
    };
});

private function scaleUp(WorkerEfficiencyChanged $event): void
{
    logger('Scaling up workers', [
        'current_efficiency' => $event->currentEfficiency,
        'active_workers' => $event->activeWorkers,
    ]);

    // Trigger AWS Auto Scaling, Kubernetes HPA, etc.
    $this->cloudProvider->increaseWorkerCount(
        current: $event->activeWorkers,
        target: $event->activeWorkers + 2
    );
}
```

**Available data:**

```php
$event->currentEfficiency;     // float (0-100%)
$event->previousEfficiency;    // float (0-100%)
$event->changePercentage;      // float
$event->activeWorkers;         // int
$event->idleWorkers;          // int
$event->getScalingRecommendation(); // 'scale_up'|'scale_down'|'maintain'
```

**Scaling recommendations:**
- `scale_up`: Efficiency >90% and no idle workers
- `scale_down`: Efficiency <50% and >30% workers are idle
- `maintain`: Capacity is appropriate

### HealthScoreChanged

**Dispatched**: When queue health score changes significantly
**Frequency**: Medium (every few minutes)
**Purpose**: System health monitoring and alerts

```php
use Cbox\LaravelQueueMetrics\Events\HealthScoreChanged;

Event::listen(HealthScoreChanged::class, function (HealthScoreChanged $event) {
    // Detect status transitions
    if ($event->fromStatus === 'healthy' && $event->toStatus === 'warning') {
        Slack::send("⚠️ Queue health degrading: {$event->newScore}/100");
    }

    if ($event->toStatus === 'critical') {
        PagerDuty::alert("🚨 CRITICAL: Queue health: {$event->newScore}/100");
    }

    // Log for trend analysis
    Log::info('Health score changed', [
        'old_score' => $event->oldScore,
        'new_score' => $event->newScore,
        'severity' => $event->severity,
        'from_status' => $event->fromStatus,
        'to_status' => $event->toStatus,
    ]);
});
```

**Available data:**

```php
$event->oldScore;      // float (0-100)
$event->newScore;      // float (0-100)
$event->fromStatus;    // 'healthy'|'warning'|'critical'
$event->toStatus;      // 'healthy'|'warning'|'critical'
$event->severity;      // 'normal'|'info'|'warning'|'critical'
```

**Health status thresholds:**
- `healthy`: Score ≥ 80
- `warning`: Score 50-79
- `critical`: Score < 50

### BaselineRecalculated

**Dispatched**: After baseline calculation completes
**Frequency**: Low (daily or on-demand)
**Purpose**: Performance regression detection

```php
use Cbox\LaravelQueueMetrics\Events\BaselineRecalculated;

Event::listen(BaselineRecalculated::class, function (BaselineRecalculated $event) {
    // Check if baseline changed significantly
    if ($event->isSignificantChange()) {
        Log::warning('Baseline shifted significantly', [
            'connection' => $event->connection,
            'queue' => $event->queue,
            'job_class' => $event->jobClass,
            'old_duration' => $event->oldBaseline?->avgDurationMs,
            'new_duration' => $event->newBaseline->avgDurationMs,
        ]);

        // Alert if performance degraded
        if ($event->newBaseline->avgDurationMs > ($event->oldBaseline?->avgDurationMs ?? 0) * 1.5) {
            Slack::send("⚠️ Performance degradation detected for {$event->jobClass}");
        }
    }
});
```

**Available data:**

```php
$event->connection;            // string
$event->queue;                 // string
$event->jobClass;              // ?string (null for aggregated)
$event->oldBaseline;           // ?BaselineDataDTO
$event->newBaseline;           // BaselineDataDTO
$event->isSignificantChange(); // bool (>20% change)
```

### QueueDepthThresholdExceeded

**Dispatched**: When queue depth exceeds configured threshold
**Frequency**: Medium (when depth grows)
**Purpose**: Capacity alerts and scaling triggers

```php
use Cbox\LaravelQueueMetrics\Events\QueueDepthThresholdExceeded;

Event::listen(QueueDepthThresholdExceeded::class, function (QueueDepthThresholdExceeded $event) {
    // Calculate severity
    $percentOver = $event->getPercentageOverThreshold();

    if ($percentOver > 100) { // More than 2x threshold
        PagerDuty::alert("🚨 Queue depth critical: {$event->currentDepth} jobs");
    } else {
        Slack::send("⚠️ Queue depth high: {$event->currentDepth} jobs (threshold: {$event->threshold})");
    }

    // Provide context for scaling decisions
    Log::info('Queue depth exceeded', [
        'current_depth' => $event->currentDepth,
        'threshold' => $event->threshold,
        'oldest_job_age' => $event->oldestJobAgeSeconds,
        'active_workers' => $event->activeWorkerCount,
        'processing_rate' => $event->processingRate,
    ]);

    // Trigger auto-scaling
    if ($event->oldestJobAgeSeconds > 300) { // 5 minutes
        $this->autoScaler->scaleUp([
            'reason' => 'queue_depth_exceeded',
            'current_depth' => $event->currentDepth,
        ]);
    }
});
```

**Available data:**

```php
$event->connection;             // string
$event->queue;                  // string
$event->currentDepth;           // int
$event->threshold;              // int
$event->oldestJobAgeSeconds;    // ?int
$event->activeWorkerCount;      // int
$event->processingRate;         // float (jobs/minute)
$event->getPercentageOverThreshold(); // float
```

## Registering Event Listeners

### In EventServiceProvider

Register listeners in `app/Providers/EventServiceProvider.php`:

```php
use Cbox\LaravelQueueMetrics\Events\{
    MetricsRecorded,
    WorkerEfficiencyChanged,
    HealthScoreChanged,
    BaselineRecalculated,
    QueueDepthThresholdExceeded,
};

protected $listen = [
    MetricsRecorded::class => [
        SendMetricsToDatadog::class,
        UpdateRealtimeDashboard::class,
    ],

    WorkerEfficiencyChanged::class => [
        TriggerAutoScaling::class,
    ],

    HealthScoreChanged::class => [
        SendHealthAlert::class,
        LogHealthChange::class,
    ],

    BaselineRecalculated::class => [
        CheckPerformanceRegression::class,
    ],

    QueueDepthThresholdExceeded::class => [
        ScaleUpWorkers::class,
        AlertOpsTeam::class,
    ],
];
```

### Using Closures

For simple logic, use closure-based listeners:

```php
// In AppServiceProvider::boot()

Event::listen(MetricsRecorded::class, function (MetricsRecorded $event) {
    // Simple inline logic
    Log::channel('metrics')->info('Job completed', [
        'job' => $event->metrics->jobClass,
        'duration' => $event->metrics->duration->average,
    ]);
});
```

### Using Queued Listeners

Make listeners asynchronous for non-critical processing:

```php
namespace App\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Cbox\LaravelQueueMetrics\Events\MetricsRecorded;

class SendMetricsToDatadog implements ShouldQueue
{
    public $queue = 'metrics';
    public $tries = 3;

    public function handle(MetricsRecorded $event): void
    {
        Datadog::gauge('queue.job.duration', $event->metrics->duration->average, [
            'job_class' => $event->metrics->jobClass,
            'queue' => $event->metrics->queue,
        ]);
    }
}
```

## Common Use Cases

### Slack Notifications

```php
use Cbox\LaravelQueueMetrics\Events\HealthScoreChanged;

Event::listen(HealthScoreChanged::class, function (HealthScoreChanged $event) {
    if ($event->severity === 'critical') {
        Slack::send(
            channel: '#ops-alerts',
            message: "🚨 Queue health critical: {$event->newScore}/100\n" .
                    "Status: {$event->fromStatus} → {$event->toStatus}"
        );
    }
});
```

### PagerDuty Integration

```php
use Cbox\LaravelQueueMetrics\Events\QueueDepthThresholdExceeded;

Event::listen(QueueDepthThresholdExceeded::class, function ($event) {
    if ($event->getPercentageOverThreshold() > 100) {
        PagerDuty::trigger([
            'summary' => "Queue depth critical: {$event->currentDepth} jobs",
            'severity' => 'critical',
            'source' => $event->connection . ':' . $event->queue,
            'custom_details' => [
                'depth' => $event->currentDepth,
                'threshold' => $event->threshold,
                'oldest_job_age' => $event->oldestJobAgeSeconds,
                'workers' => $event->activeWorkerCount,
            ],
        ]);
    }
});
```

### Datadog Metrics

```php
use Cbox\LaravelQueueMetrics\Events\MetricsRecorded;

Event::listen(MetricsRecorded::class, function (MetricsRecorded $event) {
    $tags = [
        'job_class' => $event->metrics->jobClass,
        'queue' => $event->metrics->queue,
        'connection' => $event->metrics->connection,
    ];

    Datadog::gauge('queue.job.duration.avg', $event->metrics->duration->average, $tags);
    Datadog::gauge('queue.job.duration.p95', $event->metrics->duration->p95, $tags);
    Datadog::gauge('queue.job.memory.avg', $event->metrics->memory->average, $tags);
    Datadog::gauge('queue.job.failure_rate', $event->metrics->failureRate, $tags);
});
```

### AWS CloudWatch

```php
use Cbox\LaravelQueueMetrics\Events\WorkerEfficiencyChanged;
use Aws\CloudWatch\CloudWatchClient;

Event::listen(WorkerEfficiencyChanged::class, function ($event) {
    $cloudWatch = new CloudWatchClient([/*config*/]);

    $cloudWatch->putMetricData([
        'Namespace' => 'QueueMetrics',
        'MetricData' => [
            [
                'MetricName' => 'WorkerEfficiency',
                'Value' => $event->currentEfficiency,
                'Unit' => 'Percent',
            ],
            [
                'MetricName' => 'ActiveWorkers',
                'Value' => $event->activeWorkers,
                'Unit' => 'Count',
            ],
        ],
    ]);
});
```

### Auto-Scaling with Kubernetes

```php
use Cbox\LaravelQueueMetrics\Events\WorkerEfficiencyChanged;
use Kubernetes\Client;

Event::listen(WorkerEfficiencyChanged::class, function ($event) {
    $recommendation = $event->getScalingRecommendation();

    if ($recommendation === 'scale_up') {
        $k8s = new Client();
        $deployment = $k8s->deployments()->find('laravel-queue-worker');

        $deployment->scale($event->activeWorkers + 2);
    }
});
```

### Custom Dashboard Updates

```php
use Cbox\LaravelQueueMetrics\Events\MetricsRecorded;

Event::listen(MetricsRecorded::class, function (MetricsRecorded $event) {
    // Broadcast to websocket for real-time dashboard
    broadcast(new DashboardMetricsUpdated([
        'job_class' => $event->metrics->jobClass,
        'duration' => $event->metrics->duration->average,
        'throughput' => $event->metrics->throughput->perMinute,
        'failure_rate' => $event->metrics->failureRate,
    ]))->toOthers();
});
```

### Anomaly Detection

```php
use Cbox\LaravelQueueMetrics\Events\MetricsRecorded;

Event::listen(MetricsRecorded::class, function (MetricsRecorded $event) {
    $metrics = $event->metrics;

    // Detect slow jobs (>2x P95)
    if ($metrics->duration->average > $metrics->duration->p95 * 2) {
        Slack::send("⚠️ Anomaly: {$metrics->jobClass} running {$metrics->duration->average}ms (P95: {$metrics->duration->p95}ms)");
    }

    // Detect memory spikes (>150% average)
    if ($metrics->memory->peak > $metrics->memory->average * 1.5) {
        Log::warning('Memory spike detected', [
            'job' => $metrics->jobClass,
            'average' => $metrics->memory->average,
            'peak' => $metrics->memory->peak,
        ]);
    }
});
```

## Performance Considerations

### High-Frequency Events

`MetricsRecorded` fires for every job completion. For high-throughput queues:

1. **Use queued listeners** to avoid blocking job processing
2. **Batch operations** instead of one-at-a-time processing
3. **Sample data** if full tracking isn't needed
4. **Use Redis caching** for aggregations

```php
class SendMetricsToDatadog implements ShouldQueue
{
    public $queue = 'metrics'; // Separate queue
    public $tries = 1; // Don't retry failed metric sends

    public function handle(MetricsRecorded $event): void
    {
        // Process asynchronously
    }
}
```

### Sampling High-Volume Events

```php
Event::listen(MetricsRecorded::class, function (MetricsRecorded $event) {
    // Sample 10% of events
    if (rand(1, 100) <= 10) {
        $this->sendToExternalMonitoring($event);
    }
});
```

### Batching Operations

```php
class BatchMetricsToDatadog implements ShouldQueue
{
    private array $buffer = [];

    public function handle(MetricsRecorded $event): void
    {
        $this->buffer[] = $event;

        // Flush every 100 metrics
        if (count($this->buffer) >= 100) {
            Datadog::sendBatch($this->buffer);
            $this->buffer = [];
        }
    }
}
```

## Testing Events

### Testing Event Dispatch

```php
use Cbox\LaravelQueueMetrics\Events\HealthScoreChanged;

test('health score change dispatches event', function () {
    Event::fake();

    // Trigger health change
    // ... your test code ...

    Event::assertDispatched(HealthScoreChanged::class, function ($event) {
        return $event->toStatus === 'critical';
    });
});
```

### Testing Listeners

```php
use App\Listeners\SendHealthAlert;
use Cbox\LaravelQueueMetrics\Events\HealthScoreChanged;

test('alert is sent when health becomes critical', function () {
    Slack::fake();

    $event = new HealthScoreChanged(
        oldScore: 85,
        newScore: 45,
        fromStatus: 'healthy',
        toStatus: 'critical',
    );

    $listener = new SendHealthAlert();
    $listener->handle($event);

    Slack::assertSent(fn ($message) =>
        str_contains($message, 'critical')
    );
});
```

## Debugging Events

Enable event logging to debug listener execution:

```php
// In AppServiceProvider::boot()

Event::listen('*', function ($eventName, $data) {
    if (str_starts_with($eventName, 'Cbox\\LaravelQueueMetrics\\Events')) {
        Log::debug('Queue metrics event', [
            'event' => class_basename($eventName),
            'data' => $data,
        ]);
    }
});
```

## Next Steps

- See [API Documentation](../basic-usage/api-endpoints.md) for querying metrics
