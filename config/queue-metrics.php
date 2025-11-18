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

// config for PHPeek/LaravelQueueMetrics
return [

    /*
    |--------------------------------------------------------------------------
    | 👉 BASIC CONFIGURATION
    |--------------------------------------------------------------------------
    |
    | Essential settings to get started with queue metrics collection.
    | These are the only settings most users need to configure.
    |
    */

    'enabled' => env('QUEUE_METRICS_ENABLED', true),

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
    | 🔒 SECURITY
    |--------------------------------------------------------------------------
    |
    | Control access to metrics endpoints.
    |
    */

    'allowed_ips' => env('QUEUE_METRICS_ALLOWED_IPS') ? explode(',', env('QUEUE_METRICS_ALLOWED_IPS')) : null,

    'middleware' => [
        'api',
        AllowIps::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | 📊 INTEGRATIONS
    |--------------------------------------------------------------------------
    |
    | Optional integrations for monitoring and observability.
    |
    */

    'prometheus' => [
        'enabled' => env('QUEUE_METRICS_PROMETHEUS_ENABLED', true),
        'namespace' => env('QUEUE_METRICS_PROMETHEUS_NAMESPACE', 'laravel_queue'),

        // Cache TTL for metrics export (in seconds)
        // Prevents multiple concurrent requests from overloading Redis with key scans
        'cache_ttl' => env('QUEUE_METRICS_PROMETHEUS_CACHE_TTL', 10),

        // Optional rate limiting for Prometheus endpoint
        // Enable this if you want to prevent abuse of the metrics endpoint
        'rate_limit' => [
            'enabled' => env('QUEUE_METRICS_PROMETHEUS_RATE_LIMIT_ENABLED', false),
            'max_attempts' => env('QUEUE_METRICS_PROMETHEUS_RATE_LIMIT_MAX_ATTEMPTS', 60),
            'decay_minutes' => env('QUEUE_METRICS_PROMETHEUS_RATE_LIMIT_DECAY_MINUTES', 1),
        ],

        // Histogram buckets for job duration and memory metrics
        // These can be overridden to match your application's characteristics
        'buckets' => [
            // Duration buckets (seconds): 10ms to 60s
            // Default: [0.01, 0.05, 0.1, 0.5, 1, 2, 5, 10, 30, 60]
            'duration' => [],

            // Memory buckets (megabytes): 10MB to 1GB
            // Default: [10, 25, 50, 100, 250, 500, 1000]
            'memory' => [],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | 🔌 EXTENSIBILITY (for autoscaler & custom processing)
    |--------------------------------------------------------------------------
    |
    | Hooks allow you to extend the metrics processing pipeline.
    | Register custom hook classes to enrich or process metrics data.
    |
    | Available contexts:
    | - 'before_record': Before recording job metrics
    | - 'after_record': After recording job metrics (enrich with custom data)
    | - 'before_calculate': Before calculating aggregated metrics
    | - 'after_calculate': After calculating aggregated metrics (export to external systems)
    | - 'before_baseline': Before baseline calculation
    | - 'after_baseline': After baseline calculation (trigger autoscaler)
    |
    | Example:
    | 'hooks' => [
    |     'after_record' => [\App\Hooks\CustomMetricsEnricherHook::class],
    |     'after_baseline' => [\App\Hooks\AutoscalerTriggerHook::class],
    | ],
    |
    */

    'hooks' => [
        'before_record' => [],
        'after_record' => [],
        'before_calculate' => [],
        'after_calculate' => [],
        'before_baseline' => [],
        'after_baseline' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | ⚙️ ADVANCED CONFIGURATION
    |--------------------------------------------------------------------------
    |
    | These settings rarely need changes. They're here if you need fine-grained
    | control over worker monitoring and baseline calculation behavior.
    |
    */

    'worker_heartbeat' => [
        'stale_threshold' => env('QUEUE_METRICS_STALE_THRESHOLD', 60),
        'auto_detect_schedule' => env('QUEUE_METRICS_AUTO_DETECT_SCHEDULE', '* * * * *'),
    ],

    'baseline' => [
        // Sliding window size (recent data is weighted higher)
        'sliding_window_days' => env('QUEUE_METRICS_BASELINE_WINDOW_DAYS', 7),

        // Exponential decay factor (higher = faster decay, more weight on recent data)
        // λ value where weight = e^(-λ * age_in_days)
        'decay_factor' => env('QUEUE_METRICS_BASELINE_DECAY_FACTOR', 0.1),

        // Target sample size for 100% confidence
        'target_sample_size' => env('QUEUE_METRICS_BASELINE_TARGET_SAMPLES', 200),

        // Adaptive recalculation intervals based on confidence (in minutes)
        'intervals' => [
            'no_baseline' => 1,      // No baseline exists - calculate every minute
            'low_confidence' => 5,   // Confidence < 0.5 - every 5 minutes
            'medium_confidence' => 10, // Confidence 0.5-0.7 - every 10 minutes
            'high_confidence' => 30,  // Confidence 0.7-0.9 - every 30 minutes
            'very_high_confidence' => 60, // Confidence >= 0.9 - every 60 minutes
        ],

        // Deviation detection for triggering more frequent recalculation
        'deviation' => [
            'enabled' => env('QUEUE_METRICS_BASELINE_DEVIATION_ENABLED', true),
            'threshold' => env('QUEUE_METRICS_BASELINE_DEVIATION_THRESHOLD', 2.0), // Standard deviations
            'trigger_interval' => 5, // Recalculate every 5 minutes when deviation detected
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | 🛠️ SPATIE-STYLE EXTENSIBILITY
    |--------------------------------------------------------------------------
    |
    | Override repositories and actions to customize package behavior.
    | This follows Spatie's pattern for extending Laravel packages.
    |
    | Example: To use a custom repository
    | 'repositories' => [
    |     JobMetricsRepository::class => \App\Repositories\CustomJobMetricsRepository::class,
    | ],
    |
    | Example: To use a custom action
    | 'actions' => [
    |     'record_job_start' => \App\Actions\CustomRecordJobStartAction::class,
    | ],
    |
    */

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
        'calculate_baselines' => \PHPeek\LaravelQueueMetrics\Actions\CalculateBaselinesAction::class,
    ],

];
