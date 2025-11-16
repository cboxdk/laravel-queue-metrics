<?php

declare(strict_types=1);

use PHPeek\LaravelQueueMetrics\Actions\CalculateJobMetricsAction;
use PHPeek\LaravelQueueMetrics\Actions\RecordJobCompletionAction;
use PHPeek\LaravelQueueMetrics\Actions\RecordJobFailureAction;
use PHPeek\LaravelQueueMetrics\Actions\RecordJobStartAction;
use PHPeek\LaravelQueueMetrics\Actions\RecordQueueDepthHistoryAction;
use PHPeek\LaravelQueueMetrics\Actions\RecordThroughputHistoryAction;
use PHPeek\LaravelQueueMetrics\Actions\RecordWorkerHeartbeatAction;
use PHPeek\LaravelQueueMetrics\Actions\TransitionWorkerStateAction;
use PHPeek\LaravelQueueMetrics\Http\Middleware\AllowIps;
use PHPeek\LaravelQueueMetrics\Repositories\Contracts\BaselineRepository;
use PHPeek\LaravelQueueMetrics\Repositories\Contracts\JobMetricsRepository;
use PHPeek\LaravelQueueMetrics\Repositories\Contracts\QueueMetricsRepository;
use PHPeek\LaravelQueueMetrics\Repositories\Contracts\WorkerHeartbeatRepository;
use PHPeek\LaravelQueueMetrics\Repositories\Contracts\WorkerRepository;
use PHPeek\LaravelQueueMetrics\Repositories\RedisBaselineRepository;
use PHPeek\LaravelQueueMetrics\Repositories\RedisJobMetricsRepository;
use PHPeek\LaravelQueueMetrics\Repositories\RedisQueueMetricsRepository;
use PHPeek\LaravelQueueMetrics\Repositories\RedisWorkerHeartbeatRepository;
use PHPeek\LaravelQueueMetrics\Repositories\RedisWorkerRepository;
use PHPeek\LaravelQueueMetrics\Storage\DatabaseStorageDriver;
use PHPeek\LaravelQueueMetrics\Storage\RedisStorageDriver;

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
    | HTTP API Configuration
    |--------------------------------------------------------------------------
    |
    | Configure the HTTP API for accessing queue metrics.
    |
    */

    'urls' => [
        'metrics' => 'queue-metrics',
        'overview' => 'queue-metrics/overview',
        'jobs' => 'queue-metrics/jobs',
        'queues' => 'queue-metrics/queues',
        'workers' => 'queue-metrics/workers',
    ],

    /*
    |--------------------------------------------------------------------------
    | Allowed IPs
    |--------------------------------------------------------------------------
    |
    | Only these IP addresses will be allowed to access the metrics endpoints.
    | Set to null to allow all IPs.
    |
    */

    'allowed_ips' => env('QUEUE_METRICS_ALLOWED_IPS') ? explode(',', env('QUEUE_METRICS_ALLOWED_IPS')) : null,

    /*
    |--------------------------------------------------------------------------
    | Middleware
    |--------------------------------------------------------------------------
    |
    | The middleware that will be applied to the metrics URLs.
    |
    */

    'middleware' => [
        'api',
        AllowIps::class,
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
        'enabled' => env('QUEUE_METRICS_PROMETHEUS_ENABLED', true),
        'namespace' => env('QUEUE_METRICS_PROMETHEUS_NAMESPACE', 'laravel_queue'),
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
        'stale_threshold' => env('QUEUE_METRICS_STALE_THRESHOLD', 60),
        'auto_detect_schedule' => env('QUEUE_METRICS_AUTO_DETECT_SCHEDULE', '* * * * *'),
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
        'batch_size' => 100,
        'percentile_samples' => 1000,
        'baseline_samples' => 100,
    ],

    /*
    |--------------------------------------------------------------------------
    | Customizable Classes
    |--------------------------------------------------------------------------
    |
    | You can override these classes to customize the low-level behavior
    | of the package. In most cases, you can just use the defaults.
    |
    */

    'storage_drivers' => [
        'redis' => RedisStorageDriver::class,
        'database' => DatabaseStorageDriver::class,
    ],

    'repositories' => [
        JobMetricsRepository::class => RedisJobMetricsRepository::class,
        QueueMetricsRepository::class => RedisQueueMetricsRepository::class,
        WorkerRepository::class => RedisWorkerRepository::class,
        BaselineRepository::class => RedisBaselineRepository::class,
        WorkerHeartbeatRepository::class => RedisWorkerHeartbeatRepository::class,
    ],

    'actions' => [
        'record_job_start' => RecordJobStartAction::class,
        'record_job_completion' => RecordJobCompletionAction::class,
        'record_job_failure' => RecordJobFailureAction::class,
        'calculate_job_metrics' => CalculateJobMetricsAction::class,
        'record_worker_heartbeat' => RecordWorkerHeartbeatAction::class,
        'transition_worker_state' => TransitionWorkerStateAction::class,
        'record_queue_depth_history' => RecordQueueDepthHistoryAction::class,
        'record_throughput_history' => RecordThroughputHistoryAction::class,
    ],

];
