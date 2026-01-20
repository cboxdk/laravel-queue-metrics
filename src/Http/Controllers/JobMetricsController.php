<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueMetrics\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Cbox\LaravelQueueMetrics\Services\JobMetricsQueryService;

/**
 * HTTP controller for job metrics endpoints.
 */
final class JobMetricsController extends Controller
{
    public function __construct(
        private readonly JobMetricsQueryService $metricsQuery,
    ) {}

    public function show(string $jobClass): JsonResponse
    {
        $decodedJobClass = urldecode($jobClass);

        $metrics = $this->metricsQuery->getAggregatedJobMetrics($decodedJobClass);

        return response()->json([
            'data' => $metrics->toArray(),
        ]);
    }
}
