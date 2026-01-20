---
title: "Performance Tuning"
description: "Optimize Laravel Queue Metrics for high-throughput systems and resource efficiency"
weight: 34
---

# Performance Tuning

Optimize Laravel Queue Metrics for your workload and scale.

## Performance Characteristics

### Per-Job Overhead

**Redis Storage**:
- Event listener: ~0.1ms
- Action execution: ~0.5ms
- Hook pipeline: ~0.1-0.5ms per hook
- Repository storage: ~0.5-1ms
- **Total**: ~1-2ms per job

**Database Storage**:
- Event listener: ~0.1ms
- Action execution: ~0.5ms
- Hook pipeline: ~0.1-0.5ms per hook
- Repository storage: ~5-10ms
- **Total**: ~5-15ms per job

### Memory Overhead

- Package classes: ~5-10MB (loaded once)
- Per-job data: ~1-2KB in storage
- Worker heartbeats: ~0.5KB per worker
- Aggregated metrics: ~5-10KB per job class

### Tested Throughput

- **10,000+ jobs/minute** with Redis storage
- **2,000+ jobs/minute** with Database storage
- **100+ concurrent workers** monitored
- **1,000+ job classes** tracked

## Optimization Strategies

### 1. Storage Driver Selection

**Use Redis for Production**

Redis is 10-100x faster than database storage:

```php
// config/queue-metrics.php
'storage' => [
    'driver' => 'redis',
    'connection' => 'metrics', // Dedicated connection recommended
],
```

**Dedicated Redis Connection**:

```php
// config/database.php
'redis' => [
    'metrics' => [
        'host' => env('REDIS_METRICS_HOST', '127.0.0.1'),
        'password' => env('REDIS_METRICS_PASSWORD', null),
        'port' => env('REDIS_METRICS_PORT', 6379),
        'database' => env('REDIS_METRICS_DB', 2), // Separate DB
        'options' => [
            'prefix' => 'queue_metrics:',
        ],
    ],
],
```

**Why Dedicated Connection?**
- Isolates metrics from application cache
- Prevents cache eviction of metrics data
- Allows independent scaling
- Easier monitoring and debugging

### 2. TTL Configuration

Shorter TTL = Less storage, faster queries:

```php
'storage' => [
    'ttl' => [
        'raw' => 1800,          // 30 min (was 1 hour)
        'aggregated' => 259200, // 3 days (was 7 days)
        'baseline' => 1209600,  // 14 days (was 30 days)
        'workers' => 180,       // 3 min (was 5 min)
        'trends' => 604800,     // 7 days (was 14 days)
    ],
],
```

**TTL Recommendations by Volume**:

```php
// Low volume (<1,000 jobs/day)
'ttl' => ['raw' => 7200, 'aggregated' => 2592000],

// Medium volume (1,000-10,000 jobs/day)
'ttl' => ['raw' => 3600, 'aggregated' => 604800],

// High volume (10,000-100,000 jobs/day)
'ttl' => ['raw' => 1800, 'aggregated' => 259200],

// Very high volume (>100,000 jobs/day)
'ttl' => ['raw' => 900, 'aggregated' => 86400],
```

### 3. Sampling for Scale

For systems processing >50,000 jobs/day, enable sampling:

```php
'sampling' => [
    'enabled' => true,
    'rate' => 0.1, // 10% sampling

    // Always sample critical jobs
    'always_sample' => [
        App\Jobs\ProcessPayment::class,
        App\Jobs\SendInvoice::class,
    ],

    // Never sample low-priority jobs
    'never_sample' => [
        App\Jobs\HealthCheck::class,
        App\Jobs\LogCleanup::class,
    ],
],
```

**Sampling Benefits**:
- 90% reduction in storage with 10% sampling
- 90% reduction in processing overhead
- Still provides accurate statistical insights
- Critical jobs always tracked

### 4. Query Caching

Cache frequently accessed metrics:

```php
'performance' => [
    'cache_ttl' => 30, // Cache for 30 seconds
    'batch_size' => 500,
],
```

**Manual Caching**:

```php
use Illuminate\Support\Facades\Cache;
use Cbox\LaravelQueueMetrics\Facades\QueueMetrics;

// Cache dashboard overview
$overview = Cache::remember('metrics:overview', 60, function () {
    return QueueMetrics::getOverview();
});

// Cache job metrics
$metrics = Cache::remember("metrics:job:{$jobClass}", 30, function () use ($jobClass) {
    return QueueMetrics::getJobMetrics($jobClass);
});
```

### 5. Async Processing

Process hooks and events asynchronously:

```php
'performance' => [
    'async_hooks' => true,   // Queue hook processing
    'queue_events' => true,  // Queue event dispatching
],

'hooks' => [
    'max_execution_time' => 50, // Timeout hooks faster
    'fail_silently' => true,    // Don't block on errors
],
```

**Queued Event Listeners**:

```php
use Illuminate\Contracts\Queue\ShouldQueue;
use Cbox\LaravelQueueMetrics\Events\MetricsRecorded;

class SendMetricsToDatadog implements ShouldQueue
{
    public function handle(MetricsRecorded $event): void
    {
        // Process asynchronously
        Datadog::gauge('queue.duration', $event->metrics->duration->average);
    }
}
```

### 6. Selective Event Dispatching

Disable high-frequency events:

```php
'events' => [
    'dispatch' => [
        'metrics_recorded' => false,            // Very high frequency
        'worker_efficiency_changed' => true,    // Important
        'health_score_changed' => true,         // Important
        'baseline_recalculated' => false,       // Low frequency
        'queue_depth_threshold_exceeded' => true, // Important
    ],
],
```

### 7. Exclusions

Exclude jobs/queues that don't need metrics:

```php
'exclusions' => [
    'jobs' => [
        App\Jobs\HealthCheck::class,
        App\Jobs\CleanupLogs::class,
        App\Jobs\RefreshCache::class,
    ],
    'queues' => [
        'low-priority',
        'maintenance',
    ],
    'connections' => [
        'sync', // Don't track synchronous jobs
    ],
],
```

### 8. Worker Configuration

Optimize worker heartbeat tracking:

```php
'workers' => [
    'heartbeat_interval' => 60,  // Increase from 30s
    'stale_threshold' => 180,    // Increase from 120s
    'track_resources' => false,  // Disable if not needed
],
```

### 9. Batch Operations

Use batch size for bulk operations:

```php
'performance' => [
    'batch_size' => 1000, // Larger batches for bulk ops
],
```

### 10. Disable Features

Turn off unused features:

```php
'trends' => ['enabled' => false],
'baselines' => ['enabled' => false],
'server_metrics' => ['enabled' => false],
'prometheus' => ['enabled' => false],
```

## Performance Monitoring

### 1. Monitor Package Overhead

```php
// In a service provider
use Illuminate\Support\Facades\Event;
use Cbox\LaravelQueueMetrics\Events\MetricsRecorded;

Event::listen(MetricsRecorded::class, function ($event) {
    $overhead = $event->metrics->recordingDuration ?? 0;

    if ($overhead > 10) { // 10ms threshold
        Log::warning("Queue metrics overhead high: {$overhead}ms");
    }
});
```

### 2. Redis Memory Usage

```bash
# Connect to Redis
redis-cli

# Check memory usage
INFO memory

# Check key count
KEYS queue_metrics:* | wc -l

# Check largest keys
MEMORY USAGE queue_metrics:jobs:redis:default:App\\Jobs\\ProcessOrder
```

### 3. Database Storage Size

```sql
-- MySQL
SELECT
    table_name,
    ROUND(((data_length + index_length) / 1024 / 1024), 2) AS size_mb
FROM information_schema.tables
WHERE table_schema = DATABASE()
AND table_name LIKE 'queue_%'
ORDER BY (data_length + index_length) DESC;

-- Check row counts
SELECT
    'queue_job_metrics' AS table_name,
    COUNT(*) AS row_count
FROM queue_job_metrics
UNION ALL
SELECT 'queue_snapshots', COUNT(*) FROM queue_snapshots
UNION ALL
SELECT 'worker_heartbeats', COUNT(*) FROM worker_heartbeats;
```

### 4. Query Performance

```php
// Enable query logging
DB::enableQueryLog();

// Execute metrics query
$metrics = QueueMetrics::getJobMetrics(ProcessOrder::class);

// Check queries
$queries = DB::getQueryLog();
foreach ($queries as $query) {
    Log::info("Query: {$query['query']} - Time: {$query['time']}ms");
}
```

## Performance Benchmarks

### Setup

```php
// Benchmark configuration
$iterations = 10000;
$jobClass = App\Jobs\TestJob::class;

// Dispatch test jobs
$start = microtime(true);

for ($i = 0; $i < $iterations; $i++) {
    dispatch(new TestJob());
}

$duration = (microtime(true) - $start) * 1000;
$perJob = $duration / $iterations;

echo "Total: {$duration}ms\n";
echo "Per job: {$perJob}ms\n";
```

### Baseline Results

**Redis Storage** (10,000 jobs):
- Total time: ~15-20 seconds
- Per job overhead: ~1.5-2ms
- Throughput: ~500-660 jobs/second

**Database Storage** (10,000 jobs):
- Total time: ~60-80 seconds
- Per job overhead: ~6-8ms
- Throughput: ~125-165 jobs/second

**With Sampling (10%)** (10,000 jobs):
- Total time: ~8-10 seconds
- Per job overhead: ~0.8-1ms
- Throughput: ~1000-1250 jobs/second

## Optimization Checklist

### Essential (Do First)

- [ ] Use Redis storage driver
- [ ] Configure dedicated Redis connection
- [ ] Set appropriate TTL values for your volume
- [ ] Exclude health check and maintenance jobs
- [ ] Disable unused features (trends, baselines, server metrics)

### High Impact (Do Next)

- [ ] Enable sampling for >50k jobs/day
- [ ] Cache frequently accessed metrics (30-60s)
- [ ] Disable MetricsRecorded event (high frequency)
- [ ] Increase worker heartbeat interval to 60s
- [ ] Use async hooks and queued events

### Fine Tuning (Optional)

- [ ] Increase batch sizes for bulk operations
- [ ] Exclude low-priority queues
- [ ] Disable resource tracking on workers
- [ ] Lower hook execution timeout
- [ ] Configure aggressive health thresholds

## Troubleshooting Performance Issues

### High Redis Memory Usage

**Symptoms**: Redis memory growing continuously

**Solutions**:
1. Verify TTL is configured and working:
```bash
redis-cli
> TTL queue_metrics:jobs:redis:default:App\\Jobs\\ProcessOrder
```

2. Reduce TTL values
3. Enable sampling
4. Clean up old keys manually:
```bash
redis-cli --scan --pattern "queue_metrics:*" | xargs redis-cli DEL
```

### Slow API Responses

**Symptoms**: API endpoints taking >1 second

**Solutions**:
1. Enable query caching
2. Reduce data points for trends/baselines
3. Add database indexes:
```sql
CREATE INDEX idx_job_class ON queue_job_metrics(job_class, connection, queue);
CREATE INDEX idx_completed_at ON queue_job_metrics(completed_at);
```

4. Use Prometheus for monitoring instead of HTTP API

### High CPU Usage

**Symptoms**: Workers consuming high CPU

**Solutions**:
1. Disable resource tracking on workers
2. Increase heartbeat interval
3. Enable async hooks processing
4. Disable events that aren't needed
5. Use sampling

### Job Processing Slowdown

**Symptoms**: Jobs taking longer after installing package

**Solutions**:
1. Profile overhead with event listener
2. Disable synchronous hook processing
3. Remove slow hooks
4. Enable sampling
5. Verify Redis connection performance

## Production Recommendations

### Small Scale (<10k jobs/day)

```php
'storage' => [
    'driver' => 'redis',
    'ttl' => ['raw' => 3600, 'aggregated' => 604800],
],
'sampling' => ['enabled' => false],
'performance' => ['cache_ttl' => 60],
```

### Medium Scale (10k-100k jobs/day)

```php
'storage' => [
    'driver' => 'redis',
    'connection' => 'metrics',
    'ttl' => ['raw' => 1800, 'aggregated' => 259200],
],
'sampling' => ['enabled' => false],
'performance' => ['cache_ttl' => 30, 'async_hooks' => true],
'events' => ['dispatch' => ['metrics_recorded' => false]],
```

### Large Scale (>100k jobs/day)

```php
'storage' => [
    'driver' => 'redis',
    'connection' => 'metrics',
    'ttl' => ['raw' => 900, 'aggregated' => 86400],
],
'sampling' => ['enabled' => true, 'rate' => 0.1],
'performance' => [
    'cache_ttl' => 30,
    'async_hooks' => true,
    'queue_events' => true,
    'batch_size' => 1000,
],
'events' => ['dispatch' => ['metrics_recorded' => false]],
'workers' => ['heartbeat_interval' => 60, 'track_resources' => false],
```

## Next Steps

- [Configuration Reference](../configuration-reference.md) - All config options
- [Architecture](architecture.md) - Understanding package internals
- [Prometheus](prometheus.md) - Efficient monitoring integration
