<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueMetrics\Http\Controllers;

use Cbox\LaravelQueueMetrics\Services\WorkerMetricsQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * HTTP controller for worker endpoints.
 */
final class WorkerController extends Controller
{
    public function __construct(
        private readonly WorkerMetricsQueryService $metricsQuery,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $connection = $request->query('connection');
        $queue = $request->query('queue');

        $workers = $this->metricsQuery->getActiveWorkers($connection, $queue);
        $efficiencyTrend = $this->metricsQuery->getWorkerEfficiencyTrend();

        return response()->json([
            'data' => [
                'workers' => $workers->map(fn ($worker) => $worker->toArray())->values(),
                'efficiency_trend' => $efficiencyTrend,
            ],
        ]);
    }
}
