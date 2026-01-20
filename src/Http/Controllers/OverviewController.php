<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueMetrics\Http\Controllers;

use Cbox\LaravelQueueMetrics\Services\OverviewQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * HTTP controller for overview endpoint.
 */
final class OverviewController extends Controller
{
    public function __construct(
        private readonly OverviewQueryService $metricsQuery,
    ) {}

    public function __invoke(\Illuminate\Http\Request $request): JsonResponse
    {
        // Slim view by default, full view with ?full=1
        $slim = ! $request->query('full');

        $overview = $this->metricsQuery->getOverview($slim);

        return response()->json([
            'data' => $overview,
        ]);
    }
}
