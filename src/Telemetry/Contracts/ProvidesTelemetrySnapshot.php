<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueMetrics\Telemetry\Contracts;

/**
 * Supplies the stored metrics snapshot consumed by the telemetry
 * observable gauges. Implementations must stay cheap: callbacks run on
 * every telemetry scrape.
 */
interface ProvidesTelemetrySnapshot
{
    /**
     * @return array{
     *     queues: array<int|string, array<string, mixed>>,
     *     workers: array<string, mixed>,
     *     baselines: array<int|string, array<string, mixed>>
     * }
     */
    public function snapshot(): array;
}
