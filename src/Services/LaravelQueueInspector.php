<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueMetrics\Services;

use Carbon\Carbon;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Queue\RedisQueue;
use PHPeek\LaravelQueueMetrics\Contracts\QueueInspector;
use PHPeek\LaravelQueueMetrics\DataTransferObjects\QueueDepthData;
use ReflectionClass;
use ReflectionException;

/**
 * Laravel Queue API inspector with reflection fallback.
 */
final readonly class LaravelQueueInspector implements QueueInspector
{
    public function __construct(
        private QueueFactory $queueFactory,
    ) {}

    public function getQueueDepth(string $connection, string $queue): QueueDepthData
    {
        $queueInstance = $this->queueFactory->connection($connection);

        // Try modern Laravel API first (Laravel 11+, PR #56010)
        if (method_exists($queueInstance, 'size')) {
            return $this->getDepthModernApi($queueInstance, $connection, $queue);
        }

        // Fallback to reflection for older versions
        return $this->getDepthViaReflection($queueInstance, $connection, $queue);
    }

    public function hasJobs(string $connection, string $queue): bool
    {
        $depth = $this->getQueueDepth($connection, $queue);

        return ! $depth->isEmpty();
    }

    /**
     * @return array<string>
     */
    public function getAllQueues(): array
    {
        $queues = config('queue.connections', []);
        $discovered = ['default'];

        foreach ($queues as $connection => $config) {
            if (isset($config['queue'])) {
                $discovered[] = $config['queue'];
            }

            // Also check for multiple queues in config
            if (isset($config['queues']) && is_array($config['queues'])) {
                $discovered = array_merge($discovered, $config['queues']);
            }
        }

        // Get from workers configuration if available
        $workers = config('queue.workers', []);
        foreach ($workers as $worker) {
            if (isset($worker['queue'])) {
                $queues = explode(',', $worker['queue']);
                $discovered = array_merge($discovered, $queues);
            }
        }

        return array_values(array_unique(array_filter($discovered)));
    }

    /**
     * Use modern Laravel Queue API (Laravel 11+).
     */
    private function getDepthModernApi(
        mixed $queueInstance,
        string $connection,
        string $queue,
    ): QueueDepthData {
        $pendingJobs = method_exists($queueInstance, 'size')
            ? $queueInstance->size($queue)
            : 0;

        $reservedJobs = 0;
        $delayedJobs = 0;

        // Try to get reserved and delayed counts if methods exist
        if (method_exists($queueInstance, 'sizeReserved')) {
            $reservedJobs = $queueInstance->sizeReserved($queue);
        }

        if (method_exists($queueInstance, 'sizeDelayed')) {
            $delayedJobs = $queueInstance->sizeDelayed($queue);
        }

        // Try to get oldest job timestamps
        $oldestPendingAge = $this->getOldestJobAge($queueInstance, $queue, 'pending');
        $oldestDelayedAge = $this->getOldestJobAge($queueInstance, $queue, 'delayed');

        return new QueueDepthData(
            connection: $connection,
            queue: $queue,
            pendingJobs: $pendingJobs,
            reservedJobs: $reservedJobs,
            delayedJobs: $delayedJobs,
            oldestPendingJobAge: $oldestPendingAge,
            oldestDelayedJobAge: $oldestDelayedAge,
            measuredAt: Carbon::now(),
        );
    }

    /**
     * Fallback using reflection for older Laravel versions.
     */
    private function getDepthViaReflection(
        mixed $queueInstance,
        string $connection,
        string $queue,
    ): QueueDepthData {
        // For Redis queues, we can use Redis commands directly
        if ($queueInstance instanceof RedisQueue) {
            return $this->getRedisQueueDepth($queueInstance, $connection, $queue);
        }

        // For other queue types, try reflection
        try {
            $reflection = new ReflectionClass($queueInstance);

            $pendingJobs = 0;
            $reservedJobs = 0;
            $delayedJobs = 0;

            // Try to call protected/private size methods
            if ($reflection->hasMethod('size')) {
                $method = $reflection->getMethod('size');
                $method->setAccessible(true);
                $pendingJobs = $method->invoke($queueInstance, $queue) ?? 0;
            }

            return new QueueDepthData(
                connection: $connection,
                queue: $queue,
                pendingJobs: $pendingJobs,
                reservedJobs: $reservedJobs,
                delayedJobs: $delayedJobs,
                oldestPendingJobAge: null,
                oldestDelayedJobAge: null,
                measuredAt: Carbon::now(),
            );
        } catch (ReflectionException) {
            // If reflection fails, return empty depth
            return new QueueDepthData(
                connection: $connection,
                queue: $queue,
                pendingJobs: 0,
                reservedJobs: 0,
                delayedJobs: 0,
                oldestPendingJobAge: null,
                oldestDelayedJobAge: null,
                measuredAt: Carbon::now(),
            );
        }
    }

    /**
     * Get queue depth for Redis queues using direct Redis commands.
     */
    private function getRedisQueueDepth(
        RedisQueue $queue,
        string $connection,
        string $queueName,
    ): QueueDepthData {
        try {
            $reflection = new ReflectionClass($queue);
            $redisProperty = $reflection->getProperty('redis');
            $redisProperty->setAccessible(true);
            $redis = $redisProperty->getValue($queue);

            $prefix = config("queue.connections.{$connection}.prefix", 'queues');

            // Get pending jobs count
            $pendingKey = "{$prefix}:{$queueName}";
            $pendingJobs = $redis->llen($pendingKey) ?? 0;

            // Get reserved jobs count
            $reservedKey = "{$prefix}:{$queueName}:reserved";
            $reservedJobs = $redis->zcard($reservedKey) ?? 0;

            // Get delayed jobs count
            $delayedKey = "{$prefix}:{$queueName}:delayed";
            $delayedJobs = $redis->zcard($delayedKey) ?? 0;

            // Get oldest pending job timestamp
            $oldestPending = null;
            $oldestJob = $redis->lindex($pendingKey, 0);
            if ($oldestJob) {
                $decoded = json_decode($oldestJob, true);
                if (isset($decoded['pushedAt'])) {
                    $oldestPending = Carbon::createFromTimestamp((int) $decoded['pushedAt']);
                }
            }

            // Get oldest delayed job timestamp
            $oldestDelayed = null;
            $oldestDelayedJobs = $redis->zrange($delayedKey, 0, 0, 'WITHSCORES');
            if (! empty($oldestDelayedJobs)) {
                $timestamp = (int) reset($oldestDelayedJobs);
                $oldestDelayed = Carbon::createFromTimestamp($timestamp);
            }

            return new QueueDepthData(
                connection: $connection,
                queue: $queueName,
                pendingJobs: $pendingJobs,
                reservedJobs: $reservedJobs,
                delayedJobs: $delayedJobs,
                oldestPendingJobAge: $oldestPending,
                oldestDelayedJobAge: $oldestDelayed,
                measuredAt: Carbon::now(),
            );
        } catch (ReflectionException) {
            return new QueueDepthData(
                connection: $connection,
                queue: $queueName,
                pendingJobs: 0,
                reservedJobs: 0,
                delayedJobs: 0,
                oldestPendingJobAge: null,
                oldestDelayedJobAge: null,
                measuredAt: Carbon::now(),
            );
        }
    }

    /**
     * Try to get oldest job age from queue.
     */
    private function getOldestJobAge(
        mixed $queueInstance,
        string $queue,
        string $type = 'pending',
    ): ?Carbon {
        // This would require custom queue driver implementation or Laravel 11+ API
        // For now, return null and rely on Redis-specific implementation above
        return null;
    }
}
