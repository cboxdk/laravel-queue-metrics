<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueMetrics\Services;

use Illuminate\Support\Facades\Cache;
use PHPeek\LaravelQueueMetrics\Config\QueueMetricsConfig;
use PHPeek\LaravelQueueMetrics\Services\Contracts\OverviewQueryInterface;
use Spatie\Prometheus\Facades\Prometheus;

/**
 * Comprehensive Prometheus metrics exporter for Laravel queue monitoring.
 *
 * Exports queue depth, job performance, worker stats, and baselines
 * with proper labels for multi-dimensional observability.
 */
final readonly class PrometheusService
{
    public function __construct(
        private readonly OverviewQueryInterface $metricsQuery,
        private readonly QueueMetricsConfig $config,
        private readonly ?ServerMetricsService $serverMetrics = null,
    ) {}

    /**
     * Export all metrics to Prometheus format.
     * Uses configurable cache TTL to prevent timeout on concurrent requests.
     *
     * @return array<string, mixed> Overview data used for metrics
     */
    public function exportMetrics(): array
    {
        $namespace = $this->config->getPrometheusNamespace();

        // Cache the overview data to prevent concurrent requests from all trying
        // to scan Redis simultaneously (configurable TTL, default 10 seconds)
        $cacheTtl = config('queue-metrics.prometheus.cache_ttl', 10);
        $cacheTtl = is_numeric($cacheTtl) ? (int) $cacheTtl : 10;

        /** @var array{queues: array<string, array<string, mixed>>, jobs: array<string, array<string, mixed>>, workers: array<string, mixed>, baselines: array<string, array<string, mixed>>} $overview */
        $overview = Cache::remember(
            'queue_metrics:prometheus:overview',
            now()->addSeconds($cacheTtl),
            fn () => $this->metricsQuery->getOverview()
        );

        // Export all metric categories
        $this->exportQueueMetrics($overview['queues'], $namespace);
        $this->exportJobMetrics($overview['jobs'], $namespace);
        $this->exportWorkerMetrics($overview['workers'], $namespace);
        $this->exportBaselineMetrics($overview['baselines'], $namespace);
        $this->exportServerMetrics($namespace);
        $this->exportTrendMetrics($overview['trends'] ?? [], $namespace);

        return $overview;
    }

    /**
     * Export queue-level metrics with labels (queue, connection).
     *
     * @param  array<string, array<string, mixed>>  $queues
     */
    private function exportQueueMetrics(array $queues, string $namespace): void
    {
        // Total queues count (summary metric)
        Prometheus::addGauge('queues_total')
            ->name('queues_total')
            ->namespace($namespace)
            ->helpText('Total number of active queues being monitored')
            ->value((float) count($queues));

        // Per-queue metrics with labels
        foreach ($queues as $queueData) {
            $queue = is_string($queueData['queue'] ?? null) ? $queueData['queue'] : 'unknown';
            $connection = is_string($queueData['connection'] ?? null) ? $queueData['connection'] : 'unknown';

            // Queue depth - pending
            Prometheus::addGauge('queue_depth_pending')
                ->name('queue_depth_pending')
                ->namespace($namespace)
                ->label('queue')
                ->label('connection')
                ->helpText('Number of pending jobs in queue')
                ->value($this->toFloat($queueData['pending'] ?? 0), [$queue, $connection]);

            // Queue depth - delayed
            Prometheus::addGauge('queue_depth_delayed')
                ->name('queue_depth_delayed')
                ->namespace($namespace)
                ->label('queue')
                ->label('connection')
                ->helpText('Number of delayed/scheduled jobs in queue')
                ->value($this->toFloat($queueData['scheduled'] ?? 0), [$queue, $connection]);

            // Queue depth - reserved
            Prometheus::addGauge('queue_depth_reserved')
                ->name('queue_depth_reserved')
                ->namespace($namespace)
                ->label('queue')
                ->label('connection')
                ->helpText('Number of reserved (processing) jobs in queue')
                ->value($this->toFloat($queueData['reserved'] ?? 0), [$queue, $connection]);

            // Total queue size
            Prometheus::addGauge('queue_depth_total')
                ->name('queue_depth_total')
                ->namespace($namespace)
                ->label('queue')
                ->label('connection')
                ->helpText('Total queue depth (pending + scheduled + reserved)')
                ->value($this->toFloat($queueData['depth'] ?? 0), [$queue, $connection]);

            // Backlog age
            Prometheus::addGauge('queue_oldest_job_seconds')
                ->name('queue_oldest_job_seconds')
                ->namespace($namespace)
                ->label('queue')
                ->label('connection')
                ->helpText('Age of oldest pending job in seconds')
                ->value($this->toFloat($queueData['oldest_job_age_seconds'] ?? 0), [$queue, $connection]);

            // Throughput
            Prometheus::addGauge('queue_throughput_per_minute')
                ->name('queue_throughput_per_minute')
                ->namespace($namespace)
                ->label('queue')
                ->label('connection')
                ->helpText('Jobs processed per minute for this queue')
                ->value($this->toFloat($queueData['throughput_per_minute'] ?? 0), [$queue, $connection]);

            // Failure rate
            Prometheus::addGauge('queue_failure_rate_percent')
                ->name('queue_failure_rate_percent')
                ->namespace($namespace)
                ->label('queue')
                ->label('connection')
                ->helpText('Job failure rate percentage for this queue')
                ->value($this->toFloat($queueData['failure_rate'] ?? 0), [$queue, $connection]);

            // Worker utilization
            Prometheus::addGauge('queue_utilization_rate_percent')
                ->name('queue_utilization_rate_percent')
                ->namespace($namespace)
                ->label('queue')
                ->label('connection')
                ->helpText('Worker utilization rate percentage for this queue')
                ->value($this->toFloat($queueData['utilization_rate'] ?? 0), [$queue, $connection]);
        }
    }

    /**
     * Export job-level metrics with labels (job, queue, connection).
     *
     * @param  array<string, array<string, mixed>>  $jobs
     */
    private function exportJobMetrics(array $jobs, string $namespace): void
    {
        $totalProcessed = 0;
        $totalFailed = 0;

        foreach ($jobs as $jobClass => $jobData) {
            $queue = is_string($jobData['queue'] ?? null) ? $jobData['queue'] : 'unknown';
            $connection = is_string($jobData['connection'] ?? null) ? $jobData['connection'] : 'unknown';
            $job = $jobClass;

            /** @var array{avg: float, min: float, max: float, p50: float, p95: float, p99: float, stddev: float}|null $duration */
            $duration = $jobData['duration'] ?? null;

            /** @var array{avg: float, min: float, max: float, peak: float, p95: float, p99: float}|null $memory */
            $memory = $jobData['memory'] ?? null;

            /** @var array{success_count: int, failure_count: int, success_rate: float, failure_rate: float}|null $execution */
            $execution = $jobData['execution'] ?? null;

            /** @var array{per_minute: float, per_hour: float, per_day: float}|null $throughput */
            $throughput = $jobData['throughput'] ?? null;

            // Duration metrics (converted to seconds for Prometheus convention)
            if ($duration !== null && isset($duration['avg'], $duration['p50'], $duration['p95'], $duration['p99'], $duration['max'])) {
                $durationSeconds = $duration['avg'] / 1000.0;

                // P50 percentile
                Prometheus::addGauge('job_duration_p50_seconds')
                    ->name('job_duration_p50_seconds')
                    ->namespace($namespace)
                    ->label('job')
                    ->label('queue')
                    ->label('connection')
                    ->helpText('Job execution duration 50th percentile (median)')
                    ->value($duration['p50'] / 1000.0, [$job, $queue, $connection]);

                // P95 percentile
                Prometheus::addGauge('job_duration_p95_seconds')
                    ->name('job_duration_p95_seconds')
                    ->namespace($namespace)
                    ->label('job')
                    ->label('queue')
                    ->label('connection')
                    ->helpText('Job execution duration 95th percentile')
                    ->value($duration['p95'] / 1000.0, [$job, $queue, $connection]);

                // P99 percentile
                Prometheus::addGauge('job_duration_p99_seconds')
                    ->name('job_duration_p99_seconds')
                    ->namespace($namespace)
                    ->label('job')
                    ->label('queue')
                    ->label('connection')
                    ->helpText('Job execution duration 99th percentile')
                    ->value($duration['p99'] / 1000.0, [$job, $queue, $connection]);

                // Max duration
                Prometheus::addGauge('job_duration_max_seconds')
                    ->name('job_duration_max_seconds')
                    ->namespace($namespace)
                    ->label('job')
                    ->label('queue')
                    ->label('connection')
                    ->helpText('Job execution duration maximum observed')
                    ->value($duration['max'] / 1000.0, [$job, $queue, $connection]);
            }

            // Memory metrics
            if ($memory !== null && isset($memory['peak'], $memory['p95'], $memory['p99'])) {
                // Peak memory gauge
                Prometheus::addGauge('job_memory_peak_megabytes')
                    ->name('job_memory_peak_megabytes')
                    ->namespace($namespace)
                    ->label('job')
                    ->label('queue')
                    ->label('connection')
                    ->helpText('Job peak memory usage in megabytes')
                    ->value($memory['peak'], [$job, $queue, $connection]);

                // P95 percentile
                Prometheus::addGauge('job_memory_p95_megabytes')
                    ->name('job_memory_p95_megabytes')
                    ->namespace($namespace)
                    ->label('job')
                    ->label('queue')
                    ->label('connection')
                    ->helpText('Job memory usage 95th percentile')
                    ->value($memory['p95'], [$job, $queue, $connection]);

                // P99 percentile
                Prometheus::addGauge('job_memory_p99_megabytes')
                    ->name('job_memory_p99_megabytes')
                    ->namespace($namespace)
                    ->label('job')
                    ->label('queue')
                    ->label('connection')
                    ->helpText('Job memory usage 99th percentile')
                    ->value($memory['p99'], [$job, $queue, $connection]);
            }

            // Execution counters (cumulative metrics)
            if ($execution !== null && isset($execution['success_count'], $execution['failure_count'])) {
                $successCount = $execution['success_count'];
                $failureCount = $execution['failure_count'];

                $totalProcessed += $successCount;
                $totalFailed += $failureCount;

                Prometheus::addCounter('job_processed_total')
                    ->name('job_processed_total')
                    ->namespace($namespace)
                    ->label('job')
                    ->label('queue')
                    ->label('connection')
                    ->helpText('Total number of successfully processed jobs')
                    ->setInitialValue($successCount, [$job, $queue, $connection]);

                Prometheus::addCounter('job_failed_total')
                    ->name('job_failed_total')
                    ->namespace($namespace)
                    ->label('job')
                    ->label('queue')
                    ->label('connection')
                    ->helpText('Total number of failed jobs')
                    ->setInitialValue($failureCount, [$job, $queue, $connection]);

                // Success and failure rates (percentage)
                Prometheus::addGauge('job_success_rate_percent')
                    ->name('job_success_rate_percent')
                    ->namespace($namespace)
                    ->label('job')
                    ->label('queue')
                    ->label('connection')
                    ->helpText('Job success rate percentage')
                    ->value($execution['success_rate'] * 100, [$job, $queue, $connection]);

                Prometheus::addGauge('job_failure_rate_percent')
                    ->name('job_failure_rate_percent')
                    ->namespace($namespace)
                    ->label('job')
                    ->label('queue')
                    ->label('connection')
                    ->helpText('Job failure rate percentage')
                    ->value($execution['failure_rate'] * 100, [$job, $queue, $connection]);
            }

            // Throughput metrics
            if ($throughput !== null && isset($throughput['per_minute'], $throughput['per_hour'])) {
                Prometheus::addGauge('job_throughput_per_minute')
                    ->name('job_throughput_per_minute')
                    ->namespace($namespace)
                    ->label('job')
                    ->label('queue')
                    ->label('connection')
                    ->helpText('Job processing rate per minute')
                    ->value($throughput['per_minute'], [$job, $queue, $connection]);

                Prometheus::addGauge('job_throughput_per_hour')
                    ->name('job_throughput_per_hour')
                    ->namespace($namespace)
                    ->label('job')
                    ->label('queue')
                    ->label('connection')
                    ->helpText('Job processing rate per hour')
                    ->value($throughput['per_hour'], [$job, $queue, $connection]);
            }
        }

        // Global aggregated counters (no labels for total counts)
        Prometheus::addCounter('jobs_processed_total')
            ->name('jobs_processed_total')
            ->namespace($namespace)
            ->helpText('Total number of jobs processed across all queues')
            ->setInitialValue($totalProcessed);

        Prometheus::addCounter('jobs_failed_total')
            ->name('jobs_failed_total')
            ->namespace($namespace)
            ->helpText('Total number of jobs failed across all queues')
            ->setInitialValue($totalFailed);
    }

    /**
     * Export worker metrics.
     *
     * @param  array<string, mixed>  $workers
     */
    private function exportWorkerMetrics(array $workers, string $namespace): void
    {
        Prometheus::addGauge('worker_count_total')
            ->name('worker_count_total')
            ->namespace($namespace)
            ->helpText('Total number of queue workers')
            ->value($this->toFloat($workers['total'] ?? 0));

        Prometheus::addGauge('worker_count_active')
            ->name('worker_count_active')
            ->namespace($namespace)
            ->helpText('Number of active queue workers')
            ->value($this->toFloat($workers['active'] ?? 0));

        Prometheus::addGauge('worker_count_idle')
            ->name('worker_count_idle')
            ->namespace($namespace)
            ->helpText('Number of idle queue workers')
            ->value($this->toFloat($workers['idle'] ?? 0));

        // Worker utilization (average idle percentage inverted = utilization)
        $avgIdlePercent = $this->toFloat($workers['avg_idle_percentage'] ?? 0);
        $utilizationPercent = 100.0 - $avgIdlePercent;

        Prometheus::addGauge('worker_utilization_percent')
            ->name('worker_utilization_percent')
            ->namespace($namespace)
            ->helpText('Average worker utilization percentage')
            ->value($utilizationPercent);

        Prometheus::addGauge('worker_idle_percent')
            ->name('worker_idle_percent')
            ->namespace($namespace)
            ->helpText('Average worker idle percentage')
            ->value($avgIdlePercent);

        // Total jobs processed by all workers
        Prometheus::addCounter('worker_jobs_processed_total')
            ->name('worker_jobs_processed_total')
            ->namespace($namespace)
            ->helpText('Total jobs processed by all workers')
            ->setInitialValue($this->toFloat($workers['total_jobs_processed'] ?? 0));
    }

    /**
     * Export baseline metrics with labels (queue, connection, job).
     *
     * @param  array<string, array<string, mixed>>  $baselines
     */
    private function exportBaselineMetrics(array $baselines, string $namespace): void
    {
        foreach ($baselines as $baselineKey => $baseline) {
            $queue = is_string($baseline['queue'] ?? null) ? $baseline['queue'] : 'unknown';
            $connection = is_string($baseline['connection'] ?? null) ? $baseline['connection'] : 'unknown';
            $job = isset($baseline['job_class']) && is_string($baseline['job_class']) && $baseline['job_class'] !== ''
                ? $baseline['job_class']
                : '';

            $labels = $job !== '' ? [$queue, $connection, $job] : [$queue, $connection];
            $labelNames = $job !== '' ? ['queue', 'connection', 'job'] : ['queue', 'connection'];

            // CPU usage per job
            $cpuGauge = Prometheus::addGauge('baseline_cpu_percent_per_job')
                ->name('baseline_cpu_percent_per_job')
                ->namespace($namespace)
                ->helpText('Baseline CPU usage percentage per job');

            foreach ($labelNames as $labelName) {
                $cpuGauge->label($labelName);
            }

            $cpuGauge->value($this->toFloat($baseline['cpu_percent_per_job'] ?? 0), $labels);

            // Memory usage per job
            $memoryGauge = Prometheus::addGauge('baseline_memory_mb_per_job')
                ->name('baseline_memory_mb_per_job')
                ->namespace($namespace)
                ->helpText('Baseline memory usage in MB per job');

            foreach ($labelNames as $labelName) {
                $memoryGauge->label($labelName);
            }

            $memoryGauge->value($this->toFloat($baseline['memory_mb_per_job'] ?? 0), $labels);

            // Average duration
            $durationGauge = Prometheus::addGauge('baseline_duration_ms')
                ->name('baseline_duration_ms')
                ->namespace($namespace)
                ->helpText('Baseline average job duration in milliseconds');

            foreach ($labelNames as $labelName) {
                $durationGauge->label($labelName);
            }

            $durationGauge->value($this->toFloat($baseline['avg_duration_ms'] ?? 0), $labels);

            // Confidence score
            $confidenceGauge = Prometheus::addGauge('baseline_confidence_score')
                ->name('baseline_confidence_score')
                ->namespace($namespace)
                ->helpText('Baseline confidence score (0-1, higher is more reliable)');

            foreach ($labelNames as $labelName) {
                $confidenceGauge->label($labelName);
            }

            $confidenceGauge->value($this->toFloat($baseline['confidence_score'] ?? 0), $labels);

            // Sample count
            $sampleGauge = Prometheus::addGauge('baseline_sample_count')
                ->name('baseline_sample_count')
                ->namespace($namespace)
                ->helpText('Number of samples used for baseline calculation');

            foreach ($labelNames as $labelName) {
                $sampleGauge->label($labelName);
            }

            $sampleGauge->value($this->toFloat($baseline['sample_count'] ?? 0), $labels);
        }
    }

    /**
     * Convert mixed value to float with type safety.
     */
    private function toFloat(mixed $value): float
    {
        if (is_numeric($value)) {
            return (float) $value;
        }

        return 0.0;
    }

    /**
     * Export server resource metrics (CPU, memory, disk) if available.
     */
    private function exportServerMetrics(string $namespace): void
    {
        if ($this->serverMetrics === null) {
            return;
        }

        try {
            $serverMetrics = $this->serverMetrics->getCurrentMetrics();
        } catch (\Throwable $e) {
            logger()->warning('Failed to retrieve server metrics', [
                'error' => $e->getMessage(),
            ]);

            return;
        }

        if (! ($serverMetrics['available'] ?? false)) {
            return;
        }

        /** @var array{usage_percent: float, load_average: array{'1min': float, '5min': float, '15min': float}} $cpu */
        $cpu = $serverMetrics['cpu'];

        /** @var array{usage_percent: float, used_bytes: int, total_bytes: int} $memory */
        $memory = $serverMetrics['memory'];

        /** @var array<array{mountpoint: string, usage_percent: float, used_bytes: int}> $disks */
        $disks = $serverMetrics['disk'];

        // CPU metrics
        Prometheus::addGauge('server_cpu_usage_percent')
            ->name('server_cpu_usage_percent')
            ->namespace($namespace)
            ->helpText('Server CPU usage percentage')
            ->value($cpu['usage_percent']);

        Prometheus::addGauge('server_cpu_load_1min')
            ->name('server_cpu_load_1min')
            ->namespace($namespace)
            ->helpText('Server CPU load average (1 minute)')
            ->value($cpu['load_average']['1min']);

        Prometheus::addGauge('server_cpu_load_5min')
            ->name('server_cpu_load_5min')
            ->namespace($namespace)
            ->helpText('Server CPU load average (5 minutes)')
            ->value($cpu['load_average']['5min']);

        Prometheus::addGauge('server_cpu_load_15min')
            ->name('server_cpu_load_15min')
            ->namespace($namespace)
            ->helpText('Server CPU load average (15 minutes)')
            ->value($cpu['load_average']['15min']);

        // Memory metrics
        Prometheus::addGauge('server_memory_usage_percent')
            ->name('server_memory_usage_percent')
            ->namespace($namespace)
            ->helpText('Server memory usage percentage')
            ->value($memory['usage_percent']);

        Prometheus::addGauge('server_memory_used_bytes')
            ->name('server_memory_used_bytes')
            ->namespace($namespace)
            ->helpText('Server memory used in bytes')
            ->value((float) $memory['used_bytes']);

        Prometheus::addGauge('server_memory_total_bytes')
            ->name('server_memory_total_bytes')
            ->namespace($namespace)
            ->helpText('Server total memory in bytes')
            ->value((float) $memory['total_bytes']);

        // Disk metrics (per mountpoint)
        foreach ($disks as $disk) {
            Prometheus::addGauge('server_disk_usage_percent')
                ->name('server_disk_usage_percent')
                ->namespace($namespace)
                ->label('mountpoint')
                ->helpText('Server disk usage percentage by mountpoint')
                ->value($disk['usage_percent'], [$disk['mountpoint']]);

            Prometheus::addGauge('server_disk_used_bytes')
                ->name('server_disk_used_bytes')
                ->namespace($namespace)
                ->label('mountpoint')
                ->helpText('Server disk used in bytes by mountpoint')
                ->value((float) $disk['used_bytes'], [$disk['mountpoint']]);
        }
    }

    /**
     * Export trend metrics (queue depth trends and worker efficiency trends).
     *
     * @param  array<string, mixed>  $trends
     */
    private function exportTrendMetrics(array $trends, string $namespace): void
    {
        // Queue depth trends (now an array of trends per queue)
        if (isset($trends['queue_depth']) && is_array($trends['queue_depth'])) {
            foreach ($trends['queue_depth'] as $queueKey => $queueDepth) {
                if (! is_array($queueDepth) || ($queueDepth['available'] ?? false) !== true) {
                    continue;
                }

                $connection = $queueDepth['connection'] ?? 'unknown';
                $queue = $queueDepth['queue'] ?? 'unknown';
                $labels = [$connection, $queue];

                // Current queue depth
                if (isset($queueDepth['statistics']['current'])) {
                    Prometheus::addGauge('queue_depth_trend_current')
                        ->name('queue_depth_trend_current')
                        ->namespace($namespace)
                        ->labels(['connection', 'queue'])
                        ->helpText('Current queue depth from trend analysis')
                        ->value((float) $queueDepth['statistics']['current'], $labels);
                }

                // Average queue depth
                if (isset($queueDepth['statistics']['average'])) {
                    Prometheus::addGauge('queue_depth_trend_average')
                        ->name('queue_depth_trend_average')
                        ->namespace($namespace)
                        ->labels(['connection', 'queue'])
                        ->helpText('Average queue depth over trend period')
                        ->value((float) $queueDepth['statistics']['average'], $labels);
                }

                // Min queue depth
                if (isset($queueDepth['statistics']['min'])) {
                    Prometheus::addGauge('queue_depth_trend_min')
                        ->name('queue_depth_trend_min')
                        ->namespace($namespace)
                        ->labels(['connection', 'queue'])
                        ->helpText('Minimum queue depth over trend period')
                        ->value((float) $queueDepth['statistics']['min'], $labels);
                }

                // Max queue depth
                if (isset($queueDepth['statistics']['max'])) {
                    Prometheus::addGauge('queue_depth_trend_max')
                        ->name('queue_depth_trend_max')
                        ->namespace($namespace)
                        ->labels(['connection', 'queue'])
                        ->helpText('Maximum queue depth over trend period')
                        ->value((float) $queueDepth['statistics']['max'], $labels);
                }

                // Standard deviation
                if (isset($queueDepth['statistics']['std_dev'])) {
                    Prometheus::addGauge('queue_depth_trend_stddev')
                        ->name('queue_depth_trend_stddev')
                        ->namespace($namespace)
                        ->labels(['connection', 'queue'])
                        ->helpText('Standard deviation of queue depth')
                        ->value((float) $queueDepth['statistics']['std_dev'], $labels);
                }

                // Trend slope (rate of change)
                if (isset($queueDepth['trend']['slope'])) {
                    Prometheus::addGauge('queue_depth_trend_slope')
                        ->name('queue_depth_trend_slope')
                        ->namespace($namespace)
                        ->labels(['connection', 'queue'])
                        ->helpText('Queue depth trend slope (rate of change)')
                        ->value((float) $queueDepth['trend']['slope'], $labels);
                }

                // Trend confidence (R²)
                if (isset($queueDepth['trend']['confidence'])) {
                    Prometheus::addGauge('queue_depth_trend_confidence')
                        ->name('queue_depth_trend_confidence')
                        ->namespace($namespace)
                        ->labels(['connection', 'queue'])
                        ->helpText('Queue depth trend confidence score (R²)')
                        ->value((float) $queueDepth['trend']['confidence'], $labels);
                }

                // Forecasted next value
                if (isset($queueDepth['forecast']['next_value'])) {
                    Prometheus::addGauge('queue_depth_trend_forecast')
                        ->name('queue_depth_trend_forecast')
                        ->namespace($namespace)
                        ->labels(['connection', 'queue'])
                        ->helpText('Forecasted next queue depth value')
                        ->value((float) $queueDepth['forecast']['next_value'], $labels);
                }

                // Data points count
                if (isset($queueDepth['data_points'])) {
                    Prometheus::addGauge('queue_depth_trend_data_points')
                        ->name('queue_depth_trend_data_points')
                        ->namespace($namespace)
                        ->labels(['connection', 'queue'])
                        ->helpText('Number of data points in trend analysis')
                        ->value((float) $queueDepth['data_points'], $labels);
                }
            }
        }

        // Worker efficiency trends
        if (isset($trends['worker_efficiency']) && is_array($trends['worker_efficiency'])) {
            $efficiency = $trends['worker_efficiency'];

            if (($efficiency['available'] ?? false) === true) {
                // Current efficiency
                if (isset($efficiency['efficiency']['current'])) {
                    Prometheus::addGauge('worker_efficiency_trend_current')
                        ->name('worker_efficiency_trend_current')
                        ->namespace($namespace)
                        ->helpText('Current worker efficiency percentage')
                        ->value((float) $efficiency['efficiency']['current']);
                }

                // Average efficiency
                if (isset($efficiency['efficiency']['average'])) {
                    Prometheus::addGauge('worker_efficiency_trend_average')
                        ->name('worker_efficiency_trend_average')
                        ->namespace($namespace)
                        ->helpText('Average worker efficiency over trend period')
                        ->value((float) $efficiency['efficiency']['average']);
                }

                // Min efficiency
                if (isset($efficiency['efficiency']['min'])) {
                    Prometheus::addGauge('worker_efficiency_trend_min')
                        ->name('worker_efficiency_trend_min')
                        ->namespace($namespace)
                        ->helpText('Minimum worker efficiency over trend period')
                        ->value((float) $efficiency['efficiency']['min']);
                }

                // Max efficiency
                if (isset($efficiency['efficiency']['max'])) {
                    Prometheus::addGauge('worker_efficiency_trend_max')
                        ->name('worker_efficiency_trend_max')
                        ->namespace($namespace)
                        ->helpText('Maximum worker efficiency over trend period')
                        ->value((float) $efficiency['efficiency']['max']);
                }

                // Average memory usage
                if (isset($efficiency['resource_usage']['avg_memory_mb'])) {
                    Prometheus::addGauge('worker_efficiency_trend_memory_mb')
                        ->name('worker_efficiency_trend_memory_mb')
                        ->namespace($namespace)
                        ->helpText('Average worker memory usage in MB')
                        ->value((float) $efficiency['resource_usage']['avg_memory_mb']);
                }

                // Average CPU usage
                if (isset($efficiency['resource_usage']['avg_cpu_percent'])) {
                    Prometheus::addGauge('worker_efficiency_trend_cpu_percent')
                        ->name('worker_efficiency_trend_cpu_percent')
                        ->namespace($namespace)
                        ->helpText('Average worker CPU usage percentage')
                        ->value((float) $efficiency['resource_usage']['avg_cpu_percent']);
                }

                // Data points count
                if (isset($efficiency['data_points'])) {
                    Prometheus::addGauge('worker_efficiency_trend_data_points')
                        ->name('worker_efficiency_trend_data_points')
                        ->namespace($namespace)
                        ->helpText('Number of data points in efficiency trend analysis')
                        ->value((float) $efficiency['data_points']);
                }
            }
        }
    }
}
