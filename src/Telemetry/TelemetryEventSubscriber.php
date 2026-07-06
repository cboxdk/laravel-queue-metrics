<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueMetrics\Telemetry;

use Cbox\LaravelQueueMetrics\Events\BaselineRecalculated;
use Cbox\LaravelQueueMetrics\Events\HealthScoreChanged;
use Cbox\LaravelQueueMetrics\Events\JobMetricsDebounced;
use Cbox\LaravelQueueMetrics\Events\QueueDepthThresholdExceeded;
use Cbox\LaravelQueueMetrics\Events\WorkerEfficiencyChanged;
use Cbox\Telemetry\TelemetryManager;
use Illuminate\Contracts\Container\Container;

/**
 * Pushes queue-metrics domain events into cboxdk/laravel-telemetry as
 * counters, gauges and structured OTLP events.
 *
 * The manager is resolved per event so Telemetry::fake() swaps take
 * effect, and rare-but-important signals flush immediately — the
 * long-running processes dispatching them have no request terminate.
 */
final readonly class TelemetryEventSubscriber
{
    public function __construct(private Container $container) {}

    /**
     * @return array<class-string, string>
     */
    public function subscribe(): array
    {
        return [
            HealthScoreChanged::class => 'handleHealthScoreChanged',
            QueueDepthThresholdExceeded::class => 'handleQueueDepthThresholdExceeded',
            JobMetricsDebounced::class => 'handleJobMetricsDebounced',
            WorkerEfficiencyChanged::class => 'handleWorkerEfficiencyChanged',
            BaselineRecalculated::class => 'handleBaselineRecalculated',
        ];
    }

    public function handleHealthScoreChanged(HealthScoreChanged $event): void
    {
        if (! $this->enabled()) {
            return;
        }

        $telemetry = $this->telemetry();
        $labels = ['connection' => $event->connection, 'queue' => $event->queue];

        $telemetry
            ->gauge('queue_metrics.queue.health_score', description: 'Queue health score (0-100)', unit: '1')
            ->set($event->currentScore, $labels);

        $telemetry
            ->counter('queue_metrics.health.changes', 'Queue health score changes by severity')
            ->inc(1, [...$labels, 'severity' => $event->getSeverity()]);

        if (in_array($event->getSeverity(), ['warning', 'critical'], true)) {
            $telemetry->event('queue_metrics.health.changed', [
                ...$labels,
                'score' => $event->currentScore,
                'previous_score' => $event->previousScore,
                'status' => $event->status,
                'severity' => $event->getSeverity(),
            ]);

            $telemetry->flush();
        }
    }

    public function handleQueueDepthThresholdExceeded(QueueDepthThresholdExceeded $event): void
    {
        if (! $this->enabled()) {
            return;
        }

        $telemetry = $this->telemetry();
        $labels = ['connection' => $event->depth->connection, 'queue' => $event->depth->queue];

        $telemetry
            ->counter('queue_metrics.queue.depth_threshold.exceeded', 'Times a queue exceeded its depth threshold')
            ->inc(1, $labels);

        $telemetry->event('queue_metrics.queue.depth_threshold.exceeded', [
            ...$labels,
            'depth' => $event->depth->totalJobs(),
            'threshold' => $event->threshold,
            'percentage_over' => $event->percentageOver,
        ]);

        $telemetry->flush();
    }

    public function handleJobMetricsDebounced(JobMetricsDebounced $event): void
    {
        if (! $this->enabled()) {
            return;
        }

        $this->telemetry()
            ->counter('queue_metrics.jobs.debounced', 'Jobs superseded by debouncing')
            ->inc(1, ['job.name' => $event->jobClass, 'queue' => $event->queue]);
    }

    public function handleWorkerEfficiencyChanged(WorkerEfficiencyChanged $event): void
    {
        if (! $this->enabled()) {
            return;
        }

        $telemetry = $this->telemetry();

        $telemetry
            ->gauge('queue_metrics.workers.efficiency', description: 'Worker fleet efficiency', unit: '%')
            ->set($event->currentEfficiency);

        $recommendation = $event->getScalingRecommendation();

        if ($recommendation !== 'maintain') {
            $telemetry->event('queue_metrics.workers.scaling_recommendation', [
                'recommendation' => $recommendation,
                'efficiency' => $event->currentEfficiency,
                'previous_efficiency' => $event->previousEfficiency,
                'active_workers' => $event->activeWorkers,
                'idle_workers' => $event->idleWorkers,
            ]);

            $telemetry->flush();
        }
    }

    public function handleBaselineRecalculated(BaselineRecalculated $event): void
    {
        if (! $this->enabled()) {
            return;
        }

        $this->telemetry()
            ->counter('queue_metrics.baseline.recalculations', 'Baseline recalculations by significance')
            ->inc(1, [
                'connection' => $event->connection,
                'queue' => $event->queue,
                'significant' => $event->significantChange ? 'true' : 'false',
            ]);
    }

    private function enabled(): bool
    {
        return (bool) config('queue-metrics.telemetry.events', true);
    }

    private function telemetry(): TelemetryManager
    {
        return $this->container->make(TelemetryManager::class);
    }
}
