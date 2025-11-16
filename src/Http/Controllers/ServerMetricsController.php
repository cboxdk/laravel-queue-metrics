<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueMetrics\Http\Controllers;

use Illuminate\Http\JsonResponse;
use PHPeek\LaravelQueueMetrics\Services\ServerMetricsService;

/**
 * Exposes server-wide resource metrics.
 */
final readonly class ServerMetricsController
{
    public function __construct(
        private ServerMetricsService $serverMetrics,
    ) {}

    /**
     * Get current server resource metrics.
     */
    public function index(): JsonResponse
    {
        $metrics = $this->serverMetrics->getCurrentMetrics();

        return response()->json([
            'server_metrics' => $metrics,
        ]);
    }

    /**
     * Get server health status.
     */
    public function health(): JsonResponse
    {
        $health = $this->serverMetrics->getHealthStatus();
        $metrics = $this->serverMetrics->getCurrentMetrics();

        return response()->json([
            'health' => $health,
            'metrics' => $metrics['available'] ? [
                'cpu_usage_percent' => $metrics['cpu']['usage_percent'],
                'memory_usage_percent' => $metrics['memory']['usage_percent'],
                'load_average_1min' => $metrics['cpu']['load_average']['1min'],
                'disk_usage' => array_map(fn ($disk) => [
                    'mountpoint' => $disk['mountpoint'],
                    'usage_percent' => $disk['usage_percent'],
                ], $metrics['disk']),
            ] : null,
        ]);
    }
}
