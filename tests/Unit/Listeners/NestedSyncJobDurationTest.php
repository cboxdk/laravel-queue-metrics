<?php

declare(strict_types=1);

use Cbox\LaravelQueueMetrics\Events\JobMetricsCompleted;
use Cbox\LaravelQueueMetrics\Listeners\JobProcessedListener;
use Cbox\LaravelQueueMetrics\Listeners\JobProcessingListener;
use Cbox\LaravelQueueMetrics\Support\JobStartTimeCache;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;

class InnerSyncDispatchedJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        usleep(50_000);
    }
}

class OuterSyncDispatchingJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        usleep(100_000);

        dispatch_sync(new InnerSyncDispatchedJob);
    }
}

beforeEach(function () {
    config([
        'queue-metrics.enabled' => true,
        'queue-metrics.persistence.enabled' => false,
    ]);

    JobStartTimeCache::reset();
    Event::fake([JobMetricsCompleted::class]);

    Event::listen(JobProcessing::class, JobProcessingListener::class);
    Event::listen(JobProcessed::class, JobProcessedListener::class);
});

it('keeps the start time of a sync job that dispatches another sync job', function () {
    dispatch_sync(new OuterSyncDispatchingJob);

    $durations = [];
    $jobIds = [];
    Event::assertDispatched(JobMetricsCompleted::class, function (JobMetricsCompleted $event) use (&$durations, &$jobIds) {
        $durations[$event->jobClass] = $event->durationMs;
        $jobIds[] = $event->jobId;

        return true;
    });

    expect($durations[OuterSyncDispatchingJob::class])->toBeGreaterThanOrEqual(150.0)
        ->and($durations[InnerSyncDispatchedJob::class])->toBeGreaterThanOrEqual(50.0)
        ->and($durations[InnerSyncDispatchedJob::class])->toBeLessThan($durations[OuterSyncDispatchingJob::class])
        ->and(array_unique($jobIds))->toHaveCount(2)
        ->and($jobIds)->not->toContain('');
});
