<?php

declare(strict_types=1);

// config for PHPeek/LaravelQueueMetrics
return [

    /*
    |--------------------------------------------------------------------------
    | Queue Metrics Enabled
    |--------------------------------------------------------------------------
    |
    | Enable or disable queue metrics collection globally.
    |
    */

    'enabled' => env('QUEUE_METRICS_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Storage Configuration
    |--------------------------------------------------------------------------
    |
    | Configure how queue metrics are stored. Supported drivers:
    | - 'redis': Fast, in-memory storage (recommended for production)
    | - 'database': Persistent storage using database tables
    | - 'null': Disable storage (useful for testing)
    |
    */

    'storage' => [
        'driver' => env('QUEUE_METRICS_STORAGE', 'redis'),
        'connection' => env('QUEUE_METRICS_CONNECTION', 'default'),
        'prefix' => 'queue_metrics',

        'ttl' => [
            'raw' => 3600,        // 1 hour - raw job execution data
            'aggregated' => 604800, // 7 days - calculated metrics
            'baseline' => 2592000,  // 30 days - baseline calculations
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Time Windows
    |--------------------------------------------------------------------------
    |
    | Define time windows for metrics aggregation in seconds.
    | These are used to calculate rolling averages and trends.
    |
    */

    'windows' => [
        'short' => [60, 300, 900],    // 1 minute, 5 minutes, 15 minutes
        'medium' => [3600],            // 1 hour
        'long' => [86400],             // 24 hours
    ],

    /*
    |--------------------------------------------------------------------------
    | Retention Policies
    |--------------------------------------------------------------------------
    |
    | How long to retain different types of metrics data in seconds.
    |
    */

    'retention' => [
        'raw_metrics' => 3600,           // 1 hour
        'aggregated_metrics' => 604800,  // 7 days
        'baselines' => 2592000,          // 30 days
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP API Configuration
    |--------------------------------------------------------------------------
    |
    | Configure the HTTP API for accessing queue metrics.
    |
    */

    'api' => [
        'enabled' => true,
        'prefix' => 'queue-metrics',
        'middleware' => ['api'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Prometheus Export
    |--------------------------------------------------------------------------
    |
    | Configure Prometheus metrics export.
    |
    */

    'prometheus' => [
        'enabled' => true,
        'namespace' => 'laravel_queue',
    ],

    /*
    |--------------------------------------------------------------------------
    | Performance Tuning
    |--------------------------------------------------------------------------
    |
    | Fine-tune the performance of metrics collection and calculation.
    |
    */

    'performance' => [
        'batch_size' => 100,              // Batch size for bulk operations
        'percentile_samples' => 1000,     // Number of samples for percentile calculations
        'baseline_samples' => 100,        // Number of samples for baseline calculations
    ],

    /*
    |--------------------------------------------------------------------------
    | Worker Heartbeat Configuration
    |--------------------------------------------------------------------------
    |
    | Configure worker heartbeat monitoring and stale worker detection.
    |
    */

    'worker_heartbeat' => [
        'stale_threshold' => env('QUEUE_METRICS_STALE_THRESHOLD', 60), // Seconds before worker considered stale
        'auto_detect_schedule' => env('QUEUE_METRICS_AUTO_DETECT_SCHEDULE', '* * * * *'), // Cron expression for automatic detection
    ],

];
