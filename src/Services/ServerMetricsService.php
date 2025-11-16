<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueMetrics\Services;

use Gophpeek\SystemMetrics\SystemMetrics;

/**
 * Service for collecting server-wide resource metrics.
 */
final readonly class ServerMetricsService
{
    public function __construct(
        private SystemMetrics $systemMetrics,
    ) {}

    /**
     * Get current server resource metrics.
     *
     * @return array<string, mixed>
     */
    public function getCurrentMetrics(): array
    {
        $result = $this->systemMetrics->getMetrics();

        if ($result->isFailure()) {
            return [
                'available' => false,
                'error' => $result->getError()->message,
            ];
        }

        $metrics = $result->getValue();

        return [
            'available' => true,
            'timestamp' => time(),
            'cpu' => [
                'count' => $metrics->cpu->count,
                'usage_percent' => round($metrics->cpu->usagePercent, 2),
                'user_percent' => round($metrics->cpu->userPercent, 2),
                'system_percent' => round($metrics->cpu->systemPercent, 2),
                'idle_percent' => round($metrics->cpu->idlePercent, 2),
                'load_average' => [
                    '1min' => round($metrics->cpu->loadAverage->oneMinute, 2),
                    '5min' => round($metrics->cpu->loadAverage->fiveMinutes, 2),
                    '15min' => round($metrics->cpu->loadAverage->fifteenMinutes, 2),
                ],
            ],
            'memory' => [
                'total_bytes' => $metrics->memory->totalBytes,
                'total_mb' => round($metrics->memory->totalBytes / 1024 / 1024, 2),
                'total_gb' => round($metrics->memory->totalBytes / 1024 / 1024 / 1024, 2),
                'available_bytes' => $metrics->memory->availableBytes,
                'available_mb' => round($metrics->memory->availableBytes / 1024 / 1024, 2),
                'available_gb' => round($metrics->memory->availableBytes / 1024 / 1024 / 1024, 2),
                'used_bytes' => $metrics->memory->usedBytes,
                'used_mb' => round($metrics->memory->usedBytes / 1024 / 1024, 2),
                'used_gb' => round($metrics->memory->usedBytes / 1024 / 1024 / 1024, 2),
                'usage_percent' => round($metrics->memory->usagePercent, 2),
                'cached_bytes' => $metrics->memory->cachedBytes,
                'cached_mb' => round($metrics->memory->cachedBytes / 1024 / 1024, 2),
                'buffers_bytes' => $metrics->memory->buffersBytes,
                'buffers_mb' => round($metrics->memory->buffersBytes / 1024 / 1024, 2),
            ],
            'disk' => array_map(function ($disk) {
                return [
                    'mountpoint' => $disk->mountpoint,
                    'filesystem' => $disk->filesystem,
                    'total_bytes' => $disk->totalBytes,
                    'total_gb' => round($disk->totalBytes / 1024 / 1024 / 1024, 2),
                    'used_bytes' => $disk->usedBytes,
                    'used_gb' => round($disk->usedBytes / 1024 / 1024 / 1024, 2),
                    'available_bytes' => $disk->availableBytes,
                    'available_gb' => round($disk->availableBytes / 1024 / 1024 / 1024, 2),
                    'usage_percent' => round($disk->usagePercent, 2),
                ];
            }, $metrics->disks),
            'network' => array_map(function ($interface) {
                return [
                    'name' => $interface->name,
                    'bytes_sent' => $interface->bytesSent,
                    'bytes_received' => $interface->bytesReceived,
                    'packets_sent' => $interface->packetsSent,
                    'packets_received' => $interface->packetsReceived,
                    'errors_in' => $interface->errorsIn,
                    'errors_out' => $interface->errorsOut,
                    'drops_in' => $interface->dropsIn,
                    'drops_out' => $interface->dropsOut,
                ];
            }, $metrics->network),
        ];
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
        /** @var array{usage_percent: float, count: int, load_average: array{1min: float, 5min: float, 15min: float}} $cpu */
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
