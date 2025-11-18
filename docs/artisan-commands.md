# Artisan Commands

Laravel Queue Metrics provides Artisan commands for maintenance and monitoring.

## Available Commands

### queue-metrics:record-trends

Record trend data for historical analysis.

```bash
php artisan queue-metrics:record-trends
```

**Purpose**: Records queue depth and worker efficiency snapshots for trend analysis.

**Schedule Example**:

```php
// app/Console/Kernel.php
protected function schedule(Schedule $schedule)
{
    $schedule->command('queue-metrics:record-trends')
        ->everyFiveMinutes();
}
```

---

### queue-metrics:detect-stale-workers

Detect and mark stale workers.

```bash
php artisan queue-metrics:detect-stale-workers
```

**Purpose**: Identifies workers that haven't sent heartbeats recently and marks them as stale.

**Options**:

```bash
# Custom stale threshold (default: 60 seconds)
php artisan queue-metrics:detect-stale-workers --threshold=180
```

**Schedule Example**:

```php
protected function schedule(Schedule $schedule)
{
    $schedule->command('queue-metrics:detect-stale-workers')
        ->everyMinute();
}
```

---

### queue-metrics:migrate-discovery

Migrate existing metrics to discovery sets.

```bash
php artisan queue-metrics:migrate-discovery
```

**Purpose**: One-time migration command to improve performance by using Redis sets for discovery instead of key scanning.

**When to run**: After upgrading from a version that didn't use discovery sets.

## Recommended Schedule

```php
// app/Console/Kernel.php
protected function schedule(Schedule $schedule)
{
    // Trend recording
    $schedule->command('queue-metrics:record-trends')
        ->everyFiveMinutes();

    // Worker monitoring
    $schedule->command('queue-metrics:detect-stale-workers')
        ->everyMinute();
}
```

## Next Steps

- [Configuration Reference](configuration-reference.md) - Configure TTL and thresholds
- [API Endpoints](api-endpoints.md) - HTTP access to metrics
