<?php

declare(strict_types=1);

use Cbox\LaravelQueueMetrics\Telemetry\Contracts\ProvidesTelemetrySnapshot;
use Cbox\LaravelQueueMetrics\Telemetry\QueueMetricsTelemetryProvider;
use Cbox\Telemetry\Facades\Telemetry;

beforeEach(function () {
    config(['queue-metrics.telemetry.enabled' => true]);

    $this->fake = Telemetry::fake();
});

function bindTelemetrySnapshot(
    array $queues = [],
    array $workers = [],
    array $baselines = [],
): void {
    $snapshot = Mockery::mock(ProvidesTelemetrySnapshot::class);
    $snapshot->shouldReceive('snapshot')->andReturn([
        'queues' => $queues,
        'workers' => $workers === [] ? [
            'count' => ['total' => 0, 'active' => 0, 'idle' => 0],
            'utilization' => ['current_busy_percent' => 0.0, 'lifetime_busy_percent' => 0.0],
        ] : $workers,
        'baselines' => $baselines,
    ]);

    app()->instance(ProvidesTelemetrySnapshot::class, $snapshot);
}

function exampleQueue(): array
{
    return [
        [
            'connection' => 'redis',
            'queue' => 'default',
            'depth' => [
                'total' => 15,
                'pending' => 10,
                'scheduled' => 3,
                'reserved' => 2,
                'oldest_job_age_seconds' => 42,
            ],
            'performance_60s' => [
                'throughput_per_minute' => 12.5,
                'avg_duration_ms' => 250.0,
            ],
            'lifetime' => [
                'failure_rate_percent' => 1.5,
            ],
            'workers' => [
                'active_count' => 4,
                'current_busy_percent' => 50.0,
                'lifetime_busy_percent' => 75.0,
            ],
        ],
    ];
}

it('publishes queue depth gauges by state', function () {
    bindTelemetrySnapshot(queues: exampleQueue());

    $this->fake->provider(new QueueMetricsTelemetryProvider(app()));

    $labels = ['connection' => 'redis', 'queue' => 'default'];

    expect($this->fake->gaugeValue('queue_metrics.queue.depth', [...$labels, 'state' => 'pending']))->toBe(10.0)
        ->and($this->fake->gaugeValue('queue_metrics.queue.depth', [...$labels, 'state' => 'scheduled']))->toBe(3.0)
        ->and($this->fake->gaugeValue('queue_metrics.queue.depth', [...$labels, 'state' => 'reserved']))->toBe(2.0);
})->group('functional');

it('publishes queue backlog, throughput, failure rate and active workers gauges', function () {
    bindTelemetrySnapshot(queues: exampleQueue());

    $this->fake->provider(new QueueMetricsTelemetryProvider(app()));

    $labels = ['connection' => 'redis', 'queue' => 'default'];

    expect($this->fake->gaugeValue('queue_metrics.queue.oldest_job.age', $labels))->toBe(42.0)
        ->and($this->fake->gaugeValue('queue_metrics.queue.throughput', $labels))->toBe(12.5)
        ->and($this->fake->gaugeValue('queue_metrics.queue.failure_rate', $labels))->toBe(1.5)
        ->and($this->fake->gaugeValue('queue_metrics.queue.active_workers', $labels))->toBe(4.0);
})->group('functional');

it('publishes worker summary gauges', function () {
    bindTelemetrySnapshot(workers: [
        'count' => ['total' => 5, 'active' => 3, 'idle' => 2],
        'utilization' => ['current_busy_percent' => 60.0, 'lifetime_busy_percent' => 80.0],
    ]);

    $this->fake->provider(new QueueMetricsTelemetryProvider(app()));

    expect($this->fake->gaugeValue('queue_metrics.workers.count', ['state' => 'busy']))->toBe(3.0)
        ->and($this->fake->gaugeValue('queue_metrics.workers.count', ['state' => 'idle']))->toBe(2.0)
        ->and($this->fake->gaugeValue('queue_metrics.workers.utilization', ['window' => 'current']))->toBe(60.0)
        ->and($this->fake->gaugeValue('queue_metrics.workers.utilization', ['window' => 'lifetime']))->toBe(80.0);
})->group('functional');

it('publishes baseline gauges per queue and job class', function () {
    bindTelemetrySnapshot(queues: exampleQueue(), baselines: [
        [
            'connection' => 'redis',
            'queue' => 'default',
            'job_class' => 'App\\Jobs\\SendEmail',
            'cpu_percent_per_job' => 12.0,
            'memory_mb_per_job' => 64.0,
            'avg_duration_ms' => 250.0,
            'sample_count' => 100,
            'confidence_score' => 0.9,
        ],
    ]);

    $this->fake->provider(new QueueMetricsTelemetryProvider(app()));

    $labels = ['connection' => 'redis', 'queue' => 'default', 'job.name' => 'App\\Jobs\\SendEmail'];

    expect($this->fake->gaugeValue('queue_metrics.baseline.duration', $labels))->toBe(250.0)
        ->and($this->fake->gaugeValue('queue_metrics.baseline.memory', $labels))->toBe(64.0)
        ->and($this->fake->gaugeValue('queue_metrics.baseline.cpu', $labels))->toBe(12.0)
        ->and($this->fake->gaugeValue('queue_metrics.baseline.confidence', $labels))->toBe(0.9);
})->group('functional');

it('does not register queue gauges when the queues toggle is disabled', function () {
    config(['queue-metrics.telemetry.gauges.queues' => false]);

    bindTelemetrySnapshot(queues: exampleQueue());

    $this->fake->provider(new QueueMetricsTelemetryProvider(app()));

    $names = array_map(fn ($family) => $family->name(), $this->fake->collect());

    expect($names)->not->toContain('queue_metrics.queue.depth')
        ->and($names)->toContain('queue_metrics.workers.count');
})->group('functional');

it('does not register baseline gauges when the baselines toggle is disabled', function () {
    config(['queue-metrics.telemetry.gauges.baselines' => false]);

    bindTelemetrySnapshot(queues: exampleQueue());

    $this->fake->provider(new QueueMetricsTelemetryProvider(app()));

    $names = array_map(fn ($family) => $family->name(), $this->fake->collect());

    expect($names)->not->toContain('queue_metrics.baseline.duration');
})->group('functional');

it('identifies itself as the cbox.queue-metrics provider', function () {
    expect((new QueueMetricsTelemetryProvider(app()))->name())->toBe('cbox.queue-metrics');
})->group('functional');
