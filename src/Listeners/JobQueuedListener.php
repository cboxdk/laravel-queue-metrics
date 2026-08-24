<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueMetrics\Listeners;

use Carbon\Carbon;
use Cbox\LaravelQueueMetrics\Repositories\Contracts\JobMetricsRepository;
use Illuminate\Queue\Events\JobQueued;

/**
 * Listen for jobs being queued.
 * Tracks when jobs are added to queue for time-to-start metrics.
 */
final readonly class JobQueuedListener
{
    public function __construct(
        private JobMetricsRepository $jobMetricsRepository,
    ) {}

    public function handle(JobQueued $event): void
    {
        if (! config('queue-metrics.persistence.enabled', true)) {
            return;
        }

        $connection = $event->connectionName;

        // The event's queue is authoritative: the job instance's own queue
        // property is null when the destination came from the connection
        // default or from a bulk push (e.g. every job inside a batch), which
        // recorded all batched jobs under a literal 'default' queue. Both are
        // null for a plain dispatch with no explicit queue, so resolve the
        // same name the driver resolves internally: the connection's
        // configured `queue` key.
        $queue = $event->queue ?? $event->job->queue ?? null;

        if (! is_string($queue) || $queue === '') {
            $queue = $this->connectionDefaultQueue($connection);
        }

        // Job can be an object or a string depending on the queue driver
        $job = $event->job;
        $jobClass = is_object($job) ? get_class($job) : (string) $job;

        // Store queued timestamp for time-to-start calculation
        // When JobProcessing fires, we can calculate: processing_started - queued_at
        $this->jobMetricsRepository->recordQueuedAt(
            jobClass: $jobClass,
            connection: $connection,
            queue: $queue,
            queuedAt: Carbon::now(),
        );
    }

    /**
     * Resolve the queue name the driver itself resolves for a
     * connection-default dispatch (`enum_value($queue) ?: $this->default`).
     */
    private function connectionDefaultQueue(string $connection): string
    {
        $configured = config("queue.connections.{$connection}.queue");

        return is_string($configured) && $configured !== '' ? $configured : 'default';
    }
}
