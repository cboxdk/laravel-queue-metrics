<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueMetrics\Support;

use Cbox\LaravelQueueMetrics\Utilities\MemoryConverter;
use Cbox\SystemMetrics\ProcessMetrics;

/**
 * Collects process-level metrics for a completed or failed job.
 *
 * Extracts duration, memory, and CPU deltas in a single place,
 * ensuring snapshot caches are always cleaned up (even on failure).
 *
 * @internal
 */
final class JobMetricsCollector
{
    /**
     * Collect metrics for a job that has just finished processing.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function collect(string $jobId, array $payload): JobMetricsSnapshot
    {
        $startTime = is_numeric($payload['pushedAt'] ?? null) ? (float) $payload['pushedAt'] : microtime(true);
        $durationMs = max(0.0, (microtime(true) - $startTime) * 1000);

        $trackerId = "job_{$jobId}";

        $memoryMb = MemoryConverter::bytesToMegabytes(memory_get_peak_usage(true));
        $memoryIncrementalMb = 0.0;
        $cpuTimeMs = 0.0;

        try {
            $metricsResult = ProcessMetrics::stop($trackerId);

            if ($metricsResult->isSuccess()) {
                $metrics = $metricsResult->getValue();

                $peakMemoryMb = MemoryConverter::bytesToMegabytes($metrics->peak->memoryRssBytes);
                $startMemoryMb = JobMemorySnapshotCache::get($jobId);

                if ($startMemoryMb !== null) {
                    $memoryIncrementalMb = max(0.0, $peakMemoryMb - $startMemoryMb);
                }

                $memoryMb = $peakMemoryMb;

                $endCpuTimes = $metrics->current->cpuTimes;
                $endCpuTimeMs = (float) ($endCpuTimes->user + $endCpuTimes->system);
                $startCpuTimeMs = JobCpuSnapshotCache::get($jobId);

                if ($startCpuTimeMs !== null) {
                    $cpuTimeMs = max(0.0, $endCpuTimeMs - $startCpuTimeMs);
                }
            }
        } finally {
            JobCpuSnapshotCache::forget($jobId);
            JobMemorySnapshotCache::forget($jobId);
        }

        return new JobMetricsSnapshot(
            durationMs: $durationMs,
            memoryMb: $memoryMb,
            memoryIncrementalMb: $memoryIncrementalMb,
            cpuTimeMs: $cpuTimeMs,
        );
    }
}
