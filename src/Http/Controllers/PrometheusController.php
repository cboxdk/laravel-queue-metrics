<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueMetrics\Http\Controllers;

use Illuminate\Http\Response;
use PHPeek\LaravelQueueMetrics\Config\QueueMetricsConfig;
use PHPeek\LaravelQueueMetrics\Services\MetricsQueryService;
use PHPeek\LaravelQueueMetrics\Services\ServerMetricsService;
use Spatie\Prometheus\Facades\Prometheus;

/**
 * Prometheus metrics exporter using Spatie's Laravel Prometheus.
 */
final readonly class PrometheusController
{
    public function __construct(
        private MetricsQueryService $metricsQuery,
        private QueueMetricsConfig $config,
        private ServerMetricsService $serverMetrics,
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

        // Server resource metrics
        $serverMetrics = $this->serverMetrics->getCurrentMetrics();

        if ($serverMetrics['available']) {
            // Type-safe extraction
            /** @var array{usage_percent: float, load_average: array{1min: float, 5min: float, 15min: float}} $cpu */
            $cpu = $serverMetrics['cpu'];
            /** @var array{usage_percent: float, used_bytes: int, total_bytes: int} $memory */
            $memory = $serverMetrics['memory'];
            /** @var array<array{mountpoint: string, usage_percent: float, used_bytes: int}> $disks */
            $disks = $serverMetrics['disk'];

            // CPU metrics
            Prometheus::addGauge(
                'server_cpu_usage_percent',
                $cpu['usage_percent'],
                null,
                $namespace,
                'Server CPU usage percentage'
            );

            Prometheus::addGauge(
                'server_cpu_load_1min',
                $cpu['load_average']['1min'],
                null,
                $namespace,
                'Server CPU load average (1 minute)'
            );

            Prometheus::addGauge(
                'server_cpu_load_5min',
                $cpu['load_average']['5min'],
                null,
                $namespace,
                'Server CPU load average (5 minutes)'
            );

            Prometheus::addGauge(
                'server_cpu_load_15min',
                $cpu['load_average']['15min'],
                null,
                $namespace,
                'Server CPU load average (15 minutes)'
            );

            // Memory metrics
            Prometheus::addGauge(
                'server_memory_usage_percent',
                $memory['usage_percent'],
                null,
                $namespace,
                'Server memory usage percentage'
            );

            Prometheus::addGauge(
                'server_memory_used_bytes',
                (float) $memory['used_bytes'],
                null,
                $namespace,
                'Server memory used in bytes'
            );

            Prometheus::addGauge(
                'server_memory_total_bytes',
                (float) $memory['total_bytes'],
                null,
                $namespace,
                'Server total memory in bytes'
            );

            // Disk metrics (for each mountpoint)
            foreach ($disks as $disk) {
                Prometheus::addGauge(
                    'server_disk_usage_percent',
                    $disk['usage_percent'],
                    ['mountpoint' => $disk['mountpoint']],
                    $namespace,
                    'Server disk usage percentage by mountpoint'
                );

                Prometheus::addGauge(
                    'server_disk_used_bytes',
                    (float) $disk['used_bytes'],
                    ['mountpoint' => $disk['mountpoint']],
                    $namespace,
                    'Server disk used in bytes by mountpoint'
                );
            }
        }

        $metrics = Prometheus::renderCollectors();

        return response($metrics, 200, [
            'Content-Type' => 'text/plain; version=0.0.4; charset=utf-8',
        ]);
    }
}
