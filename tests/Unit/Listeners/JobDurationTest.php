<?php

declare(strict_types=1);

use Cbox\LaravelQueueMetrics\Events\JobMetricsCompleted;
use Cbox\LaravelQueueMetrics\Events\JobMetricsFailed;
use Cbox\LaravelQueueMetrics\Listeners\JobFailedListener;
use Cbox\LaravelQueueMetrics\Listeners\JobProcessedListener;
use Cbox\LaravelQueueMetrics\Listeners\JobProcessingListener;
use Cbox\LaravelQueueMetrics\Support\JobStartTimeCache;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    config([
        'queue-metrics.enabled' => true,
        'queue-metrics.persistence.enabled' => false,
    ]);

    JobStartTimeCache::reset();
    Event::fake([JobMetricsCompleted::class, JobMetricsFailed::class]);
});

function durationTestJob(string $jobId, array $payload = []): Job
{
    $job = Mockery::mock(Job::class);
    $job->shouldReceive('getJobId')->andReturn($jobId);
    $job->shouldReceive('payload')->andReturn(['displayName' => 'App\\Jobs\\TimedJob', ...$payload]);
    $job->shouldReceive('getQueue')->andReturn('default');

    return $job;
}

function recordedDurationMs(): float
{
    $durationMs = null;

    Event::assertDispatched(JobMetricsCompleted::class, function (JobMetricsCompleted $event) use (&$durationMs) {
        $durationMs = $event->durationMs;

        return true;
    });

    return $durationMs;
}

it('measures duration from when processing started when the payload has no pushedAt', function () {
    $job = durationTestJob('job-1');

    app(JobProcessingListener::class)->handle(new JobProcessing('redis', $job));
    usleep(150_000);
    app(JobProcessedListener::class)->handle(new JobProcessed('redis', $job));

    expect(recordedDurationMs())->toBeGreaterThanOrEqual(150.0)->toBeLessThan(1_000.0);
});

it('does not count the time a job waited in the queue', function () {
    $job = durationTestJob('job-2', ['pushedAt' => microtime(true) - 30]);

    app(JobProcessingListener::class)->handle(new JobProcessing('redis', $job));
    usleep(150_000);
    app(JobProcessedListener::class)->handle(new JobProcessed('redis', $job));

    expect(recordedDurationMs())->toBeGreaterThanOrEqual(150.0)->toBeLessThan(1_000.0);
});

it('records the run time of a failed job', function () {
    $job = durationTestJob('job-3', ['pushedAt' => microtime(true) - 30]);

    app(JobProcessingListener::class)->handle(new JobProcessing('redis', $job));
    usleep(150_000);
    app(JobFailedListener::class)->handle(new JobFailed('redis', $job, new RuntimeException('boom')));

    Event::assertDispatched(JobMetricsFailed::class, fn (JobMetricsFailed $event) => $event->durationMs >= 150.0 && $event->durationMs < 1_000.0);
    expect(JobStartTimeCache::get('job-3'))->toBeNull();
});

it('reports zero for a job that was never seen starting', function () {
    $job = durationTestJob('job-4', ['pushedAt' => microtime(true) - 30]);

    app(JobProcessedListener::class)->handle(new JobProcessed('redis', $job));

    expect(recordedDurationMs())->toBeLessThan(50.0);
});

it('removes the start time once the job has been recorded', function () {
    $job = durationTestJob('job-5');

    app(JobProcessingListener::class)->handle(new JobProcessing('redis', $job));

    expect(JobStartTimeCache::get('job-5'))->not->toBeNull();

    app(JobProcessedListener::class)->handle(new JobProcessed('redis', $job));

    expect(JobStartTimeCache::get('job-5'))->toBeNull();
});
