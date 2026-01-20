<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueMetrics\Http\Controllers;

use Cbox\LaravelQueueMetrics\Services\JobMetricsQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * HTTP controller for all jobs with comprehensive metrics.
 */
final class AllJobsController extends Controller
{
    public function __construct(
        private readonly JobMetricsQueryService $metricsQuery,
    ) {}

    public function index(): JsonResponse
    {
        $jobs = $this->metricsQuery->getAllJobsWithMetrics();

        return response()->json([
            'data' => $jobs,
        ]);
    }
}
