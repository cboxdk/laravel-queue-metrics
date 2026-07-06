<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueMetrics\Telemetry;

use Cbox\LaravelQueueMetrics\Telemetry\Contracts\ProvidesTelemetrySnapshot;
use Cbox\Telemetry\Contracts\TelemetryProvider;
use Cbox\Telemetry\Metrics\Registry;
use Illuminate\Contracts\Container\Container;

/**
 * Publishes queue-metrics state to cboxdk/laravel-telemetry as observable
 * gauges, evaluated at scrape time from the stored metrics snapshot.
 *
 * Deliberately limited to what telemetry's own QueueInstrumentation cannot
 * see (queue depth, worker state, baselines) — per-job durations, memory
 * and outcome counters are already covered by telemetry itself.
 */
final readonly class QueueMetricsTelemetryProvider implements TelemetryProvider
{
    public function __construct(private Container $container) {}

    public function name(): string
    {
        return 'cbox.queue-metrics';
    }

    public function register(Registry $registry): void
    {
        if (config('queue-metrics.telemetry.gauges.queues', true)) {
            $this->registerQueueGauges($registry);
        }

        if (config('queue-metrics.telemetry.gauges.workers', true)) {
            $this->registerWorkerGauges($registry);
        }

        if (config('queue-metrics.telemetry.gauges.baselines', true)) {
            $this->registerBaselineGauges($registry);
        }
    }

    private function registerQueueGauges(Registry $registry): void
    {
        $registry->gauge(
            'queue_metrics.queue.depth',
            fn (): array => $this->perQueue(fn (array $queue, array $labels): array => [
                [$this->toFloat($this->nested($queue, 'depth', 'pending')), [...$labels, 'state' => 'pending']],
                [$this->toFloat($this->nested($queue, 'depth', 'scheduled')), [...$labels, 'state' => 'scheduled']],
                [$this->toFloat($this->nested($queue, 'depth', 'reserved')), [...$labels, 'state' => 'reserved']],
            ]),
            description: 'Queue depth by job state',
            unit: '{jobs}',
        );

        $registry->gauge(
            'queue_metrics.queue.oldest_job.age',
            fn (): array => $this->perQueue(fn (array $queue, array $labels): array => [
                [$this->toFloat($this->nested($queue, 'depth', 'oldest_job_age_seconds')), $labels],
            ]),
            description: 'Age of the oldest pending job',
            unit: 's',
        );

        $registry->gauge(
            'queue_metrics.queue.throughput',
            fn (): array => $this->perQueue(fn (array $queue, array $labels): array => [
                [$this->toFloat($this->nested($queue, 'performance_60s', 'throughput_per_minute')), $labels],
            ]),
            description: 'Jobs processed per minute (60s window)',
            unit: '{jobs}/min',
        );

        $registry->gauge(
            'queue_metrics.queue.failure_rate',
            fn (): array => $this->perQueue(fn (array $queue, array $labels): array => [
                [$this->toFloat($this->nested($queue, 'lifetime', 'failure_rate_percent')), $labels],
            ]),
            description: 'Lifetime job failure rate',
            unit: '%',
        );

        $registry->gauge(
            'queue_metrics.queue.active_workers',
            fn (): array => $this->perQueue(fn (array $queue, array $labels): array => [
                [$this->toFloat($this->nested($queue, 'workers', 'active_count')), $labels],
            ]),
            description: 'Workers currently attached to this queue',
            unit: '{workers}',
        );
    }

    private function registerWorkerGauges(Registry $registry): void
    {
        $registry->gauge(
            'queue_metrics.workers.count',
            function (): array {
                $workers = $this->snapshot()['workers'];

                return [
                    [$this->toFloat($this->nested($workers, 'count', 'active')), ['state' => 'busy']],
                    [$this->toFloat($this->nested($workers, 'count', 'idle')), ['state' => 'idle']],
                ];
            },
            description: 'Queue workers by state',
            unit: '{workers}',
        );

        $registry->gauge(
            'queue_metrics.workers.utilization',
            function (): array {
                $workers = $this->snapshot()['workers'];

                return [
                    [$this->toFloat($this->nested($workers, 'utilization', 'current_busy_percent')), ['window' => 'current']],
                    [$this->toFloat($this->nested($workers, 'utilization', 'lifetime_busy_percent')), ['window' => 'lifetime']],
                ];
            },
            description: 'Worker busy percentage, current snapshot and lifetime',
            unit: '%',
        );
    }

    private function registerBaselineGauges(Registry $registry): void
    {
        $baselineGauges = [
            'queue_metrics.baseline.duration' => ['avg_duration_ms', 'Baseline average job duration', 'ms'],
            'queue_metrics.baseline.memory' => ['memory_mb_per_job', 'Baseline memory per job', 'MBy'],
            'queue_metrics.baseline.cpu' => ['cpu_percent_per_job', 'Baseline CPU per job', '%'],
            'queue_metrics.baseline.confidence' => ['confidence_score', 'Baseline confidence score (0-1)', '1'],
        ];

        foreach ($baselineGauges as $name => [$field, $description, $unit]) {
            $registry->gauge(
                $name,
                fn (): array => $this->perBaseline(fn (array $baseline, array $labels): array => [
                    [$this->toFloat($baseline[$field] ?? 0), $labels],
                ]),
                description: $description,
                unit: $unit,
            );
        }
    }

    /**
     * @param  callable(array<string, mixed>, array<string, string>): list<array{0: float, 1: array<string, string>}>  $samples
     * @return list<array{0: float, 1: array<string, string>}>
     */
    private function perQueue(callable $samples): array
    {
        $result = [];

        foreach ($this->snapshot()['queues'] as $queue) {
            $labels = [
                'connection' => $this->toLabel($queue['connection'] ?? 'default'),
                'queue' => $this->toLabel($queue['queue'] ?? 'default'),
            ];

            $result = [...$result, ...$samples($queue, $labels)];
        }

        return $result;
    }

    /**
     * @param  callable(array<string, mixed>, array<string, string>): list<array{0: float, 1: array<string, string>}>  $samples
     * @return list<array{0: float, 1: array<string, string>}>
     */
    private function perBaseline(callable $samples): array
    {
        $result = [];

        foreach ($this->snapshot()['baselines'] as $baseline) {
            $labels = [
                'connection' => $this->toLabel($baseline['connection'] ?? 'default'),
                'queue' => $this->toLabel($baseline['queue'] ?? 'default'),
            ];

            $jobClass = $baseline['job_class'] ?? '';

            if (is_string($jobClass) && $jobClass !== '') {
                $labels['job.name'] = $jobClass;
            }

            $result = [...$result, ...$samples($baseline, $labels)];
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function nested(array $data, string $group, string $key): mixed
    {
        $section = $data[$group] ?? null;

        return is_array($section) ? ($section[$key] ?? null) : null;
    }

    /**
     * @return array{
     *     queues: array<int|string, array<string, mixed>>,
     *     workers: array<string, mixed>,
     *     baselines: array<int|string, array<string, mixed>>
     * }
     */
    private function snapshot(): array
    {
        return $this->container->make(ProvidesTelemetrySnapshot::class)->snapshot();
    }

    private function toFloat(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }

    private function toLabel(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : 'unknown';
    }
}
