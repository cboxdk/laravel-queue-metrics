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

        // Layer 1: Try Laravel 12.19+ native methods (PR #56010)
        if ($this->hasAllNativeMethods($queueInstance)) {
            return $this->getDepthNativeApi($queueInstance, $connection, $queue);
        }

        // Layer 2: Try driver-specific implementations
        return $this->getDepthViaReflection($queueInstance, $connection, $queue);
    }

    /**
     * Check if queue instance has all Laravel 12.19+ native methods.
     */
    private function hasAllNativeMethods(mixed $queueInstance): bool
    {
        return is_object($queueInstance)
            && method_exists($queueInstance, 'pendingSize')
            && method_exists($queueInstance, 'delayedSize')
            && method_exists($queueInstance, 'reservedSize');
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
     * Use Laravel 12.19+ native queue methods (PR #56010).
     */
    private function getDepthNativeApi(
        mixed $queueInstance,
        string $connection,
        string $queue,
    ): QueueDepthData {
        // @phpstan-ignore method.nonObject (Laravel 12.19+ dynamic methods checked by hasAllNativeMethods)
        $pending = $queueInstance->pendingSize($queue);
        $pendingJobs = is_int($pending) ? $pending : 0;

        // @phpstan-ignore method.nonObject (Laravel 12.19+ dynamic methods checked by hasAllNativeMethods)
        $delayed = $queueInstance->delayedSize($queue);
        $delayedJobs = is_int($delayed) ? $delayed : 0;

        // @phpstan-ignore method.nonObject (Laravel 12.19+ dynamic methods checked by hasAllNativeMethods)
        $reserved = $queueInstance->reservedSize($queue);
        $reservedJobs = is_int($reserved) ? $reserved : 0;

        // Get oldest pending job age if available (Laravel 12.19+)
        $oldestPendingAge = null;
        if (is_object($queueInstance) && method_exists($queueInstance, 'creationTimeOfOldestPendingJob')) {
            $oldestCreationTime = $queueInstance->creationTimeOfOldestPendingJob($queue);
            if ($oldestCreationTime !== null) {
                $oldestPendingAge = Carbon::createFromTimestamp($oldestCreationTime);
            }
        }

        // Try to get oldest delayed job age
        $oldestDelayedAge = null;
        if (is_object($queueInstance) && method_exists($queueInstance, 'creationTimeOfOldestDelayedJob')) {
            $oldestDelayedTime = $queueInstance->creationTimeOfOldestDelayedJob($queue);
            if ($oldestDelayedTime !== null) {
                $oldestDelayedAge = Carbon::createFromTimestamp($oldestDelayedTime);
            }
        }

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
     * Layer 2: Driver-specific implementations with generic fallback.
     */
    private function getDepthViaReflection(
        mixed $queueInstance,
        string $connection,
        string $queue,
    ): QueueDepthData {
        // Redis queue - use direct Redis commands
        if ($queueInstance instanceof RedisQueue) {
            return $this->getRedisQueueDepth($queueInstance, $connection, $queue);
        }

        // Database queue - use direct database queries
        if ($this->isDatabaseQueue($queueInstance)) {
            return $this->getDatabaseQueueDepth($queueInstance, $connection, $queue);
        }

        // Layer 3: Generic fallback - try size() method
        return $this->getGenericQueueDepth($queueInstance, $connection, $queue);
    }

    /**
     * Check if queue instance is a database queue.
     */
    private function isDatabaseQueue(mixed $queueInstance): bool
    {
        if (! is_object($queueInstance)) {
            return false;
        }

        $className = get_class($queueInstance);

        return $className === 'Illuminate\Queue\DatabaseQueue';
    }

    /**
     * Get queue depth for Database queues using direct database queries.
     */
    private function getDatabaseQueueDepth(
        mixed $queueInstance,
        string $connection,
        string $queueName,
    ): QueueDepthData {
        try {
            if (! is_object($queueInstance)) {
                return $this->getGenericQueueDepth($queueInstance, $connection, $queueName);
            }

            $reflection = new ReflectionClass($queueInstance);

            // Get database connection via reflection
            $databaseProperty = $reflection->getProperty('database');
            $databaseProperty->setAccessible(true);
            $database = $databaseProperty->getValue($queueInstance);

            // Get table name via reflection
            $tableProperty = $reflection->getProperty('table');
            $tableProperty->setAccessible(true);
            $table = $tableProperty->getValue($queueInstance);

            // Validate we got the expected types
            if (! is_object($database) || ! method_exists($database, 'table')) {
                return $this->getGenericQueueDepth($queueInstance, $connection, $queueName);
            }

            if (! is_string($table)) {
                return $this->getGenericQueueDepth($queueInstance, $connection, $queueName);
            }

            $now = Carbon::now();

            // Count pending jobs (available now, not reserved)
            $pending = $database->table($table)
                ->where('queue', $queueName)
                ->whereNull('reserved_at')
                ->where('available_at', '<=', $now->timestamp)
                ->count();

            // Count delayed jobs (available in future)
            $delayed = $database->table($table)
                ->where('queue', $queueName)
                ->where('available_at', '>', $now->timestamp)
                ->count();

            // Count reserved jobs (currently being processed)
            $reserved = $database->table($table)
                ->where('queue', $queueName)
                ->whereNotNull('reserved_at')
                ->count();

            // Get oldest pending job
            $oldestPendingAge = null;
            if ($pending > 0) {
                $oldestPendingTimestamp = $database->table($table)
                    ->where('queue', $queueName)
                    ->whereNull('reserved_at')
                    ->where('available_at', '<=', $now->timestamp)
                    ->orderBy('created_at')
                    ->value('created_at');

                if (is_numeric($oldestPendingTimestamp)) {
                    $oldestPendingAge = Carbon::createFromTimestamp((int) $oldestPendingTimestamp);
                }
            }

            // Get oldest delayed job
            $oldestDelayedAge = null;
            if ($delayed > 0) {
                $oldestDelayedTimestamp = $database->table($table)
                    ->where('queue', $queueName)
                    ->where('available_at', '>', $now->timestamp)
                    ->orderBy('available_at')
                    ->value('available_at');

                if (is_numeric($oldestDelayedTimestamp)) {
                    $oldestDelayedAge = Carbon::createFromTimestamp((int) $oldestDelayedTimestamp);
                }
            }

            return new QueueDepthData(
                connection: $connection,
                queue: $queueName,
                pendingJobs: $pending,
                reservedJobs: $reserved,
                delayedJobs: $delayed,
                oldestPendingJobAge: $oldestPendingAge,
                oldestDelayedJobAge: $oldestDelayedAge,
                measuredAt: Carbon::now(),
            );
        } catch (ReflectionException) {
            // Database reflection failed, use generic fallback
            return $this->getGenericQueueDepth($queueInstance, $connection, $queueName);
        }
    }

    /**
     * Layer 3: Generic fallback using size() method.
     */
    private function getGenericQueueDepth(
        mixed $queueInstance,
        string $connection,
        string $queue,
    ): QueueDepthData {
        $pendingJobs = 0;

        // Try generic size() method as last resort
        if (is_object($queueInstance) && method_exists($queueInstance, 'size')) {
            try {
                $size = $queueInstance->size($queue);
                // Use size as pending count (best approximation we have)
                $pendingJobs = is_int($size) ? $size : 0;
            } catch (\Throwable) {
                // size() failed, return zeros
                $pendingJobs = 0;
            }
        }

        return new QueueDepthData(
            connection: $connection,
            queue: $queue,
            pendingJobs: $pendingJobs,
            reservedJobs: 0, // Cannot determine with generic method
            delayedJobs: 0, // Cannot determine with generic method
            oldestPendingJobAge: null,
            oldestDelayedJobAge: null,
            measuredAt: Carbon::now(),
        );
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

            $prefix = config("queue.connections.{$connection}.prefix");
            if (! is_string($prefix)) {
                $prefix = 'queues';
            }

            // Get pending jobs count
            $pendingKey = "{$prefix}:{$queueName}";
            $pendingCount = $redis->llen($pendingKey);
            $pendingJobs = is_int($pendingCount) ? $pendingCount : 0;

            // Get reserved jobs count
            $reservedKey = "{$prefix}:{$queueName}:reserved";
            $reservedCount = $redis->zcard($reservedKey);
            $reservedJobs = is_int($reservedCount) ? $reservedCount : 0;

            // Get delayed jobs count
            $delayedKey = "{$prefix}:{$queueName}:delayed";
            $delayedCount = $redis->zcard($delayedKey);
            $delayedJobs = is_int($delayedCount) ? $delayedCount : 0;

            // Get oldest pending job timestamp
            $oldestPending = null;
            $oldestJob = $redis->lindex($pendingKey, 0);
            if (is_string($oldestJob)) {
                $decoded = json_decode($oldestJob, true);
                if (is_array($decoded) && isset($decoded['pushedAt']) && is_numeric($decoded['pushedAt'])) {
                    $oldestPending = Carbon::createFromTimestamp((int) $decoded['pushedAt']);
                }
            }

            // Get oldest delayed job timestamp
            $oldestDelayed = null;
            $oldestDelayedJobs = $redis->zrange($delayedKey, 0, 0, 'WITHSCORES');
            if (is_array($oldestDelayedJobs) && ! empty($oldestDelayedJobs)) {
                $timestamp = reset($oldestDelayedJobs);
                if (is_numeric($timestamp)) {
                    $oldestDelayed = Carbon::createFromTimestamp((int) $timestamp);
                }
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

}
