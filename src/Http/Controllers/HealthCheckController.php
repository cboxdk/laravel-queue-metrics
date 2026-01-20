<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueMetrics\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Cbox\LaravelQueueMetrics\Services\QueueMetricsQueryService;

/**
 * HTTP controller for health check endpoint.
 */
final class HealthCheckController extends Controller
{
    public function __construct(
        private readonly QueueMetricsQueryService $metricsQuery,
    ) {}

    public function __invoke(): JsonResponse
    {
        $health = $this->metricsQuery->healthCheck();

        return response()->json($health);
    }
}
