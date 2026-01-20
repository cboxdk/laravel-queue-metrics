<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueMetrics\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Cbox\LaravelQueueMetrics\Services\WorkerMetricsQueryService;

/**
 * HTTP controller for all workers summary metrics.
 */
final class AllWorkersController extends Controller
{
    public function __construct(
        private readonly WorkerMetricsQueryService $metricsQuery,
    ) {}

    public function index(): JsonResponse
    {
        $workers = $this->metricsQuery->getAllWorkersWithMetrics();

        return response()->json([
            'data' => $workers,
        ]);
    }
}
