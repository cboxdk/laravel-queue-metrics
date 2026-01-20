<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueMetrics\Http\Controllers;

use Cbox\LaravelQueueMetrics\Services\WorkerMetricsQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Worker status and heartbeat controller.
 */
final readonly class WorkerStatusController
{
    public function __construct(
        private readonly WorkerMetricsQueryService $metricsService,
    ) {}

    /**
     * Get all worker heartbeats with optional filtering.
     */
    public function index(Request $request): JsonResponse
    {
        $connection = $request->query('connection');
        $queue = $request->query('queue');

        $workers = $this->metricsService->getWorkerHeartbeats($connection, $queue);

        return response()->json([
            'data' => $workers->map(fn ($worker) => [
                'worker_id' => $worker->workerId,
                'connection' => $worker->connection,
                'queue' => $worker->queue,
                'state' => $worker->state->value,
                'last_heartbeat' => $worker->lastHeartbeat->toIso8601String(),
                'last_state_change' => $worker->lastStateChange?->toIso8601String(),
                'current_job_id' => $worker->currentJobId,
                'current_job_class' => $worker->currentJobClass,
                'idle_time_seconds' => $worker->idleTimeSeconds,
                'busy_time_seconds' => $worker->busyTimeSeconds,
                'jobs_processed' => $worker->jobsProcessed,
                'pid' => $worker->pid,
                'hostname' => $worker->hostname,
                'is_stale' => $worker->isStale(),
                'seconds_since_last_heartbeat' => $worker->secondsSinceLastHeartbeat(),
            ])->values(),
            'meta' => [
                'total' => $workers->count(),
            ],
        ]);
    }

    /**
     * Get specific worker heartbeat.
     */
    public function show(string $workerId): JsonResponse
    {
        $worker = $this->metricsService->getWorkerHeartbeat($workerId);

        if ($worker === null) {
            return response()->json([
                'error' => 'Worker not found',
            ], 404);
        }

        return response()->json([
            'data' => [
                'worker_id' => $worker->workerId,
                'connection' => $worker->connection,
                'queue' => $worker->queue,
                'state' => $worker->state->value,
                'last_heartbeat' => $worker->lastHeartbeat->toIso8601String(),
                'last_state_change' => $worker->lastStateChange?->toIso8601String(),
                'current_job_id' => $worker->currentJobId,
                'current_job_class' => $worker->currentJobClass,
                'idle_time_seconds' => $worker->idleTimeSeconds,
                'busy_time_seconds' => $worker->busyTimeSeconds,
                'jobs_processed' => $worker->jobsProcessed,
                'pid' => $worker->pid,
                'hostname' => $worker->hostname,
                'is_stale' => $worker->isStale(),
                'seconds_since_last_heartbeat' => $worker->secondsSinceLastHeartbeat(),
            ],
        ]);
    }

    /**
     * Detect and mark stale workers as crashed.
     */
    public function detectStale(Request $request): JsonResponse
    {
        $thresholdSeconds = (int) ($request->query('threshold', 60));

        $markedCount = $this->metricsService->detectStaledWorkers($thresholdSeconds);

        return response()->json([
            'data' => [
                'threshold_seconds' => $thresholdSeconds,
                'workers_marked_crashed' => $markedCount,
            ],
        ]);
    }
}
