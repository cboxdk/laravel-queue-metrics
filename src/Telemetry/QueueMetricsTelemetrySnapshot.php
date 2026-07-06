<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueMetrics\Telemetry;

use Cbox\LaravelQueueMetrics\DataTransferObjects\BaselineData;
use Cbox\LaravelQueueMetrics\Repositories\Contracts\BaselineRepository;
use Cbox\LaravelQueueMetrics\Services\QueueMetricsQueryService;
use Cbox\LaravelQueueMetrics\Services\WorkerMetricsQueryService;
use Cbox\LaravelQueueMetrics\Telemetry\Contracts\ProvidesTelemetrySnapshot;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Cache;

/**
 * Default snapshot source backed by the stored queue metrics.
 *
 * The snapshot is cached briefly (like the Prometheus exporter) so
 * concurrent scrapes do not all scan the metrics store at once.
 */
final class QueueMetricsTelemetrySnapshot implements ProvidesTelemetrySnapshot
{
    /**
     * @var array{queues: array<int|string, array<string, mixed>>, workers: array<string, mixed>, baselines: array<int|string, array<string, mixed>>}|null
     */
    private ?array $memo = null;

    private float $memoAt = 0.0;

    public function __construct(private readonly Container $container) {}

    /**
     * @return array{
     *     queues: array<int|string, array<string, mixed>>,
     *     workers: array<string, mixed>,
     *     baselines: array<int|string, array<string, mixed>>
     * }
     */
    public function snapshot(): array
    {
        $cacheTtl = config('queue-metrics.telemetry.cache_ttl', 10);
        $cacheTtl = is_numeric($cacheTtl) ? (int) $cacheTtl : 10;

        if ($cacheTtl <= 0) {
            // Every observable gauge callback calls snapshot() during a
            // single scrape. Even with the shared cache disabled, one
            // scrape must not rebuild per gauge — memoize briefly within
            // this process instead.
            if ($this->memo !== null && (microtime(true) - $this->memoAt) < 1.0) {
                return $this->memo;
            }

            $this->memoAt = microtime(true);

            return $this->memo = $this->build();
        }

        /** @var array{queues: array<int|string, array<string, mixed>>, workers: array<string, mixed>, baselines: array<int|string, array<string, mixed>>} */
        return Cache::remember(
            'queue_metrics:telemetry:snapshot',
            now()->addSeconds($cacheTtl),
            fn (): array => $this->build(),
        );
    }

    /**
     * @return array{
     *     queues: array<int|string, array<string, mixed>>,
     *     workers: array<string, mixed>,
     *     baselines: array<int|string, array<string, mixed>>
     * }
     */
    private function build(): array
    {
        $queueService = $this->container->make(QueueMetricsQueryService::class);
        $workerService = $this->container->make(WorkerMetricsQueryService::class);

        $queues = array_values($queueService->getAllQueuesWithMetrics());
        $workers = $workerService->getWorkersSummary();

        return [
            'queues' => $queues,
            'workers' => $workers,
            'baselines' => $this->baselines($queues),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $queues
     * @return array<int, array<string, mixed>>
     */
    private function baselines(array $queues): array
    {
        if ($queues === [] || ! config('queue-metrics.telemetry.gauges.baselines', true)) {
            return [];
        }

        $pairs = array_map(fn (array $queue): array => [
            'connection' => is_string($queue['connection'] ?? null) ? $queue['connection'] : 'default',
            'queue' => is_string($queue['queue'] ?? null) ? $queue['queue'] : 'default',
        ], $queues);

        $baselines = $this->container->make(BaselineRepository::class)->getBaselines($pairs);

        return array_values(array_map(
            fn (BaselineData $baseline): array => $baseline->toArray(),
            $baselines,
        ));
    }
}
