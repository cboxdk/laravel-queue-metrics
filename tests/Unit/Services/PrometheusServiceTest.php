<?php

declare(strict_types=1);

use Cbox\LaravelQueueMetrics\Config\QueueMetricsConfig;
use Cbox\LaravelQueueMetrics\Services\Contracts\OverviewQueryInterface;
use Cbox\LaravelQueueMetrics\Services\PrometheusService;
use Spatie\Prometheus\Facades\Prometheus;

test('it exports queue depth metrics with labels', function () {
    config(['queue-metrics.prometheus.namespace' => 'test_namespace']);

    $overview = Mockery::mock(OverviewQueryInterface::class);
    $config = QueueMetricsConfig::fromConfig();

    $overview->shouldReceive('getOverview')->andReturn([
        'queues' => [
            [
                'queue' => 'default',
                'connection' => 'redis',
                'depth' => 100,
                'pending' => 80,
                'scheduled' => 15,
                'reserved' => 5,
                'oldest_job_age_seconds' => 120.5,
                'throughput_per_minute' => 10.0,
                'failure_rate' => 2.5,
                'utilization_rate' => 75.0,
            ],
        ],
        'jobs' => [],
        'workers' => ['total' => 0, 'active' => 0, 'idle' => 0, 'avg_idle_percentage' => 0, 'total_jobs_processed' => 0],
        'baselines' => [],
    ]);

    $service = new PrometheusService($overview, $config);
    $service->exportMetrics();

    $metrics = Prometheus::renderCollectors();

    // Verify queue depth metrics are present
    expect($metrics)->toContain('test_namespace_queue_depth_pending');
    expect($metrics)->toContain('test_namespace_queue_depth_delayed');
    expect($metrics)->toContain('test_namespace_queue_depth_reserved');
    expect($metrics)->toContain('test_namespace_queue_depth_total');

    // Verify labels are present
    expect($metrics)->toContain('queue="default"');
    expect($metrics)->toContain('connection="redis"');

    // Verify specific metric values with labels
    expect($metrics)->toContain('test_namespace_queue_depth_pending{queue="default",connection="redis"} 80');
    expect($metrics)->toContain('test_namespace_queue_depth_total{queue="default",connection="redis"} 100');
})->group('functional');

test('it exports job metrics with execution counters', function () {
    config(['queue-metrics.prometheus.namespace' => 'test_namespace']);

    $overview = Mockery::mock(OverviewQueryInterface::class);
    $config = QueueMetricsConfig::fromConfig();

    $overview->shouldReceive('getOverview')->andReturn([
        'queues' => [],
        'jobs' => [
            'App\\Jobs\\ProcessOrder' => [
                'queue' => 'default',
                'connection' => 'redis',
                'execution' => [
                    'success_count' => 100,
                    'failure_count' => 5,
                    'success_rate' => 0.95,
                    'failure_rate' => 0.05,
                ],
            ],
        ],
        'workers' => ['total' => 0, 'active' => 0, 'idle' => 0, 'avg_idle_percentage' => 0, 'total_jobs_processed' => 0],
        'baselines' => [],
    ]);

    $service = new PrometheusService($overview, $config);
    $service->exportMetrics();

    $metrics = Prometheus::renderCollectors();

    // Verify job execution counters
    expect($metrics)->toContain('test_namespace_job_processed_total');
    expect($metrics)->toContain('test_namespace_job_failed_total');
    expect($metrics)->toContain('test_namespace_job_success_rate_percent');
    expect($metrics)->toContain('test_namespace_job_failure_rate_percent');

    // Verify success/failure rates are percentages (0-100 scale)
    expect($metrics)->toContain('test_namespace_job_success_rate_percent{job="App\\\\Jobs\\\\ProcessOrder",queue="default",connection="redis"} 95');
    expect($metrics)->toContain('test_namespace_job_failure_rate_percent{job="App\\\\Jobs\\\\ProcessOrder",queue="default",connection="redis"} 5');
})->group('functional');

test('it exports worker metrics', function () {
    config(['queue-metrics.prometheus.namespace' => 'test_namespace']);

    $overview = Mockery::mock(OverviewQueryInterface::class);
    $config = QueueMetricsConfig::fromConfig();

    $overview->shouldReceive('getOverview')->andReturn([
        'queues' => [],
        'jobs' => [],
        'workers' => [
            'total' => 10,
            'active' => 7,
            'idle' => 3,
            'avg_idle_percentage' => 30.0,
            'total_jobs_processed' => 5000,
        ],
        'baselines' => [],
    ]);

    $service = new PrometheusService($overview, $config);
    $service->exportMetrics();

    $metrics = Prometheus::renderCollectors();

    // Verify worker count metrics
    expect($metrics)->toContain('test_namespace_worker_count_total 10');
    expect($metrics)->toContain('test_namespace_worker_count_active 7');
    expect($metrics)->toContain('test_namespace_worker_count_idle 3');

    // Verify worker utilization (100 - avg_idle_percentage = 70%)
    expect($metrics)->toContain('test_namespace_worker_utilization_percent 70');
    expect($metrics)->toContain('test_namespace_worker_idle_percent 30');

    // Verify total jobs processed
    expect($metrics)->toContain('test_namespace_worker_jobs_processed_total 5000');
})->group('functional');

test('it exports baseline metrics with labels', function () {
    config(['queue-metrics.prometheus.namespace' => 'test_namespace']);

    $overview = Mockery::mock(OverviewQueryInterface::class);
    $config = QueueMetricsConfig::fromConfig();

    $overview->shouldReceive('getOverview')->andReturn([
        'queues' => [],
        'jobs' => [],
        'workers' => ['total' => 0, 'active' => 0, 'idle' => 0, 'avg_idle_percentage' => 0, 'total_jobs_processed' => 0],
        'baselines' => [
            'default:redis:App\\Jobs\\SendEmail' => [
                'queue' => 'default',
                'connection' => 'redis',
                'job_class' => 'App\\Jobs\\SendEmail',
                'cpu_percent_per_job' => 15.5,
                'memory_mb_per_job' => 25.3,
                'avg_duration_ms' => 150.0,
                'confidence_score' => 0.85,
                'sample_count' => 200,
            ],
        ],
    ]);

    $service = new PrometheusService($overview, $config);
    $service->exportMetrics();

    $metrics = Prometheus::renderCollectors();

    // Verify baseline metrics with 3 labels (queue, connection, job)
    expect($metrics)->toContain('test_namespace_baseline_cpu_percent_per_job');
    expect($metrics)->toContain('test_namespace_baseline_memory_mb_per_job');
    expect($metrics)->toContain('test_namespace_baseline_duration_ms');
    expect($metrics)->toContain('test_namespace_baseline_confidence_score');
    expect($metrics)->toContain('test_namespace_baseline_sample_count');

    // Verify specific values with all 3 labels
    expect($metrics)->toContain('test_namespace_baseline_cpu_percent_per_job{queue="default",connection="redis",job="App\\\\Jobs\\\\SendEmail"} 15.5');
    expect($metrics)->toContain('test_namespace_baseline_confidence_score{queue="default",connection="redis",job="App\\\\Jobs\\\\SendEmail"} 0.85');
})->group('functional');

test('it handles missing queue labels gracefully', function () {
    config(['queue-metrics.prometheus.namespace' => 'test_namespace']);

    $overview = Mockery::mock(OverviewQueryInterface::class);
    $config = QueueMetricsConfig::fromConfig();

    $overview->shouldReceive('getOverview')->andReturn([
        'queues' => [
            [
                // Missing 'queue' and 'connection' keys
                'depth' => 50,
                'pending' => 40,
            ],
        ],
        'jobs' => [],
        'workers' => ['total' => 0, 'active' => 0, 'idle' => 0, 'avg_idle_percentage' => 0, 'total_jobs_processed' => 0],
        'baselines' => [],
    ]);

    $service = new PrometheusService($overview, $config);
    $service->exportMetrics();

    $metrics = Prometheus::renderCollectors();

    // Should use 'unknown' for missing labels
    expect($metrics)->toContain('queue="unknown"');
    expect($metrics)->toContain('connection="unknown"');
})->group('functional');
