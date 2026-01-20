<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueMetrics\Services\Contracts;

interface OverviewQueryInterface
{
    /**
     * Get comprehensive overview of all queue metrics.
     *
     * @param  bool  $slim  Return minimal dashboard fields only (default: true)
     * @return array{
     *     queues: array<string, array<string, mixed>>,
     *     jobs: array<string, array<string, mixed>>,
     *     workers: array<string, mixed>,
     *     baselines?: array<string, array<string, mixed>>,
     *     trends?: array<string, mixed>,
     *     metadata: array{timestamp: string, package_version: string, laravel_version: string, storage_driver: mixed}
     * }
     */
    public function getOverview(bool $slim = true): array;
}
