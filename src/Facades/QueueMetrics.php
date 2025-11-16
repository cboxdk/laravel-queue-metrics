<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueMetrics\Facades;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Facade;
use PHPeek\LaravelQueueMetrics\DataTransferObjects\BaselineData;
use PHPeek\LaravelQueueMetrics\DataTransferObjects\JobMetricsData;
use PHPeek\LaravelQueueMetrics\DataTransferObjects\QueueMetricsData;
use PHPeek\LaravelQueueMetrics\DataTransferObjects\WorkerStatsData;
use PHPeek\LaravelQueueMetrics\Services\MetricsQueryService;

/**
 * @method static JobMetricsData getJobMetrics(string $jobClass, string $connection = 'default', string $queue = 'default')
 * @method static QueueMetricsData getQueueMetrics(string $connection = 'default', string $queue = 'default')
 * @method static Collection<int, WorkerStatsData> getActiveWorkers(?string $connection = null, ?string $queue = null)
 * @method static BaselineData|null getBaseline(string $connection, string $queue)
 * @method static array getOverview()
 * @method static array healthCheck()
 *
 * @see MetricsQueryService
 */
final class QueueMetrics extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return MetricsQueryService::class;
    }
}
