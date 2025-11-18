<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueMetrics\Http\Controllers;

use Illuminate\Http\Response;
use PHPeek\LaravelQueueMetrics\Services\PrometheusService;
use Spatie\Prometheus\Facades\Prometheus;

/**
 * Prometheus metrics exporter controller.
 *
 * Delegates comprehensive metric collection to PrometheusService,
 * then renders metrics in Prometheus exposition format.
 */
final readonly class PrometheusController
{
    public function __construct(
        private readonly PrometheusService $prometheusService,
    ) {}

    public function __invoke(): Response
    {
        // Export all metrics to Prometheus collectors
        $this->prometheusService->exportMetrics();

        // Render metrics in Prometheus text format
        $metrics = Prometheus::renderCollectors();

        return response($metrics, 200, [
            'Content-Type' => 'text/plain; version=0.0.4; charset=utf-8',
        ]);
    }
}
