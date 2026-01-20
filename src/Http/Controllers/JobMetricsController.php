<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueMetrics\Http\Controllers;

use Cbox\LaravelQueueMetrics\Services\JobMetricsQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

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

        abort_if($metrics->totalExecutions === 0, 404, "No metrics found for job class: {$decodedJobClass}");

        return response()->json([
            'data' => $metrics->toArray(),
        ]);
    }
}
