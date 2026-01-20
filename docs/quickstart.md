---
title: "Quick Start"
description: "Get up and running with Laravel Queue Metrics in 5 minutes"
weight: 3
---

# Quick Start

Get up and running with Laravel Queue Metrics in 5 minutes.

## Prerequisites

✅ Laravel 11.0+ or 12.0+ installed
✅ Redis or database configured
✅ Queue worker running

## Install Package

```bash
composer require cboxdk/laravel-queue-metrics
```

That's it! The package auto-registers and starts collecting metrics immediately.

## View Metrics

### Via HTTP API

```bash
# System overview
curl http://your-app.test/queue-metrics/overview

# Specific job metrics
curl http://your-app.test/queue-metrics/jobs/App\\Jobs\\ProcessOrder

# Queue health
curl http://your-app.test/queue-metrics/queues/default

# Active workers
curl http://your-app.test/queue-metrics/workers
```

### Via Facade

```php
use Cbox\LaravelQueueMetrics\Facades\QueueMetrics;

// Get job metrics
$metrics = QueueMetrics::getJobMetrics(\App\Jobs\ProcessOrder::class);

echo "Total processed: {$metrics->totalProcessed}\n";
echo "Average duration: {$metrics->duration->average}ms\n";
echo "Failure rate: {$metrics->failureRate}%\n";

// Get queue health
$queue = QueueMetrics::getQueueMetrics('redis', 'default');

echo "Queue depth: {$queue->depth->total}\n";
echo "Health score: {$queue->health->score}/100\n";
echo "Status: {$queue->health->status}\n";

// Get active workers
$workers = QueueMetrics::getActiveWorkers();

echo "Active workers: {$workers->count()}\n";
```

### Via Prometheus

Configure Prometheus to scrape metrics:

```yaml
# prometheus.yml
scrape_configs:
  - job_name: 'laravel-queues'
    static_configs:
      - targets: ['your-app.test']
    metrics_path: '/queue-metrics/prometheus'
    scrape_interval: 30s
```

## Common Tasks

### Monitor Job Performance

```php
use Cbox\LaravelQueueMetrics\Facades\QueueMetrics;

$metrics = QueueMetrics::getJobMetrics(\App\Jobs\ProcessOrder::class);

// Check if job is slow
if ($metrics->duration->p95 > 5000) { // P95 > 5 seconds
    alert("ProcessOrder is slow: {$metrics->duration->p95}ms");
}

// Check failure rate
if ($metrics->failureRate > 5) { // > 5% failures
    alert("ProcessOrder failing: {$metrics->failureRate}%");
}

// Check throughput
if ($metrics->throughput->perMinute < 10) { // < 10 jobs/min
    alert("ProcessOrder throughput low: {$metrics->throughput->perMinute} jobs/min");
}
```

### React to Events

Register listeners in `EventServiceProvider`:

```php
use Cbox\LaravelQueueMetrics\Events\HealthScoreChanged;

protected $listen = [
    HealthScoreChanged::class => [
        SendHealthAlert::class,
    ],
];
```

```php
// app/Listeners/SendHealthAlert.php
namespace App\Listeners;

use Cbox\LaravelQueueMetrics\Events\HealthScoreChanged;

class SendHealthAlert
{
    public function handle(HealthScoreChanged $event): void
    {
        if ($event->toStatus === 'critical') {
            Slack::send("🚨 Queue health critical: {$event->newScore}/100");
        }
    }
}
```

### Enrich Metrics with Hooks

Add custom data to all metrics in `AppServiceProvider`:

```php
use Cbox\LaravelQueueMetrics\Facades\QueueMetrics;

public function boot(): void
{
    QueueMetrics::hook('before_record', function (array $data) {
        $data['environment'] = app()->environment();
        $data['server'] = gethostname();

        return $data;
    });
}
```

### Schedule Maintenance Commands

In `app/Console/Kernel.php`:

```php
protected function schedule(Schedule $schedule)
{
    // Record trends for historical analysis
    $schedule->command('queue-metrics:trends:record')
        ->everyFiveMinutes();

    // Detect stale workers
    $schedule->command('queue-metrics:workers:detect-stale')
        ->everyMinute();

    // Calculate performance baselines
    $schedule->command('queue-metrics:baseline:calculate')
        ->daily();
}
```

## Real-World Examples

### Dashboard Endpoint

```php
// routes/web.php
Route::get('/dashboard/queues', function () {
    $overview = QueueMetrics::getOverview();

    return view('dashboard.queues', [
        'health_score' => $overview['health_score'],
        'total_queues' => $overview['total_queues'],
        'active_workers' => $overview['total_active_workers'],
        'jobs_processed' => $overview['total_jobs_processed'],
        'queues' => $overview['queues'],
    ]);
});
```

### Slack Notifications

```php
use Cbox\LaravelQueueMetrics\Events\QueueDepthThresholdExceeded;

Event::listen(QueueDepthThresholdExceeded::class, function ($event) {
    Slack::send(
        channel: '#ops-alerts',
        message: "⚠️ Queue {$event->queue} depth: {$event->currentDepth} (threshold: {$event->threshold})"
    );
});
```

### Auto-Scaling

```php
use Cbox\LaravelQueueMetrics\Events\WorkerEfficiencyChanged;

Event::listen(WorkerEfficiencyChanged::class, function ($event) {
    $recommendation = $event->getScalingRecommendation();

    if ($recommendation === 'scale_up') {
        // Trigger AWS Auto Scaling, Kubernetes HPA, etc.
        app(AutoScaler::class)->scaleUp(
            current: $event->activeWorkers,
            target: $event->activeWorkers + 2
        );
    }
});
```

### Multi-Tenancy

```php
QueueMetrics::hook('before_record', function (array $data) {
    $data['tenant_id'] = tenant('id');
    $data['tenant_plan'] = tenant('plan');

    return $data;
});
```

## Configuration

### Customize Storage

**Redis (default):**

```env
QUEUE_METRICS_STORAGE=redis
QUEUE_METRICS_CONNECTION=default
```

**Database:**

```bash
php artisan vendor:publish --tag="laravel-queue-metrics-migrations"
php artisan migrate
```

```env
QUEUE_METRICS_STORAGE=database
```

### Adjust TTL

Publish config and modify:

```bash
php artisan vendor:publish --tag="laravel-queue-metrics-config"
```

```php
// config/queue-metrics.php
'storage' => [
    'ttl' => [
        'raw' => 1800,        // 30 minutes (default: 1 hour)
        'aggregated' => 86400, // 1 day (default: 7 days)
        'baseline' => 2592000, // 30 days
    ],
],
```

### Secure API

```php
// config/queue-metrics.php
'api' => [
    'enabled' => true,
    'middleware' => ['api', 'auth:sanctum'], // Add auth
],
```

## Testing

### Dispatch Test Jobs

```php
php artisan tinker
```

```php
// Dispatch multiple test jobs
for ($i = 0; $i < 10; $i++) {
    dispatch(new \App\Jobs\TestJob());
}

// Wait for processing
sleep(5);

// Check metrics
$metrics = QueueMetrics::getJobMetrics(\App\Jobs\TestJob::class);
dump($metrics);
```

### Verify Storage

**Redis:**

```bash
redis-cli
> KEYS queue_metrics:*
> GET queue_metrics:jobs:redis:default:App\\Jobs\\TestJob
```

**Database:**

```bash
php artisan tinker
```

```php
DB::table('queue_job_metrics')->get();
```

## Performance Tips

1. **Use Redis** for production (10-100x faster than database)
2. **Enable caching** for dashboard queries
3. **Use queued listeners** for event handlers
4. **Adjust TTLs** based on your needs
5. **Monitor overhead** (~1-2ms per job)

## Troubleshooting

### No metrics appearing?

1. Verify queue worker is running: `php artisan queue:work`
2. Check package is enabled: `config('queue-metrics.enabled')`
3. Dispatch a test job and check storage

### API returns 404?

1. Clear route cache: `php artisan route:clear`
2. Verify API is enabled: `config('queue-metrics.api.enabled')`

### High memory usage?

1. Reduce TTL values in config
2. Run cleanup: `php artisan queue-metrics:cleanup --days=3`
3. Switch to Redis if using database

## Next Steps

📚 **Learn More:**
- [Events](advanced-usage/events) - React to metrics changes
- [Facade API](basic-usage/facade-api) - Complete API reference
- [Configuration](configuration-reference) - All config options

🏗️ **Advanced:**
- [Architecture](advanced-usage/architecture) - How it works
- [Performance Tuning](advanced-usage/performance) - Optimize for scale
