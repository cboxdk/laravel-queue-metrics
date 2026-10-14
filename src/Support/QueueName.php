<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueMetrics\Support;

use Illuminate\Support\Str;

/**
 * Resolves the queue name metrics are keyed by.
 *
 * Laravel's SQS driver hands workers the full queue URL as the job's queue
 * (`SqsJob::getQueue()`), while dispatching, `queue:work --queue` and the
 * JobQueued event use the queue name. Reducing a URL to the name it was
 * built from keeps both sides of a job under one queue.
 *
 * @internal
 */
final class QueueName
{
    public static function normalize(string $connection, string $queue): string
    {
        if (! self::isUrl($queue)) {
            return $queue;
        }

        $path = parse_url($queue, PHP_URL_PATH);
        $name = is_string($path) ? basename($path) : '';

        if ($name === '') {
            return $queue;
        }

        return self::withoutSuffix($name, config("queue.connections.{$connection}.suffix"));
    }

    /**
     * A prefix check rather than FILTER_VALIDATE_URL: the SQS `prefix` is never
     * validated by Laravel, and hosts such as a Docker Compose `local_stack`
     * service are rejected by the filter.
     */
    private static function isUrl(string $queue): bool
    {
        return str_starts_with(strtolower($queue), 'https://')
            || str_starts_with(strtolower($queue), 'http://');
    }

    /**
     * Inverts SqsQueue::suffixQueue(), which places the suffix before `.fifo`.
     */
    private static function withoutSuffix(string $name, mixed $suffix): string
    {
        if (! is_string($suffix) || $suffix === '') {
            return $name;
        }

        $fifo = str_ends_with($name, '.fifo') ? '.fifo' : '';
        $base = $fifo === '' ? $name : Str::beforeLast($name, '.fifo');

        if (! str_ends_with($base, $suffix) || $base === $suffix) {
            return $name;
        }

        return substr($base, 0, -strlen($suffix)).$fifo;
    }
}
