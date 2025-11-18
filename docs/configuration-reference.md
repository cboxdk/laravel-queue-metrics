# Configuration Reference

Complete configuration options for Laravel Queue Metrics.

## Publishing Configuration

```bash
php artisan vendor:publish --tag="laravel-queue-metrics-config"
```

This creates `config/queue-metrics.php`.

## Configuration Options

```php
<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Metrics Collection Enabled
    |--------------------------------------------------------------------------
    */
    'enabled' => env('QUEUE_METRICS_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Storage Configuration
    |--------------------------------------------------------------------------
    |
    | driver: 'redis' (only Redis is currently supported)
    | connection: Redis connection name from config/database.php
    | prefix: Key prefix for Redis storage
    |
    */
    'storage' => [
        'driver' => env('QUEUE_METRICS_STORAGE', 'redis'),
        'connection' => env('QUEUE_METRICS_CONNECTION', 'default'),
        'prefix' => 'queue_metrics',

        // TTL (time to live) in seconds for metric types
        'ttl' => [
            'raw' => 3600,          // 1 hour for raw job metrics
            'aggregated' => 604800, // 7 days for aggregated metrics
            'baseline' => 2592000,  // 30 days for baseline calculations
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Security - IP Allowlist
    |--------------------------------------------------------------------------
    */
    'allowed_ips' => env('QUEUE_METRICS_ALLOWED_IPS')
        ? explode(',', env('QUEUE_METRICS_ALLOWED_IPS'))
        : null,

    /*
    |--------------------------------------------------------------------------
    | Prometheus Export
    |--------------------------------------------------------------------------
    */
    'prometheus' => [
        'enabled' => env('QUEUE_METRICS_PROMETHEUS_ENABLED', true),
        'namespace' => env('QUEUE_METRICS_PROMETHEUS_NAMESPACE', 'laravel_queue'),
        'cache_ttl' => env('QUEUE_METRICS_PROMETHEUS_CACHE_TTL', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Worker Monitoring
    |--------------------------------------------------------------------------
    */
    'worker_heartbeat' => [
        'stale_threshold' => env('QUEUE_METRICS_STALE_THRESHOLD', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Baseline Calculation
    |--------------------------------------------------------------------------
    */
    'baseline' => [
        'sliding_window_days' => env('QUEUE_METRICS_BASELINE_WINDOW_DAYS', 7),
        'decay_factor' => env('QUEUE_METRICS_BASELINE_DECAY_FACTOR', 0.1),
        'target_sample_size' => env('QUEUE_METRICS_BASELINE_TARGET_SAMPLES', 200),

        'intervals' => [
            'no_baseline' => 1,
            'low_confidence' => 5,
            'medium_confidence' => 10,
            'high_confidence' => 30,
            'very_high_confidence' => 60,
        ],

        'deviation' => [
            'enabled' => env('QUEUE_METRICS_BASELINE_DEVIATION_ENABLED', true),
            'threshold' => env('QUEUE_METRICS_BASELINE_DEVIATION_THRESHOLD', 2.0),
            'trigger_interval' => 5,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Dependency Injection Bindings
    |--------------------------------------------------------------------------
    */
    'repositories' => [
        // Repository interface to implementation mappings
    ],

    'actions' => [
        // Action name to class mappings
    ],
];
```

## Environment Variables

```env
# Enable/disable metrics collection
QUEUE_METRICS_ENABLED=true

# Storage configuration
QUEUE_METRICS_STORAGE=redis
QUEUE_METRICS_CONNECTION=default

# Security (comma-separated IPs)
QUEUE_METRICS_ALLOWED_IPS=127.0.0.1,::1

# Prometheus
QUEUE_METRICS_PROMETHEUS_ENABLED=true
QUEUE_METRICS_PROMETHEUS_NAMESPACE=laravel_queue
QUEUE_METRICS_PROMETHEUS_CACHE_TTL=10

# Worker monitoring (seconds before worker considered stale)
QUEUE_METRICS_STALE_THRESHOLD=60

# Baseline configuration
QUEUE_METRICS_BASELINE_WINDOW_DAYS=7
QUEUE_METRICS_BASELINE_DECAY_FACTOR=0.1
QUEUE_METRICS_BASELINE_TARGET_SAMPLES=200
QUEUE_METRICS_BASELINE_DEVIATION_ENABLED=true
QUEUE_METRICS_BASELINE_DEVIATION_THRESHOLD=2.0
```

## Configuration Examples

### Production Redis Setup

```php
// Use dedicated Redis connection for metrics
'storage' => [
    'driver' => 'redis',
    'connection' => 'metrics', // Define in config/database.php
    'prefix' => 'queue_metrics',
    'ttl' => [
        'raw' => 1800,          // 30 minutes for high-volume
        'aggregated' => 604800, // 7 days
        'baseline' => 2592000,  // 30 days
    ],
],

// config/database.php
'redis' => [
    'metrics' => [
        'host' => env('REDIS_METRICS_HOST', '127.0.0.1'),
        'password' => env('REDIS_METRICS_PASSWORD', null),
        'port' => env('REDIS_METRICS_PORT', 6379),
        'database' => env('REDIS_METRICS_DB', 2),
    ],
],
```

### Secure API Access

```php
// Restrict metrics access by IP
'allowed_ips' => ['10.0.0.0/8', '172.16.0.0/12'],
```

## Next Steps

- [API Endpoints](api-endpoints.md) - HTTP access to metrics
- [Prometheus](prometheus.md) - Monitoring integration
