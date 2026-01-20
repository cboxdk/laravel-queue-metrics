<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueMetrics\Http\Controllers;

use Cbox\LaravelQueueMetrics\Services\QueueMetricsQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * HTTP controller for queue metrics endpoints.
 */
final class QueueMetricsController extends Controller
{
    public function __construct(
        private readonly QueueMetricsQueryService $metricsQuery,
    ) {}

    public function show(string $connection, string $queue): JsonResponse
    {
        $metrics = $this->metricsQuery->getQueueMetrics($connection, $queue);

        abort_if($metrics->isEmpty() && $metrics->throughputPerMinute === 0.0, 404, "No metrics found for queue: {$queue} on connection: {$connection}");

        $trends = $this->metricsQuery->getQueueTrends($connection, $queue);

        return response()->json([
            'data' => array_merge($metrics->toArray(), [
                'trends' => $trends,
            ]),
        ]);
    }
}
