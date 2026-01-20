<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueMetrics\Services;

use Cbox\LaravelQueueMetrics\Support\RedisMetricsStore;

/**
 * Service for scanning and parsing Redis keys to discover entities.
 * Provides proper dependency injection and testability.
 */
final class RedisKeyScannerService
{
    private ?string $fullPrefix = null;

    public function __construct(
        private readonly RedisMetricsStore $redisStore,
    ) {}

    /**
     * Get the full Redis key prefix (lazy loaded to avoid connection during boot).
     */
    private function getFullPrefix(): string
    {
        if ($this->fullPrefix === null) {
            /** @var string $redisConnection */
            $redisConnection = config('queue-metrics.storage.connection', 'default');
            $connectionPrefix = app('redis')->connection($redisConnection)->_prefix('');
            $ourPrefix = $this->redisStore->key('');
            $this->fullPrefix = $connectionPrefix.$ourPrefix;
        }

        return $this->fullPrefix;
    }

    /**
     * Scan Redis for all keys matching given patterns and extract entity information.
     *
     * @param  string  $jobsPattern  Pattern for completed job keys (e.g., 'jobs:*:*:*')
     * @param  string  $queuedPattern  Pattern for queued job keys (e.g., 'queued:*:*:*')
     * @param  callable(string): ?array{connection: string, queue: string, jobClass?: string}  $keyParser  Function to parse key and extract entity data
     * @return array<string, array{connection: string, queue: string, jobClass?: string}>
     */
    public function scanAndParseKeys(string $jobsPattern, string $queuedPattern, callable $keyParser): array
    {
        // Scan both completed and queued job keys
        $jobsKeys = $this->redisStore->scanKeys($jobsPattern);
        $queuedKeys = $this->redisStore->scanKeys($queuedPattern);

        // Combine both key types
        $allKeys = array_merge($jobsKeys, $queuedKeys);

        if (empty($allKeys)) {
            return [];
        }

        $fullPrefix = $this->getFullPrefix();

        // Extract unique entities from all keys using the provided parser
        $discovered = [];
        foreach ($allKeys as $key) {
            // Remove the full prefix first
            if (! str_starts_with($key, $fullPrefix)) {
                continue;
            }

            $keyWithoutPrefix = substr($key, strlen($fullPrefix));

            // Parse the key using the provided callable
            $entity = $keyParser($keyWithoutPrefix);

            if ($entity !== null) {
                // Use a unique key for this entity (connection:queue or just jobClass)
                $uniqueKey = $entity['jobClass'] ?? "{$entity['connection']}:{$entity['queue']}";
                $discovered[$uniqueKey] = $entity;
            }
        }

        return $discovered;
    }
}
