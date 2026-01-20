<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueMetrics\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Cbox\LaravelQueueMetrics\Services\ServerMetricsService;

/**
 * Exposes server-wide resource metrics.
 */
final readonly class ServerMetricsController
{
    public function __construct(
        private readonly ServerMetricsService $serverMetrics,
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

        if (! $metrics['available']) {
            return response()->json([
                'health' => $health,
                'metrics' => null,
            ]);
        }

        // Type-safe extraction with proper PHPDoc syntax
        /** @var array{usage_percent: float, load_average: array{'1min': float, '5min': float, '15min': float}} $cpu */
        $cpu = $metrics['cpu'];
        /** @var array{usage_percent: float, used_bytes: int, total_bytes: int} $memory */
        $memory = $metrics['memory'];
        /** @var array<array{mountpoint: string, usage_percent: float, used_bytes: int}> $disk */
        $disk = $metrics['disk'];

        return response()->json([
            'health' => $health,
            'metrics' => [
                'cpu_usage_percent' => $cpu['usage_percent'],
                'memory_usage_percent' => $memory['usage_percent'],
                'load_average_1min' => $cpu['load_average']['1min'],
                'disk_usage' => array_map(fn ($d) => [
                    'mountpoint' => $d['mountpoint'],
                    'usage_percent' => $d['usage_percent'],
                ], $disk),
            ],
        ]);
    }
}
