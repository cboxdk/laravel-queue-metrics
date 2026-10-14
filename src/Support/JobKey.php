<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueMetrics\Support;

use Illuminate\Contracts\Queue\Job;

/**
 * Identifies a job across the listeners that see one attempt of it.
 *
 * The driver's job ID is used where there is one. The sync driver returns an
 * empty ID for every job, so a job dispatched synchronously from inside
 * another job would share the outer job's start time, CPU and memory
 * snapshots and process tracker, and finishing the inner job would consume
 * the outer job's entries. Without a driver ID the payload UUID is used, which
 * every dispatch generates afresh, and failing that the job object's identity,
 * which is unique for as long as the job is being processed.
 *
 * @internal
 */
final class JobKey
{
    public static function of(Job $job): string
    {
        $jobId = (string) $job->getJobId();

        if ($jobId !== '') {
            return $jobId;
        }

        $uuid = $job->uuid();

        if (is_string($uuid) && $uuid !== '') {
            return $uuid;
        }

        return 'object-'.spl_object_id($job);
    }
}
