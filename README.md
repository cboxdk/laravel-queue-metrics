# Laravel Queue Metrics

[![Latest Version on Packagist](https://img.shields.io/packagist/v/cboxdk/laravel-queue-metrics.svg?style=flat-square)](https://packagist.org/packages/cboxdk/laravel-queue-metrics)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/cboxdk/laravel-queue-metrics/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/cboxdk/laravel-queue-metrics/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/cboxdk/laravel-queue-metrics/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/cboxdk/laravel-queue-metrics/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/cboxdk/laravel-queue-metrics.svg?style=flat-square)](https://packagist.org/packages/cboxdk/laravel-queue-metrics)

**Production-ready queue monitoring and metrics collection for Laravel applications.**

Laravel Queue Metrics provides deep observability into your Laravel queue system with minimal overhead. Track job execution, monitor worker performance, analyze trends, and export to Prometheus—all with zero configuration required.

## Why Laravel Queue Metrics?

- 🚀 **Zero Configuration** - Works out-of-the-box
- ⚡ **Minimal Overhead** - ~1-2ms per job
- 📊 **Rich Insights** - Duration, memory, CPU, throughput, trends
- 🎯 **Production Ready** - Battle-tested at scale
- 🔌 **Extensible** - Events for customization and reactive monitoring
- 📈 **Prometheus Ready** - Native metrics export
- 🏗️ **DX First** - Clean facade API and comprehensive docs

## Quick Example

```php
use Cbox\LaravelQueueMetrics\Facades\QueueMetrics;

// Get job performance metrics
$metrics = QueueMetrics::getJobMetrics(ProcessOrder::class);

echo "Processed: {$metrics->totalProcessed}\n";
echo "P95 Duration: {$metrics->duration->p95}ms\n";
echo "Failure Rate: {$metrics->failureRate}%\n";
echo "Health Score: {$metrics->health->score}/100\n";

// React to events
Event::listen(HealthScoreChanged::class, function ($event) {
    if ($event->toStatus === 'critical') {
        Slack::alert("Queue health critical!");
    }
});
```

## Documentation

📚 **[Full Documentation →](docs/introduction.md)**

### Getting Started
- **[Installation](docs/installation.md)** - Get up and running
- **[Quick Start](docs/quickstart.md)** - 5-minute walkthrough
- **[Configuration](docs/configuration-reference.md)** - Customize behavior

### Integration
- **[Facade API](docs/basic-usage/facade-api.md)** - Developer interface
- **[HTTP API](docs/basic-usage/api-endpoints.md)** - REST endpoints
- **[Prometheus](docs/advanced-usage/prometheus.md)** - Monitoring integration

### Extensibility
- **[Events](docs/advanced-usage/events.md)** - React to metrics changes
- **[Architecture](docs/advanced-usage/architecture.md)** - How it works

### Real-World Examples
- **[Artisan Commands](docs/basic-usage/artisan-commands.md)** - CLI tools
- **[Performance Tuning](docs/advanced-usage/performance.md)** - Optimizing for scale

## Installation

```bash
composer require cboxdk/laravel-queue-metrics
```

That's it! The package auto-registers and starts collecting metrics immediately.

For database storage, run migrations:

```bash
php artisan vendor:publish --tag="laravel-queue-metrics-migrations"
php artisan migrate
```

**[→ Full installation guide](docs/installation.md)**

## Key Features

### Job Metrics
Track execution time, memory usage, CPU time, throughput, and failure rates per job class with percentile statistics (P50, P95, P99).

### Queue Health
Monitor queue depth, processing rates, failure rates, and health scores with automatic issue detection.

### Worker Monitoring
Real-time worker status, resource consumption, efficiency metrics, and stale worker detection.

### Trend Analysis
Historical analysis with linear regression, forecasting, and anomaly detection for proactive insights.

### Baseline Comparison
Automatic baseline calculation to detect performance degradation and regressions.

### Flexible Storage
Redis (fast, in-memory) or Database (persistent) backends with automatic TTL cleanup.

### Prometheus Export
Native Prometheus metrics endpoint for Grafana dashboards and alerting.

### RESTful API
Complete HTTP API for integration with custom dashboards and monitoring tools.

### Events
Extensible architecture with events for reactive monitoring and notifications.

## Requirements

- PHP 8.3+
- Laravel 11.0+ or 12.0+
- Redis or Database for metrics storage

**Note**: Laravel 12.19+ is recommended for most accurate queue metrics ([Laravel PR #56010](https://github.com/laravel/framework/pull/56010)). Earlier versions use driver-specific implementations.

## Configuration

### Storage Backend

**Redis (Recommended for Production)**:

```env
QUEUE_METRICS_STORAGE=redis
QUEUE_METRICS_CONNECTION=default
```

**Database (For Persistence)**:

```env
QUEUE_METRICS_STORAGE=database
```

### API Authentication

```php
// config/queue-metrics.php
'api' => [
    'enabled' => true,
    'middleware' => ['api', 'auth:sanctum'], // Secure the API
],
```

### Scheduled Commands

The package automatically schedules necessary maintenance tasks. You can disable or configure this behavior in `config/queue-metrics.php`:

```php
// config/queue-metrics.php
'scheduling' => [
    'enabled' => true,
    'tasks' => [
        'cleanup_stale_workers' => true,
        'calculate_baselines' => true,
        'calculate_queue_metrics' => true,
        'record_trends' => true,
    ],
],
```

If you prefer to manually schedule commands in your `Console/Kernel.php` (or `routes/console.php`), simply set `'enabled' => false`.

**[→ Complete configuration reference](docs/configuration-reference.md)**

## Usage Examples

### Monitor Job Performance

```php
$metrics = QueueMetrics::getJobMetrics(ProcessOrder::class);

if ($metrics->duration->p95 > 5000) {
    alert("ProcessOrder is slow: {$metrics->duration->p95}ms");
}

if ($metrics->failureRate > 5) {
    alert("ProcessOrder failing: {$metrics->failureRate}%");
}
```

### Check Queue Health

```php
$queue = QueueMetrics::getQueueMetrics('redis', 'default');

if ($queue->health->status === 'critical') {
    PagerDuty::alert("Queue critical: {$queue->health->score}/100");
}

if ($queue->depth->total > 10000) {
    Log::warning("Queue depth high: {$queue->depth->total}");
}
```

### React to Events

```php
Event::listen(WorkerEfficiencyChanged::class, function ($event) {
    if ($event->getScalingRecommendation() === 'scale_up') {
        AutoScaler::scaleUp($event->activeWorkers + 2);
    }
});
```

### Multi-Tenancy Integration

```php
use Cbox\LaravelQueueMetrics\Events\MetricsRecorded;

Event::listen(MetricsRecorded::class, function (MetricsRecorded $event) {
    // Log with tenant context
    Log::info('Job metrics recorded', [
        'tenant_id' => tenant('id'),
        'tenant_plan' => tenant('plan'),
        'job' => $event->metrics->jobClass,
        'duration' => $event->metrics->duration->avg,
    ]);
});
```

**[→ More examples](docs/quickstart.md#real-world-examples)**

## API Endpoints

```bash
# System overview
GET /queue-metrics/overview

# Job metrics
GET /queue-metrics/jobs/App\\Jobs\\ProcessOrder

# Queue health
GET /queue-metrics/queues/default?connection=redis

# Active workers
GET /queue-metrics/workers

# Prometheus export
GET /queue-metrics/prometheus
```

**[→ Complete API reference](docs/basic-usage/api-endpoints.md)**

## Prometheus Integration

```yaml
# prometheus.yml
scrape_configs:
  - job_name: 'laravel-queues'
    static_configs:
      - targets: ['your-app.test']
    metrics_path: '/queue-metrics/prometheus'
    scrape_interval: 30s
```

Query metrics:

```promql
# Queue depth
queue_depth{connection="redis",queue="default"}

# Job duration P95
job_duration_p95_ms{job_class="App\\Jobs\\ProcessOrder"}

# Failure rate
job_failure_rate > 5
```

**[→ Prometheus setup guide](docs/advanced-usage/prometheus.md)**

## Architecture

Laravel Queue Metrics uses a clean, layered architecture:

- **Event Listeners** → Capture Laravel queue events
- **Actions** → Business logic for recording metrics
- **Repositories** → Data access abstraction
- **Storage Drivers** → Pluggable backends (Redis/Database)
- **Services** → High-level business operations
- **DTOs** → Type-safe, immutable data structures
- **Events** → Reactive monitoring and notifications

**[→ Architecture deep dive](docs/advanced-usage/architecture.md)**

## Performance

- **Per-job overhead**: ~1-2ms (Redis), ~5-15ms (Database)
- **Memory overhead**: ~5-10MB package classes, ~1-2KB per job record
- **Tested throughput**: 10,000+ jobs/minute
- **Storage**: Auto-cleanup via TTL (Redis) or manual cleanup (Database)

**[→ Performance tuning guide](docs/advanced-usage/performance.md)**

## Testing

```bash
composer test
composer analyse
composer format
```

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Security Vulnerabilities

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## Credits

- [Sylvester Damgaard](https://github.com/sylvesterdamgaard)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.

---

**[📚 Read the full documentation →](docs/introduction.md)**
