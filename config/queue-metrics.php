<?php

declare(strict_types=1);

use PHPeek\LaravelQueueMetrics\Actions\CalculateBaselinesAction;
use PHPeek\LaravelQueueMetrics\Actions\CalculateJobMetricsAction;
use PHPeek\LaravelQueueMetrics\Actions\RecordJobCompletionAction;
use PHPeek\LaravelQueueMetrics\Actions\RecordJobFailureAction;
use PHPeek\LaravelQueueMetrics\Actions\RecordJobStartAction;
use PHPeek\LaravelQueueMetrics\Actions\RecordQueueDepthHistoryAction;
use PHPeek\LaravelQueueMetrics\Actions\RecordThroughputHistoryAction;
use PHPeek\LaravelQueueMetrics\Actions\RecordWorkerHeartbeatAction;
use PHPeek\LaravelQueueMetrics\Actions\TransitionWorkerStateAction;
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
    | 👉 BASIC
    |--------------------------------------------------------------------------
    */

    'enabled' => env('QUEUE_METRICS_ENABLED', true),

    'storage' => [
        'driver' => env('QUEUE_METRICS_STORAGE', 'redis'),
        'connection' => env('QUEUE_METRICS_CONNECTION', 'default'),
        'prefix' => 'queue_metrics',

        'ttl' => [
            'raw' => 3600,
            'aggregated' => 604800,
            'baseline' => 2592000,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | 🔒 SECURITY
    |--------------------------------------------------------------------------
    */

    'allowed_ips' => env('QUEUE_METRICS_ALLOWED_IPS') ? explode(',', env('QUEUE_METRICS_ALLOWED_IPS')) : null,

    /*
    |--------------------------------------------------------------------------
    | 📊 PROMETHEUS
    |--------------------------------------------------------------------------
    */

    'prometheus' => [
        'enabled' => env('QUEUE_METRICS_PROMETHEUS_ENABLED', true),
        'namespace' => env('QUEUE_METRICS_PROMETHEUS_NAMESPACE', 'laravel_queue'),
        'cache_ttl' => env('QUEUE_METRICS_PROMETHEUS_CACHE_TTL', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | ⚙️ ADVANCED
    |--------------------------------------------------------------------------
    */

    'worker_heartbeat' => [
        'stale_threshold' => env('QUEUE_METRICS_STALE_THRESHOLD', 60),
    ],

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
    | 🛠️ EXTENSIBILITY
    |--------------------------------------------------------------------------
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
        'calculate_baselines' => CalculateBaselinesAction::class,
    ],

];
