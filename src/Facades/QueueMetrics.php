<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueMetrics\Facades;

use Illuminate\Support\Facades\Facade;
use Cbox\LaravelQueueMetrics\Services\JobMetricsQueryService;
use Cbox\LaravelQueueMetrics\Services\OverviewQueryService;
use Cbox\LaravelQueueMetrics\Services\QueueMetricsQueryService;
use Cbox\LaravelQueueMetrics\Services\WorkerMetricsQueryService;

/**
 * Facade providing convenient access to queue metrics services.
 *
 * Overview methods:
 *
 * @method static array<string, mixed> getOverview()
 *
 * Job metrics methods:
 * @method static \Cbox\LaravelQueueMetrics\DataTransferObjects\JobMetricsData getJobMetrics(string $jobClass, string $connection = 'default', string $queue = 'default')
 * @method static array<string, array<string, mixed>> getAllJobsWithMetrics()
 *
 * Queue metrics methods:
 * @method static \Cbox\LaravelQueueMetrics\DataTransferObjects\QueueMetricsData getQueueMetrics(string $connection = 'default', string $queue = 'default')
 * @method static \Cbox\LaravelQueueMetrics\DataTransferObjects\QueueDepthData getQueueDepth(string $connection = 'default', string $queue = 'default')
 * @method static array<string, array<string, mixed>> getAllQueuesWithMetrics()
 * @method static array<string, mixed> healthCheck()
 *
 * Worker metrics methods:
 * @method static \Illuminate\Support\Collection<int, \Cbox\LaravelQueueMetrics\DataTransferObjects\WorkerHeartbeat> getActiveWorkers(?string $connection = null, ?string $queue = null)
 * @method static \Illuminate\Support\Collection<int, \Cbox\LaravelQueueMetrics\DataTransferObjects\WorkerHeartbeat> getWorkerHeartbeats(?string $connection = null, ?string $queue = null)
 * @method static ?\Cbox\LaravelQueueMetrics\DataTransferObjects\WorkerHeartbeat getWorkerHeartbeat(string $workerId)
 * @method static int detectStaledWorkers(int $thresholdSeconds = 60)
 * @method static array<string, array<string, mixed>> getAllServersWithMetrics()
 * @method static array<string, mixed> getWorkersSummary()
 *
 * @see \Cbox\LaravelQueueMetrics\Services\OverviewQueryService
 * @see \Cbox\LaravelQueueMetrics\Services\JobMetricsQueryService
 * @see \Cbox\LaravelQueueMetrics\Services\QueueMetricsQueryService
 * @see \Cbox\LaravelQueueMetrics\Services\WorkerMetricsQueryService
 */
final class QueueMetrics extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * Dynamically routes to the appropriate service based on method name.
     */
    protected static function getFacadeAccessor(): string
    {
        // Default to OverviewQueryService for the main getOverview() method
        return OverviewQueryService::class;
    }

    /**
     * Handle dynamic static method calls into the facade.
     *
     * Routes method calls to the appropriate service.
     *
     * @param  string  $method
     * @param  array<int, mixed>  $args
     */
    public static function __callStatic(mixed $method, mixed $args): mixed
    {
        // Route to appropriate service based on method name
        $service = match (true) {
            in_array($method, ['getJobMetrics', 'getAllJobsWithMetrics', 'getJobClassBaseline']) => app(JobMetricsQueryService::class),
            in_array($method, ['getQueueMetrics', 'getQueueDepth', 'getAllQueuesWithMetrics', 'getAllQueues', 'getBaseline', 'healthCheck']) => app(QueueMetricsQueryService::class),
            in_array($method, ['getActiveWorkers', 'getWorkerHeartbeats', 'getWorkerHeartbeat', 'detectStaledWorkers', 'getAllServersWithMetrics', 'getWorkersSummary', 'getAllWorkersWithMetrics']) => app(WorkerMetricsQueryService::class),
            default => app(OverviewQueryService::class),
        };

        return $service->$method(...$args);
    }
}
