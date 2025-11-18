<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueMetrics\Services\Contracts;

interface OverviewQueryInterface
{
    /**
     * Get comprehensive overview of all queue metrics.
     *
     * @return array{
     *     queues: array<string, array<string, mixed>>,
     *     jobs: array<string, array<string, mixed>>,
     *     workers: array<string, mixed>,
     *     baselines: array<string, array<string, mixed>>
     * }
     */
    public function getOverview(): array;
}
