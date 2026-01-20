<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueMetrics\Http\Controllers;

use Cbox\LaravelQueueMetrics\Services\QueueMetricsQueryService;
use Illuminate\Http\JsonResponse;

/**
 * Queue depth monitoring controller.
 */
final readonly class QueueDepthController
{
    public function __construct(
        private readonly QueueMetricsQueryService $metricsService,
    ) {}

    /**
     * Get queue depth for specific connection and queue.
     */
    public function show(string $connection, string $queue): JsonResponse
    {
        $depth = $this->metricsService->getQueueDepth($connection, $queue);

        return response()->json([
            'data' => $depth->toArray(),
        ]);
    }

    /**
     * Get all configured queues.
     */
    public function index(): JsonResponse
    {
        $queues = $this->metricsService->getAllQueues();

        return response()->json([
            'data' => $queues,
        ]);
    }
}
