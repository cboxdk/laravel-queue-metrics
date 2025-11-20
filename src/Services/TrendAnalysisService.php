<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueMetrics\Services;

use Carbon\Carbon;
use PHPeek\LaravelQueueMetrics\Support\RedisMetricsStore;

/**
 * Analyzes metrics trends over time for forecasting and insights.
 */
final readonly class TrendAnalysisService
{
    public function __construct(
        private RedisMetricsStore $storage,
    ) {}

    /**
     * Analyze queue depth trend over specified time period.
     *
     * @return array<string, mixed>
     */
    public function analyzeQueueDepthTrend(
        string $connection,
        string $queue,
        int $periodSeconds = 3600,
        int $intervalSeconds = 60,
    ): array {
        $redis = $this->storage->driver();
        $now = Carbon::now();
        $startTime = $now->copy()->subSeconds($periodSeconds);

        $key = $this->storage->key('queue_depth_history', $connection, $queue);

        // Get historical data points from sorted set (timestamp as score)
        /** @var array<string> $dataPoints */
        $dataPoints = $redis->getSortedSetByScore(
            $key,
            (string) $startTime->timestamp,
            (string) $now->timestamp
        );

        if (empty($dataPoints)) {
            return [
                'available' => false,
                'message' => 'No historical data available',
            ];
        }

        // Parse data points with type safety
        $values = [];
        foreach ($dataPoints as $point) {
            $data = json_decode($point, true);
            if (! is_array($data)) {
                continue;
            }

            $timestamp = $data['timestamp'] ?? null;
            $depth = $data['depth'] ?? null;

            if (is_numeric($timestamp) && is_numeric($depth)) {
                $values[] = [
                    'timestamp' => (int) $timestamp,
                    'depth' => (int) $depth,
                ];
            }
        }

        // Calculate statistics
        $depths = array_column($values, 'depth');
        $count = count($depths);

        if ($count === 0) {
            return ['available' => false, 'message' => 'No valid data'];
        }

        $avg = array_sum($depths) / $count;
        $min = min($depths);
        $max = max($depths);

        // Calculate trend (linear regression slope)
        $trend = $this->calculateLinearTrend($values);

        // Calculate volatility (standard deviation)
        $variance = 0.0;
        foreach ($depths as $depth) {
            $variance += pow($depth - $avg, 2);
        }
        $stdDev = sqrt($variance / $count);

        // Forecast next interval
        $forecast = $this->forecastNextValue($values, $intervalSeconds);

        return [
            'available' => true,
            'connection' => $connection,
            'queue' => $queue,
            // Time window context
            'time_window' => [
                'window_seconds' => $periodSeconds,
                'window_start' => $startTime->toIso8601String(),
                'window_end' => $now->toIso8601String(),
                'analyzed_at' => $now->toIso8601String(),
                'sample_count' => $count,
                'sample_interval_seconds' => $intervalSeconds,
            ],
            // Current and historical statistics
            'statistics' => [
                'current' => end($depths) ?: 0,
                'average' => round($avg, 2),
                'min' => $min,
                'max' => $max,
                'std_dev' => round($stdDev, 2),
            ],
            // Trend direction and confidence
            'trend' => [
                'slope' => round($trend['slope'], 4),
                'direction' => $trend['slope'] > 0.1 ? 'increasing' : ($trend['slope'] < -0.1 ? 'decreasing' : 'stable'),
                'confidence' => round($trend['r_squared'], 3),
            ],
            // Forecast for next interval
            'forecast' => [
                'next_value' => round($forecast, 2),
                'next_timestamp' => $now->copy()->addSeconds($intervalSeconds)->toIso8601String(),
            ],
        ];
    }

    /**
     * Analyze job throughput trend.
     *
     * @return array<string, mixed>
     */
    public function analyzeThroughputTrend(
        string $connection,
        string $queue,
        int $periodSeconds = 3600,
    ): array {
        $redis = $this->storage->driver();
        $now = Carbon::now();
        $startTime = $now->copy()->subSeconds($periodSeconds);

        $key = $this->storage->key('throughput_history', $connection, $queue);

        /** @var array<string> $dataPoints */
        $dataPoints = $redis->getSortedSetByScore(
            $key,
            (string) $startTime->timestamp,
            (string) $now->timestamp
        );

        if (empty($dataPoints)) {
            return [
                'available' => false,
                'message' => 'No throughput data available',
            ];
        }

        $values = [];
        foreach ($dataPoints as $point) {
            $data = json_decode($point, true);
            if (! is_array($data)) {
                continue;
            }

            $timestamp = $data['timestamp'] ?? null;
            $jobsProcessed = $data['jobs_processed'] ?? null;

            if (is_numeric($timestamp) && is_numeric($jobsProcessed)) {
                $values[] = [
                    'timestamp' => (int) $timestamp,
                    'jobs_processed' => (int) $jobsProcessed,
                ];
            }
        }

        $throughputs = array_column($values, 'jobs_processed');
        $count = count($throughputs);

        if ($count === 0) {
            return ['available' => false];
        }

        $avg = array_sum($throughputs) / $count;
        $totalJobs = array_sum($throughputs);

        // Calculate jobs per minute (with defensive division by zero check)
        $jobsPerMinute = $periodSeconds > 0 ? ($totalJobs / $periodSeconds) * 60 : 0.0;

        // Trend analysis
        $trend = $this->calculateLinearTrend($values);

        return [
            'available' => true,
            'connection' => $connection,
            'queue' => $queue,
            // Time window context
            'time_window' => [
                'window_seconds' => $periodSeconds,
                'window_start' => $startTime->toIso8601String(),
                'window_end' => $now->toIso8601String(),
                'analyzed_at' => $now->toIso8601String(),
                'sample_count' => $count,
            ],
            // Throughput statistics
            'statistics' => [
                'total_jobs' => $totalJobs,
                'average_per_interval' => round($avg, 2),
                'jobs_per_minute' => round($jobsPerMinute, 2),
                'jobs_per_hour' => round($jobsPerMinute * 60, 2),
            ],
            // Trend direction
            'trend' => [
                'slope' => round($trend['slope'], 4),
                'direction' => $trend['slope'] > 0 ? 'increasing' : ($trend['slope'] < 0 ? 'decreasing' : 'stable'),
            ],
        ];
    }

    /**
     * Analyze worker efficiency trend.
     *
     * @return array<string, mixed>
     */
    public function analyzeWorkerEfficiencyTrend(int $periodSeconds = 3600): array
    {
        $redis = $this->storage->driver();
        $now = Carbon::now();
        $startTime = $now->copy()->subSeconds($periodSeconds);

        $key = $this->storage->key('worker_efficiency_history');

        /** @var array<string> $dataPoints */
        $dataPoints = $redis->getSortedSetByScore(
            $key,
            (string) $startTime->timestamp,
            (string) $now->timestamp
        );

        if (empty($dataPoints)) {
            return [
                'available' => false,
                'message' => 'No worker efficiency data available',
            ];
        }

        $values = [];
        foreach ($dataPoints as $point) {
            $data = json_decode($point, true);
            if (! is_array($data)) {
                continue;
            }

            $timestamp = $data['timestamp'] ?? null;
            $efficiency = $data['efficiency'] ?? null;
            $avgMemoryMb = $data['avg_memory_mb'] ?? null;
            $avgCpuPercent = $data['avg_cpu_percent'] ?? null;

            if (is_numeric($timestamp) && is_numeric($efficiency) && is_numeric($avgMemoryMb) && is_numeric($avgCpuPercent)) {
                $values[] = [
                    'timestamp' => (int) $timestamp,
                    'efficiency' => (float) $efficiency,
                    'avg_memory_mb' => (float) $avgMemoryMb,
                    'avg_cpu_percent' => (float) $avgCpuPercent,
                ];
            }
        }

        $efficiencies = array_column($values, 'efficiency');
        $memoryUsages = array_column($values, 'avg_memory_mb');
        $cpuUsages = array_column($values, 'avg_cpu_percent');

        $count = count($efficiencies);

        return [
            'available' => true,
            // Time window context
            'time_window' => [
                'window_seconds' => $periodSeconds,
                'window_start' => $startTime->toIso8601String(),
                'window_end' => $now->toIso8601String(),
                'analyzed_at' => $now->toIso8601String(),
                'sample_count' => $count,
            ],
            // Worker efficiency statistics
            'efficiency' => [
                'current' => round(end($efficiencies) ?: 0, 2),
                'average' => round(array_sum($efficiencies) / max($count, 1), 2),
                'min' => round(min($efficiencies), 2),
                'max' => round(max($efficiencies), 2),
            ],
            // Resource usage statistics
            'resource_usage' => [
                'avg_memory_mb' => round(array_sum($memoryUsages) / max($count, 1), 2),
                'avg_cpu_percent' => round(array_sum($cpuUsages) / max($count, 1), 2),
            ],
        ];
    }

    /**
     * Calculate linear regression trend.
     *
     * @param  array<array{timestamp: int, depth?: int, jobs_processed?: int}>  $data
     * @return array{slope: float, intercept: float, r_squared: float}
     */
    private function calculateLinearTrend(array $data): array
    {
        $n = count($data);

        if ($n < 2) {
            return ['slope' => 0.0, 'intercept' => 0.0, 'r_squared' => 0.0];
        }

        // Normalize timestamps to start from 0
        $firstTimestamp = $data[0]['timestamp'];
        $x = array_map(fn ($d) => $d['timestamp'] - $firstTimestamp, $data);
        $y = array_map(fn ($d) => $d['depth'] ?? $d['jobs_processed'] ?? 0, $data);

        $sumX = array_sum($x);
        $sumY = array_sum($y);
        $sumXY = 0.0;
        $sumX2 = 0.0;
        $sumY2 = 0.0;

        for ($i = 0; $i < $n; $i++) {
            $sumXY += $x[$i] * $y[$i];
            $sumX2 += $x[$i] * $x[$i];
            $sumY2 += $y[$i] * $y[$i];
        }

        $denominator = ($n * $sumX2 - $sumX * $sumX);

        if ($denominator == 0) {
            return ['slope' => 0.0, 'intercept' => 0.0, 'r_squared' => 0.0];
        }

        $slope = ($n * $sumXY - $sumX * $sumY) / $denominator;
        $intercept = ($sumY - $slope * $sumX) / $n;

        // Calculate R²
        $meanY = $sumY / $n;
        $ssTotal = 0.0;
        $ssResidual = 0.0;

        for ($i = 0; $i < $n; $i++) {
            $predicted = $slope * $x[$i] + $intercept;
            $ssTotal += pow($y[$i] - $meanY, 2);
            $ssResidual += pow($y[$i] - $predicted, 2);
        }

        $rSquared = $ssTotal > 0 ? 1 - ($ssResidual / $ssTotal) : 0.0;

        return [
            'slope' => $slope,
            'intercept' => $intercept,
            'r_squared' => max(0.0, min(1.0, $rSquared)),
        ];
    }

    /**
     * Forecast next value using linear extrapolation.
     *
     * @param  array<array{timestamp: int, depth?: int}>  $data
     */
    private function forecastNextValue(array $data, int $intervalSeconds): float
    {
        if (count($data) < 2) {
            return $data[0]['depth'] ?? 0;
        }

        $trend = $this->calculateLinearTrend($data);
        $lastPoint = end($data);
        $lastTimestamp = $lastPoint['timestamp'];
        $firstTimestamp = $data[0]['timestamp'];

        $nextTimestampOffset = ($lastTimestamp - $firstTimestamp) + $intervalSeconds;
        $forecast = $trend['slope'] * $nextTimestampOffset + $trend['intercept'];

        // Ensure non-negative forecast
        return max(0.0, $forecast);
    }
}
