# Laravel Queue Metrics

[![Latest Version on Packagist](https://img.shields.io/packagist/v/gophpeek/laravel-queue-metrics.svg?style=flat-square)](https://packagist.org/packages/gophpeek/laravel-queue-metrics)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/gophpeek/laravel-queue-metrics/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/gophpeek/laravel-queue-metrics/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/gophpeek/laravel-queue-metrics/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/gophpeek/laravel-queue-metrics/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/gophpeek/laravel-queue-metrics.svg?style=flat-square)](https://packagist.org/packages/gophpeek/laravel-queue-metrics)

**Comprehensive queue monitoring and metrics collection for Laravel applications with real-time insights, trend analysis, and Prometheus export.**

Laravel Queue Metrics provides deep observability into your Laravel queue system, tracking job execution, worker performance, queue health, and server resources. Built with production workloads in mind, it offers flexible storage backends (Redis/Database), minimal overhead, and powerful analytics capabilities.

## Features

- **Job Metrics**: Track execution time, memory usage, CPU time, throughput, and failure rates per job class
- **Queue Health**: Monitor queue depth, processing rates, failure rates, and health scores
- **Worker Monitoring**: Real-time worker status, resource consumption, and efficiency metrics
- **Server Metrics**: CPU, memory, disk, and network utilization via [gophpeek/system-metrics](https://github.com/gophpeek/system-metrics)
- **Trend Analysis**: Historical analysis with linear regression, forecasting, and anomaly detection
- **Baseline Comparison**: Automatic baseline calculation to detect performance degradation
- **Flexible Storage**: Redis (fast, in-memory) or Database (persistent) backends
- **Prometheus Export**: Native Prometheus metrics endpoint for monitoring dashboards
- **RESTful API**: Complete HTTP API for integration with custom dashboards
- **Zero Configuration**: Works out-of-the-box with sensible defaults

## Requirements

- PHP 8.2+
- Laravel 11.0+
- Redis or Database for metrics storage
- (Optional) [gophpeek/system-metrics](https://github.com/gophpeek/system-metrics) for server resource monitoring

## Installation

Install the package via composer:

```bash
composer require gophpeek/laravel-queue-metrics
```

### Optional: Database Storage

If using database storage, publish and run migrations:

```bash
php artisan vendor:publish --tag="laravel-queue-metrics-migrations"
php artisan migrate
```

### Optional: Configuration

Publish the config file to customize behavior:

```bash
php artisan vendor:publish --tag="laravel-queue-metrics-config"
```

## Quick Start

The package works automatically once installed. Job metrics are collected via event listeners that hook into Laravel's queue events.

### View Metrics via API

```bash
# Queue overview
curl http://your-app.test/queue-metrics/overview

# Specific job metrics
curl http://your-app.test/queue-metrics/jobs/App\\Jobs\\ProcessOrder

# Queue depth and health
curl http://your-app.test/queue-metrics/queues/default

# Active workers
curl http://your-app.test/queue-metrics/workers

# Server health
curl http://your-app.test/queue-metrics/server/health
```

### Prometheus Integration

Configure Prometheus to scrape metrics:

```yaml
scrape_configs:
  - job_name: 'laravel-queues'
    static_configs:
      - targets: ['your-app.test']
    metrics_path: '/queue-metrics/prometheus'
    scrape_interval: 30s
```

### Programmatic Access

Use the `QueueMetrics` facade in your application:

```php
use PHPeek\LaravelQueueMetrics\Facades\QueueMetrics;

// Get job metrics
$metrics = QueueMetrics::getJobMetrics(
    jobClass: \App\Jobs\ProcessOrder::class,
    connection: 'redis',
    queue: 'default'
);

echo "Total processed: {$metrics->totalProcessed}";
echo "Average duration: {$metrics->duration->average}ms";
echo "Failure rate: {$metrics->failureRate}%";

// Get queue health
$queue = QueueMetrics::getQueueMetrics(
    connection: 'redis',
    queue: 'default'
);

echo "Queue depth: {$queue->depth}";
echo "Health score: {$queue->health->score}/100";

// Get active workers
$workers = QueueMetrics::getActiveWorkers(
    connection: 'redis',
    queue: 'default'
);

foreach ($workers as $worker) {
    echo "Worker {$worker->pid}: {$worker->jobsProcessed} jobs, {$worker->memoryMb}MB";
}

// Get system overview
$overview = QueueMetrics::getOverview();
echo "Total queues: {$overview['total_queues']}";
echo "Health score: {$overview['health_score']}";
```

## Configuration

The package includes extensive configuration options in `config/queue-metrics.php`:

### Storage Configuration

```php
'storage' => [
    'driver' => env('QUEUE_METRICS_STORAGE', 'redis'), // 'redis', 'database', or 'null'
    'connection' => env('QUEUE_METRICS_CONNECTION', 'default'),
    'prefix' => 'queue_metrics',

    'ttl' => [
        'raw' => 3600,        // 1 hour - raw execution data
        'aggregated' => 604800, // 7 days - calculated metrics
        'baseline' => 2592000,  // 30 days - baseline calculations
    ],
],
```

**Redis (Recommended)**: Fast, low-latency, automatic TTL cleanup. Ideal for production.

**Database**: Persistent storage, queryable with SQL, better for long-term retention.

**Null**: Disables storage, useful for testing or staging environments.

### Time Windows

Configure rolling windows for metrics aggregation:

```php
'windows' => [
    'short' => [60, 300, 900],    // 1min, 5min, 15min
    'medium' => [3600],            // 1 hour
    'long' => [86400],             // 24 hours
],
```

### API Configuration

```php
'api' => [
    'enabled' => true,
    'prefix' => 'queue-metrics',
    'middleware' => ['api'], // Add 'auth:sanctum' for authentication
],
```

### Worker Heartbeat

```php
'worker_heartbeat' => [
    'stale_threshold' => 60, // Seconds before worker marked as stale
    'auto_detect_schedule' => '* * * * *', // Cron for stale detection
],
```

### Performance Tuning

```php
'performance' => [
    'batch_size' => 100,              // Bulk operation batch size
    'percentile_samples' => 1000,     // Samples for P50/P95/P99
    'baseline_samples' => 100,        // Samples for baseline calculation
],
```

## API Endpoints

### Overview

```http
GET /queue-metrics/overview
```

Returns system-wide statistics: total queues, jobs processed/failed, active workers, overall health score.

### Job Metrics

```http
GET /queue-metrics/jobs/{jobClass}?connection=default&queue=default
```

Detailed metrics for a specific job class:
- Total processed, failed, queued
- Duration statistics (avg, min, max, P50, P95, P99)
- Memory usage (avg, min, max, P95)
- CPU time statistics
- Throughput (jobs/minute, jobs/hour)
- Failure rate and last exception
- Trend analysis (direction, slope, confidence)
- Baseline comparison (deviation percentage)

### Queue Metrics

```http
GET /queue-metrics/queues/{queue}?connection=default
```

Queue health and activity:
- Current depth and oldest job age
- Processing rate and throughput
- Failure rate and health score
- Active worker count
- Trend analysis and forecasting

### Worker Stats

```http
GET /queue-metrics/workers?connection=default&queue=default
```

Active worker information:
- PID, status, current job
- Jobs processed, failed
- Memory and CPU usage
- Last heartbeat timestamp

### Baseline Operations

```http
POST /queue-metrics/baselines/calculate?connection=default&queue=default
```

Manually trigger baseline calculation for performance comparison.

```http
GET /queue-metrics/baselines/{connection}/{queue}
```

Retrieve calculated baseline metrics.

### Server Metrics

```http
GET /queue-metrics/server/metrics
```

Current server resource utilization (requires gophpeek/system-metrics).

```http
GET /queue-metrics/server/health
```

Server health assessment with issue detection.

### Prometheus Export

```http
GET /queue-metrics/prometheus
```

Prometheus-formatted metrics for all queues, jobs, and workers.

## Artisan Commands

### Calculate Baselines

```bash
php artisan queue-metrics:baseline:calculate
```

Calculate performance baselines for all queues. Baselines are used to detect degradation.

### Detect Stale Workers

```bash
php artisan queue-metrics:workers:detect-stale
```

Identify workers that haven't sent heartbeats recently. Typically scheduled to run every minute.

### Record Trend Data

```bash
php artisan queue-metrics:trends:record
```

Capture current metrics for trend analysis. Schedule this command for historical tracking:

```php
// app/Console/Kernel.php
protected function schedule(Schedule $schedule)
{
    $schedule->command('queue-metrics:trends:record')->everyFiveMinutes();
    $schedule->command('queue-metrics:workers:detect-stale')->everyMinute();
    $schedule->command('queue-metrics:baseline:calculate')->daily();
}
```

### Cleanup Old Data

```bash
php artisan queue-metrics:cleanup --days=7
```

Remove metrics older than specified days. Automatic with Redis TTL, useful for database storage.

## Advanced Usage

### Custom Trend Analysis

Analyze queue depth trends with forecasting:

```php
use PHPeek\LaravelQueueMetrics\Services\TrendAnalysisService;

$trendService = app(TrendAnalysisService::class);

$analysis = $trendService->analyzeQueueDepthTrend(
    connection: 'redis',
    queue: 'default',
    periodSeconds: 3600, // Last hour
    intervalSeconds: 60   // 1-minute intervals
);

if ($analysis['available']) {
    echo "Current depth: {$analysis['statistics']['current']}";
    echo "Average: {$analysis['statistics']['average']}";
    echo "Trend: {$analysis['trend']['direction']}";
    echo "Forecast (next interval): {$analysis['forecast']['next_value']}";
}
```

### Worker Efficiency Analysis

```php
$efficiency = $trendService->analyzeWorkerEfficiencyTrend(
    periodSeconds: 3600
);

echo "Average efficiency: {$efficiency['efficiency']['average']}%";
echo "Memory usage: {$efficiency['resource_usage']['avg_memory_mb']}MB";
echo "CPU usage: {$efficiency['resource_usage']['avg_cpu_percent']}%";
```

### Programmatic Baseline Calculation

```php
use PHPeek\LaravelQueueMetrics\Actions\CalculateBaselineAction;

$calculateBaseline = app(CalculateBaselineAction::class);

$baseline = $calculateBaseline->execute(
    connection: 'redis',
    queue: 'default'
);

echo "Baseline duration: {$baseline->avgDurationMs}ms";
echo "Baseline memory: {$baseline->avgMemoryMb}MB";
```

### Direct Repository Access

For advanced use cases, access repositories directly:

```php
use PHPeek\LaravelQueueMetrics\Repositories\Contracts\JobMetricsRepository;

$repository = app(JobMetricsRepository::class);

// Record custom metrics
$repository->recordStart(
    jobId: 'custom-job-123',
    jobClass: \App\Jobs\CustomJob::class,
    connection: 'redis',
    queue: 'default',
    startedAt: now()
);

// Get raw metrics
$metrics = $repository->getMetrics(
    jobClass: \App\Jobs\CustomJob::class,
    connection: 'redis',
    queue: 'default'
);
```

## Architecture

### Storage Drivers

The package uses a **Storage Driver Pattern** for flexibility:

- **RedisStorageDriver**: Fast, automatic TTL, recommended for production
- **DatabaseStorageDriver**: Persistent, queryable, better for auditing
- **NullStorageDriver**: No-op implementation for testing

Switch drivers via configuration without code changes.

### Data Flow

1. **Collection**: Event listeners capture job lifecycle events (processing, processed, failed)
2. **Recording**: Actions transform events into metrics and store via repositories
3. **Aggregation**: Periodic commands calculate trends, baselines, and health scores
4. **Retrieval**: Services provide high-level access, repositories provide low-level access
5. **Export**: Controllers expose metrics via HTTP API and Prometheus format

### Key Components

- **Actions**: Business logic (RecordJobStart, RecordJobCompletion, CalculateBaseline)
- **Repositories**: Data access layer (JobMetrics, QueueMetrics, WorkerMetrics)
- **Services**: High-level operations (MetricsQuery, TrendAnalysis, ServerMetrics)
- **DTOs**: Immutable data transfer objects with type safety
- **Storage Drivers**: Pluggable storage backends
- **Event Listeners**: Automatic metrics collection from Laravel queue events

## Performance Considerations

### Overhead

- **Per-job overhead**: ~1-2ms for metrics recording (non-blocking)
- **Memory overhead**: ~5-10MB for package classes (loaded once)
- **Storage overhead**: ~1-2KB per job execution record (with TTL cleanup)

### Optimization Tips

1. **Use Redis storage** for production (faster than database)
2. **Configure appropriate TTLs** to limit data retention
3. **Adjust batch sizes** for high-throughput queues
4. **Limit trend periods** to reduce calculation time
5. **Cache facade calls** if querying metrics frequently

### Scalability

- **Horizontal scaling**: Works with multiple app servers (centralized Redis storage)
- **High throughput**: Tested with 10,000+ jobs/minute
- **Worker tracking**: Supports 100+ concurrent workers
- **Queue diversity**: Handles multiple connections and queues simultaneously

## Testing

Run the test suite:

```bash
composer test
```

Run static analysis:

```bash
composer analyse
```

Run code style fixes:

```bash
composer format
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Security Vulnerabilities

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## Credits

- [Sylvester Damgaard](https://github.com/sylvesterdamgaard)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
