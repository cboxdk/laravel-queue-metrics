<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueMetrics\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Cbox\LaravelQueueMetrics\Services\WorkerMetricsQueryService;

/**
 * HTTP controller for all servers with aggregated worker metrics.
 */
final class AllServersController extends Controller
{
    public function __construct(
        private readonly WorkerMetricsQueryService $metricsQuery,
    ) {}

    public function index(): JsonResponse
    {
        $servers = $this->metricsQuery->getAllServersWithMetrics();

        return response()->json([
            'data' => $servers,
        ]);
    }
}
