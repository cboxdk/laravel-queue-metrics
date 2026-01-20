<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueMetrics\Services;

use Cbox\SystemMetrics\SystemMetrics;

/**
 * Service for collecting server-wide resource metrics.
 */
final class ServerMetricsService
{
    /** @var array<string, mixed>|null */
    private static ?array $cachedMetrics = null;

    private static ?int $cacheTimestamp = null;

    private const CACHE_TTL_SECONDS = 5; // Cache CPU metrics for 5 seconds

    /**
     * Get current server resource limits and usage (with caching for performance).
     *
     * Returns system limits and current usage with 5-second cache to avoid
     * macOS CPU polling performance issues (2+ seconds per call).
     *
     * Includes:
     * - Memory: total, used, available, usage_percent (always fresh, fast)
     * - CPU: cores, usage_percent (cached for 5 seconds to avoid slow polling)
     *
     * @return array<string, mixed>
     */
    public function getSystemLimits(): array
    {
        // Check if cached metrics are still valid (within TTL)
        $now = time();
        $cacheValid = self::$cachedMetrics !== null
            && self::$cacheTimestamp !== null
            && ($now - self::$cacheTimestamp) < self::CACHE_TTL_SECONDS;

        if ($cacheValid && self::$cachedMetrics !== null) {
            // Return cached metrics (includes CPU usage from previous call)
            return self::$cachedMetrics;
        }

        // Cache expired or not set - fetch fresh metrics
        try {
            $result = SystemMetrics::overview();

            if (! $result->isSuccess()) {
                return [
                    'available' => false,
                    'error' => 'Failed to collect system metrics',
                ];
            }

            $overview = $result->getValue();

            // CPU metrics (now 21x faster on macOS with FFI!)
            $cpuCores = $overview->cpu->coreCount();
            // Note: v1.4.0 breaking change - usagePercentage() now returns 0-100% directly
            $cpuUsagePercent = 0.0;
            if ($overview->cpu->total->total() > 0) {
                $cpuUsagePercent = ($overview->cpu->total->busy() / $overview->cpu->total->total()) * 100;
            }

            // Memory metrics (fast, always fresh)
            $memoryTotalMb = $overview->memory->totalBytes / (1024 * 1024);
            $memoryUsedMb = $overview->memory->usedBytes / (1024 * 1024);
            $memoryAvailableMb = $memoryTotalMb - $memoryUsedMb;
            $memoryUsagePercent = $overview->memory->usedPercentage();

            // Load average (new in v1.4.0 with FFI - 12x faster!)
            // Load average is a separate facade method, not part of overview
            $loadAverage = [
                '1min' => 0.0,
                '5min' => 0.0,
                '15min' => 0.0,
            ];
            try {
                $loadResult = SystemMetrics::loadAverage();
                if ($loadResult->isSuccess()) {
                    $load = $loadResult->getValue();
                    $loadAverage = [
                        '1min' => $load->oneMinute,
                        '5min' => $load->fiveMinutes,
                        '15min' => $load->fifteenMinutes,
                    ];
                }
            } catch (\Throwable $e) {
                // Load average is optional, continue with zeros if unavailable
            }

            $metrics = [
                'available' => true,
                'cpu' => [
                    'cores' => $cpuCores,
                    'usage_percent' => round($cpuUsagePercent, 2),
                    'load_average' => $loadAverage,
                ],
                'memory' => [
                    'total_mb' => round($memoryTotalMb, 2),
                    'used_mb' => round($memoryUsedMb, 2),
                    'available_mb' => round($memoryAvailableMb, 2),
                    'usage_percent' => round($memoryUsagePercent, 2),
                ],
            ];

            // Cache the metrics
            self::$cachedMetrics = $metrics;
            self::$cacheTimestamp = $now;

            return $metrics;
        } catch (\Throwable $e) {
            return [
                'available' => false,
                'error' => 'Exception collecting system metrics: '.$e->getMessage(),
            ];
        }
    }

    /**
     * Get current server resource metrics (including usage).
     *
     * WARNING: On macOS, CPU usage calculation can be slow (1-2 seconds).
     * Consider using getSystemLimits() for overview/dashboard endpoints.
     *
     * @return array<string, mixed>
     */
    public function getCurrentMetrics(): array
    {
        try {
            $result = SystemMetrics::overview();

            if (! $result->isSuccess()) {
                $error = $result->getError();

                return [
                    'available' => false,
                    'error' => 'Failed to collect system metrics: '.($error?->getMessage() ?? 'Unknown error'),
                ];
            }

            $overview = $result->getValue();

            // CPU metrics (raw counters, calculate usage percentage)
            $cpuTotal = $overview->cpu->total;
            $busyTime = $cpuTotal->busy();
            $totalTime = $cpuTotal->total();
            $cpuUsagePercent = $totalTime > 0 ? ($busyTime / $totalTime) * 100 : 0;

            // Memory metrics (bytes to MB)
            $memoryUsageMb = $overview->memory->usedBytes / (1024 * 1024);
            $memoryTotalMb = $overview->memory->totalBytes / (1024 * 1024);
            $memoryUsagePercent = $overview->memory->usedPercentage();

            return [
                'available' => true,
                'cpu' => [
                    'usage_percent' => round($cpuUsagePercent, 2),
                    'count' => $overview->cpu->coreCount(),
                    'load_average' => [
                        '1min' => 0.0, // Not available in v1.0
                        '5min' => 0.0,
                        '15min' => 0.0,
                    ],
                ],
                'memory' => [
                    'usage_percent' => round($memoryUsagePercent, 2),
                    'total_mb' => round($memoryTotalMb, 2),
                    'used_mb' => round($memoryUsageMb, 2),
                    'available_mb' => round($memoryTotalMb - $memoryUsageMb, 2),
                ],
                'disk' => [], // Disk metrics not available in v1.0 overview
            ];
        } catch (\Throwable $e) {
            return [
                'available' => false,
                'error' => 'Exception collecting system metrics: '.$e->getMessage(),
            ];
        }
    }

    /**
     * Get server health status based on resource thresholds.
     *
     * @return array<string, mixed>
     */
    public function getHealthStatus(): array
    {
        $metrics = $this->getCurrentMetrics();

        if (! $metrics['available']) {
            return [
                'status' => 'unknown',
                'score' => 0,
                'issues' => ['Unable to collect system metrics'],
            ];
        }

        $issues = [];
        $score = 100;

        // Type-safe extraction with guards
        /** @var array{usage_percent: float, count: int, load_average: array{'1min': float, '5min': float, '15min': float}} $cpu */
        $cpu = $metrics['cpu'];
        /** @var array{usage_percent: float} $memory */
        $memory = $metrics['memory'];
        /** @var array<array{mountpoint: string, usage_percent: float}> $disks */
        $disks = $metrics['disk'];

        // CPU health check
        $cpuUsage = $cpu['usage_percent'];
        if ($cpuUsage > 90) {
            $issues[] = sprintf('Critical CPU usage: %.1f%%', $cpuUsage);
            $score -= 40;
        } elseif ($cpuUsage > 70) {
            $issues[] = sprintf('High CPU usage: %.1f%%', $cpuUsage);
            $score -= 20;
        }

        // Memory health check
        $memoryUsage = $memory['usage_percent'];
        if ($memoryUsage > 90) {
            $issues[] = sprintf('Critical memory usage: %.1f%%', $memoryUsage);
            $score -= 40;
        } elseif ($memoryUsage > 80) {
            $issues[] = sprintf('High memory usage: %.1f%%', $memoryUsage);
            $score -= 20;
        }

        // Disk health check (check most critical mount)
        foreach ($disks as $disk) {
            $diskUsage = $disk['usage_percent'];
            $mountpoint = $disk['mountpoint'];

            if ($diskUsage > 95) {
                $issues[] = sprintf('Critical disk space on %s: %.1f%%', $mountpoint, $diskUsage);
                $score -= 30;
            } elseif ($diskUsage > 85) {
                $issues[] = sprintf('Low disk space on %s: %.1f%%', $mountpoint, $diskUsage);
                $score -= 10;
            }
        }

        // Load average health check
        $cpuCount = $cpu['count'];
        $loadAvg1min = $cpu['load_average']['1min'];
        $loadPerCpu = $loadAvg1min / $cpuCount;

        if ($loadPerCpu > 2.0) {
            $issues[] = sprintf('Critical system load: %.2f (%d CPUs)', $loadAvg1min, $cpuCount);
            $score -= 30;
        } elseif ($loadPerCpu > 1.5) {
            $issues[] = sprintf('High system load: %.2f (%d CPUs)', $loadAvg1min, $cpuCount);
            $score -= 15;
        }

        $score = max(0, $score);

        $status = match (true) {
            $score >= 80 => 'healthy',
            $score >= 60 => 'degraded',
            $score >= 40 => 'warning',
            default => 'critical',
        };

        return [
            'status' => $status,
            'score' => $score,
            'issues' => $issues,
            'timestamp' => time(),
        ];
    }
}
