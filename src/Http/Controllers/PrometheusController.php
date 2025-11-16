<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueMetrics\Http\Controllers;

use Illuminate\Http\Response;
use PHPeek\LaravelQueueMetrics\Config\QueueMetricsConfig;
use PHPeek\LaravelQueueMetrics\Services\MetricsQueryService;
use Spatie\Prometheus\Facades\Prometheus;

/**
 * Prometheus metrics exporter using Spatie's Laravel Prometheus.
 */
final readonly class PrometheusController
{
    public function __construct(
        private MetricsQueryService $metricsQuery,
        private QueueMetricsConfig $config,
    ) {}

    public function __invoke(): Response
    {
        $namespace = $this->config->getPrometheusNamespace();

        $overview = $this->metricsQuery->getOverview();

        // Register gauges
        Prometheus::addGauge(
            'queues_total',
            (float) ($overview['total_queues'] ?? 0),
            null,
            $namespace,
            'Total number of queues'
        );

        Prometheus::addCounter(
            'jobs_processed_total',
            (int) ($overview['total_jobs_processed'] ?? 0),
            null,
            $namespace,
            'Total number of jobs processed'
        );

        Prometheus::addCounter(
            'jobs_failed_total',
            (int) ($overview['total_jobs_failed'] ?? 0),
            null,
            $namespace,
            'Total number of jobs failed'
        );

        Prometheus::addGauge(
            'workers_active',
            (float) ($overview['total_active_workers'] ?? 0),
            null,
            $namespace,
            'Number of active workers'
        );

        Prometheus::addGauge(
            'health_score',
            (float) ($overview['health_score'] ?? 100),
            null,
            $namespace,
            'Overall health score (0-100)'
        );

        $metrics = Prometheus::renderCollectors();

        return response($metrics, 200, [
            'Content-Type' => 'text/plain; version=0.0.4; charset=utf-8',
        ]);
    }
}
