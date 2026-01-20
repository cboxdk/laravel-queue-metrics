<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueMetrics\Http\Controllers;

use Cbox\LaravelQueueMetrics\Services\QueueMetricsQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * HTTP controller for all queues with comprehensive metrics.
 */
final class AllQueuesController extends Controller
{
    public function __construct(
        private readonly QueueMetricsQueryService $metricsQuery,
    ) {}

    public function index(): JsonResponse
    {
        $queues = $this->metricsQuery->getAllQueuesWithMetrics();

        return response()->json([
            'data' => $queues,
        ]);
    }
}
