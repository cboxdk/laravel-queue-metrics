# Installation

Get Laravel Queue Metrics up and running in your Laravel application.

## Requirements

- **PHP**: 8.2 or higher
- **Laravel**: 11.0+ or 12.0+
- **Storage**: Redis (recommended) or Database
- **Optional**: [gophpeek/system-metrics](https://github.com/gophpeek/system-metrics) for server monitoring

### Laravel Version Notes

Laravel 12.19+ is recommended for most accurate queue metrics. This version includes native methods for separate pending, delayed, and reserved queue sizes ([Laravel PR #56010](https://github.com/laravel/framework/pull/56010)).

Earlier versions use driver-specific implementations with reflection but work perfectly fine for most use cases.

## Installation Steps

### 1. Install via Composer

```bash
composer require gophpeek/laravel-queue-metrics
```

The package will auto-register its service provider.

### 2. Choose Storage Backend

#### Option A: Redis (Recommended)

Redis is the recommended storage backend for production use:

**Pros:**
- Fast, in-memory performance (~1-2ms per operation)
- Automatic TTL cleanup
- Low latency for real-time metrics
- Handles high throughput (10,000+ jobs/minute)

**Cons:**
- Not persistent (data lost on Redis restart)
- Requires Redis server

**Setup:**

Ensure your `.env` has Redis configured:

```env
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379
```

No migrations needed! Metrics are stored in Redis automatically.

#### Option B: Database

Use database storage for long-term persistence:

**Pros:**
- Persistent storage
- Queryable with SQL
- No additional infrastructure

**Cons:**
- Slower than Redis (~10-20ms per operation)
- Requires manual cleanup of old data
- Not ideal for very high throughput

**Setup:**

Publish and run migrations:

```bash
php artisan vendor:publish --tag="laravel-queue-metrics-migrations"
php artisan migrate
```

Configure in `.env`:

```env
QUEUE_METRICS_STORAGE=database
```

### 3. Publish Configuration (Optional)

Publish the configuration file to customize behavior:

```bash
php artisan vendor:publish --tag="laravel-queue-metrics-config"
```

This creates `config/queue-metrics.php` where you can customize:
- Storage driver and TTLs
- API endpoints and middleware
- Performance settings
- Worker heartbeat intervals

See [Configuration Reference](configuration-reference.md) for all options.

## Verification

### Test the Installation

#### 1. Dispatch a Test Job

```bash
php artisan tinker
```

```php
dispatch(new \App\Jobs\TestJob());
```

#### 2. Check Metrics via API

```bash
curl http://your-app.test/queue-metrics/overview
```

You should see JSON with queue statistics.

#### 3. Verify Metrics Storage

**For Redis:**

```bash
redis-cli
> KEYS queue_metrics:*
```

**For Database:**

```bash
php artisan tinker
```

```php
DB::table('queue_job_metrics')->count();
```

### Start Queue Worker

Ensure you have a queue worker running:

```bash
php artisan queue:work redis --queue=default
```

Or with Horizon:

```bash
php artisan horizon
```

## Post-Installation Setup

### 1. Configure Scheduled Commands (Recommended)

Add these to `app/Console/Kernel.php`:

```php
protected function schedule(Schedule $schedule)
{
    // Record trends every 5 minutes for historical analysis
    $schedule->command('queue-metrics:trends:record')
        ->everyFiveMinutes();

    // Detect stale workers every minute
    $schedule->command('queue-metrics:workers:detect-stale')
        ->everyMinute();

    // Calculate baselines daily
    $schedule->command('queue-metrics:baseline:calculate')
        ->daily();

    // Cleanup old data (only needed for database storage)
    $schedule->command('queue-metrics:cleanup --days=7')
        ->dailyAt('02:00')
        ->when(fn() => config('queue-metrics.storage.driver') === 'database');
}
```

### 2. Configure API Middleware (Optional)

By default, the API is accessible without authentication. To secure it:

**In `config/queue-metrics.php`:**

```php
'api' => [
    'enabled' => true,
    'prefix' => 'queue-metrics',
    'middleware' => ['api', 'auth:sanctum'], // Add authentication
],
```

Or create a custom middleware:

```php
'middleware' => ['api', 'check.admin'],
```

### 3. Register Event Listeners (Optional)

To react to metrics events, register listeners in `EventServiceProvider`:

```php
use PHPeek\LaravelQueueMetrics\Events\{
    MetricsRecorded,
    WorkerEfficiencyChanged,
    HealthScoreChanged,
    BaselineRecalculated,
    QueueDepthThresholdExceeded,
};

protected $listen = [
    MetricsRecorded::class => [
        SendMetricsToDatadog::class,
    ],

    WorkerEfficiencyChanged::class => [
        TriggerAutoScaling::class,
    ],

    HealthScoreChanged::class => [
        SendHealthAlert::class,
    ],
];
```

See [Events](events.md) for detailed usage.

### 4. Register Hooks (Optional)

For data enrichment, register hooks in `AppServiceProvider`:

```php
use PHPeek\LaravelQueueMetrics\Facades\QueueMetrics;

public function boot(): void
{
    QueueMetrics::hook('before_record', function (array $data) {
        // Add custom metadata
        $data['environment'] = app()->environment();
        $data['tenant_id'] = tenant('id');

        return $data;
    });
}
```

See [Hooks](hooks.md) for detailed usage.

## Common Issues

### Issue: No Metrics Appearing

**Symptoms:** API returns empty data, no metrics in storage

**Solutions:**

1. **Verify queue worker is running:**
   ```bash
   php artisan queue:work
   ```

2. **Check if jobs are actually processing:**
   ```bash
   php artisan queue:work --once
   ```

3. **Verify package is enabled:**
   ```bash
   php artisan tinker
   ```
   ```php
   config('queue-metrics.enabled'); // Should be true
   ```

4. **Check event listeners are registered:**
   ```bash
   php artisan event:list | grep Queue
   ```

### Issue: High Memory Usage

**Symptoms:** Redis or database growing too large

**Solutions:**

1. **Reduce TTL values** in `config/queue-metrics.php`:
   ```php
   'ttl' => [
       'raw' => 1800,        // 30 minutes instead of 1 hour
       'aggregated' => 86400, // 1 day instead of 7 days
   ],
   ```

2. **For database storage, run cleanup:**
   ```bash
   php artisan queue-metrics:cleanup --days=3
   ```

3. **Reduce sample sizes:**
   ```php
   'performance' => [
       'percentile_samples' => 500,  // Reduce from 1000
       'baseline_samples' => 50,     // Reduce from 100
   ],
   ```

### Issue: API Returns 404

**Symptoms:** `/queue-metrics/*` endpoints not found

**Solutions:**

1. **Clear route cache:**
   ```bash
   php artisan route:clear
   ```

2. **Verify API is enabled:**
   ```php
   config('queue-metrics.api.enabled'); // Should be true
   ```

3. **Check route registration:**
   ```bash
   php artisan route:list | grep queue-metrics
   ```

### Issue: Slow Performance

**Symptoms:** Job processing slowed after installing package

**Solutions:**

1. **Use Redis storage** instead of database
2. **Increase batch sizes** in config:
   ```php
   'performance' => [
       'batch_size' => 200, // Increase from 100
   ],
   ```

3. **Disable metrics temporarily:**
   ```env
   QUEUE_METRICS_ENABLED=false
   ```

4. **Use queued event listeners** for integrations

## Upgrading

### From Pre-1.0 Versions

If upgrading from a pre-1.0 version:

1. **Clear old config:**
   ```bash
   rm config/queue-metrics.php
   ```

2. **Republish config:**
   ```bash
   php artisan vendor:publish --tag="laravel-queue-metrics-config" --force
   ```

3. **Run new migrations:**
   ```bash
   php artisan migrate
   ```

4. **Update event listener namespaces** if you registered any

### Composer Update

Regular updates:

```bash
composer update gophpeek/laravel-queue-metrics
```

Always check [CHANGELOG.md](../CHANGELOG.md) for breaking changes.

## Uninstallation

To remove the package:

### 1. Remove Package

```bash
composer remove gophpeek/laravel-queue-metrics
```

### 2. Clean Up Data

**For Redis:**

```bash
redis-cli
> KEYS queue_metrics:*
> DEL queue_metrics:* # Be careful!
```

**For Database:**

```bash
php artisan migrate:rollback --path=database/migrations/*_create_queue_metrics_tables.php
```

### 3. Remove Config

```bash
rm config/queue-metrics.php
```

### 4. Clear Cache

```bash
php artisan config:clear
php artisan route:clear
```

## Next Steps

- [Quick Start Guide](quickstart.md) - Start using the package
- [Configuration Reference](configuration-reference.md) - Customize behavior
- [Facade API](facade-api.md) - Learn the developer interface
- [Hooks](hooks.md) - Add custom data enrichment
- [Events](events.md) - React to metrics events
