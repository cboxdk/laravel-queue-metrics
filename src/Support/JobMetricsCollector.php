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
     * Duration runs from when this worker started processing the job. A job
     * never seen starting in this process reports 0 rather than a value
     * measured from dispatch.
     */
    public static function collect(string $jobId): JobMetricsSnapshot
    {
        $now = microtime(true);
        $startTime = JobStartTimeCache::get($jobId) ?? $now;
        $durationMs = max(0.0, ($now - $startTime) * 1000);

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
            JobStartTimeCache::forget($jobId);
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
