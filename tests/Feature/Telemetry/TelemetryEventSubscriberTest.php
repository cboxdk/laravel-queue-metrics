<?php

declare(strict_types=1);

use Carbon\Carbon;
use Cbox\LaravelQueueMetrics\DataTransferObjects\BaselineData;
use Cbox\LaravelQueueMetrics\DataTransferObjects\QueueDepthData;
use Cbox\LaravelQueueMetrics\Events\BaselineRecalculated;
use Cbox\LaravelQueueMetrics\Events\HealthScoreChanged;
use Cbox\LaravelQueueMetrics\Events\JobMetricsDebounced;
use Cbox\LaravelQueueMetrics\Events\QueueDepthThresholdExceeded;
use Cbox\LaravelQueueMetrics\Events\WorkerEfficiencyChanged;
use Cbox\LaravelQueueMetrics\Telemetry\TelemetryEventSubscriber;
use Cbox\Telemetry\Facades\Telemetry;
use Cbox\Telemetry\TelemetryManager;

beforeEach(function () {
    if (! class_exists(TelemetryManager::class)) {
        $this->markTestSkipped('cboxdk/laravel-telemetry is not installed (Laravel 12+ only)');
    }

    config([
        'queue-metrics.telemetry.enabled' => true,
        'queue-metrics.telemetry.events' => true,
    ]);

    $this->fake = Telemetry::fake();
    $this->subscriber = new TelemetryEventSubscriber(app());
});

it('records health score changes as a gauge and severity counter', function () {
    $this->subscriber->handleHealthScoreChanged(new HealthScoreChanged(
        connection: 'redis',
        queue: 'default',
        currentScore: 45.0,
        previousScore: 80.0,
        status: 'degraded',
    ));

    $labels = ['connection' => 'redis', 'queue' => 'default'];

    expect($this->fake->gaugeValue('queue_metrics.queue.health_score', $labels))->toBe(45.0);

    $this->fake->assertCounterIncremented('queue_metrics.health.changes', [...$labels, 'severity' => 'critical']);
    $this->fake->assertEventEmitted('queue_metrics.health.changed');
})->group('functional');

it('does not emit a health event for small score changes', function () {
    $this->subscriber->handleHealthScoreChanged(new HealthScoreChanged(
        connection: 'redis',
        queue: 'default',
        currentScore: 78.0,
        previousScore: 80.0,
        status: 'healthy',
    ));

    $this->fake->assertEventNotEmitted('queue_metrics.health.changed');
})->group('functional');

it('records queue depth threshold breaches as a counter and event', function () {
    $this->subscriber->handleQueueDepthThresholdExceeded(new QueueDepthThresholdExceeded(
        depth: new QueueDepthData(
            connection: 'redis',
            queue: 'default',
            pendingJobs: 1500,
            reservedJobs: 10,
            delayedJobs: 5,
            oldestPendingJobAge: Carbon::now()->subMinutes(10),
            oldestDelayedJobAge: null,
            measuredAt: Carbon::now(),
        ),
        threshold: 1000,
        percentageOver: 50.0,
    ));

    $labels = ['connection' => 'redis', 'queue' => 'default'];

    $this->fake->assertCounterIncremented('queue_metrics.queue.depth_threshold.exceeded', $labels);
    $this->fake->assertEventEmitted('queue_metrics.queue.depth_threshold.exceeded');
})->group('functional');

it('counts debounced jobs', function () {
    $this->subscriber->handleJobMetricsDebounced(new JobMetricsDebounced(
        jobId: 'job-1',
        jobClass: 'App\\Jobs\\SendEmail',
        connection: 'redis',
        queue: 'default',
    ));

    $this->fake->assertCounterIncremented('queue_metrics.jobs.debounced', [
        'job.name' => 'App\\Jobs\\SendEmail',
        'queue' => 'default',
    ]);
})->group('functional');

it('records worker efficiency and emits scaling recommendations', function () {
    $this->subscriber->handleWorkerEfficiencyChanged(new WorkerEfficiencyChanged(
        currentEfficiency: 95.0,
        previousEfficiency: 70.0,
        changePercentage: 35.7,
        activeWorkers: 8,
        idleWorkers: 0,
    ));

    expect($this->fake->gaugeValue('queue_metrics.workers.efficiency'))->toBe(95.0);

    $this->fake->assertEventEmitted('queue_metrics.workers.scaling_recommendation', function ($event) {
        return $event->attributes['recommendation'] === 'scale_up';
    });
})->group('functional');

it('does not emit a scaling event when the recommendation is maintain', function () {
    $this->subscriber->handleWorkerEfficiencyChanged(new WorkerEfficiencyChanged(
        currentEfficiency: 70.0,
        previousEfficiency: 72.0,
        changePercentage: -2.8,
        activeWorkers: 4,
        idleWorkers: 1,
    ));

    $this->fake->assertEventNotEmitted('queue_metrics.workers.scaling_recommendation');
})->group('functional');

it('counts baseline recalculations with significance', function () {
    $this->subscriber->handleBaselineRecalculated(new BaselineRecalculated(
        connection: 'redis',
        queue: 'default',
        baseline: new BaselineData(
            connection: 'redis',
            queue: 'default',
            jobClass: '',
            cpuPercentPerJob: 10.0,
            memoryMbPerJob: 32.0,
            avgDurationMs: 100.0,
            sampleCount: 50,
            confidenceScore: 0.8,
            calculatedAt: Carbon::now(),
        ),
        significantChange: true,
    ));

    $this->fake->assertCounterIncremented('queue_metrics.baseline.recalculations', [
        'connection' => 'redis',
        'queue' => 'default',
        'significant' => 'true',
    ]);
})->group('functional');

it('records nothing when the events toggle is disabled', function () {
    config(['queue-metrics.telemetry.events' => false]);

    $this->subscriber->handleHealthScoreChanged(new HealthScoreChanged(
        connection: 'redis',
        queue: 'default',
        currentScore: 45.0,
        previousScore: 80.0,
        status: 'degraded',
    ));

    $this->fake->assertCounterNotIncremented('queue_metrics.health.changes');
})->group('functional');

it('never lets telemetry failures propagate into the dispatching process', function () {
    $throwing = Mockery::mock(TelemetryManager::class);
    $throwing->shouldReceive('gauge', 'counter', 'event', 'flush')
        ->andThrow(new RuntimeException('telemetry backend down'));

    app()->instance(TelemetryManager::class, $throwing);

    $this->subscriber->handleHealthScoreChanged(new HealthScoreChanged(
        connection: 'redis',
        queue: 'default',
        currentScore: 45.0,
        previousScore: 80.0,
        status: 'degraded',
    ));

    $this->subscriber->handleJobMetricsDebounced(new JobMetricsDebounced(
        jobId: 'job-1',
        jobClass: 'App\\Jobs\\SendEmail',
        connection: 'redis',
        queue: 'default',
    ));

    expect(true)->toBeTrue();
})->group('functional');

it('maps package events to handler methods', function () {
    $subscriptions = $this->subscriber->subscribe();

    expect($subscriptions)->toHaveKeys([
        HealthScoreChanged::class,
        QueueDepthThresholdExceeded::class,
        JobMetricsDebounced::class,
        WorkerEfficiencyChanged::class,
        BaselineRecalculated::class,
    ]);
})->group('functional');
