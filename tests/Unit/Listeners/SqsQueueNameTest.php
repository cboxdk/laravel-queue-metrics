<?php

declare(strict_types=1);

use Cbox\LaravelQueueMetrics\Actions\RecordWorkerHeartbeatAction;
use Cbox\LaravelQueueMetrics\Enums\WorkerState;
use Cbox\LaravelQueueMetrics\Events\JobMetricsCompleted;
use Cbox\LaravelQueueMetrics\Events\JobMetricsFailed;
use Cbox\LaravelQueueMetrics\Listeners\JobFailedListener;
use Cbox\LaravelQueueMetrics\Listeners\JobProcessedListener;
use Cbox\LaravelQueueMetrics\Listeners\LoopingListener;
use Cbox\LaravelQueueMetrics\Repositories\Contracts\WorkerHeartbeatRepository;
use Cbox\LaravelQueueMetrics\Support\HeartbeatThrottleCache;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    config([
        'queue-metrics.enabled' => true,
        'queue-metrics.persistence.enabled' => false,
    ]);

    Event::fake([JobMetricsCompleted::class, JobMetricsFailed::class]);
});

function jobOnSqsQueueUrl(): Job
{
    $job = Mockery::mock(Job::class);
    $job->shouldReceive('getJobId')->andReturn('sqs-job');
    $job->shouldReceive('payload')->andReturn(['displayName' => 'App\\Jobs\\SendEmail']);
    $job->shouldReceive('getQueue')->andReturn('https://sqs.us-east-1.amazonaws.com/123456789012/emails');

    return $job;
}

it('records a processed SQS job under the queue name, not the queue URL', function () {
    app(JobProcessedListener::class)->handle(new JobProcessed('sqs', jobOnSqsQueueUrl()));

    Event::assertDispatched(JobMetricsCompleted::class, fn (JobMetricsCompleted $event) => $event->queue === 'emails');
});

it('records a failed SQS job under the queue name, not the queue URL', function () {
    app(JobFailedListener::class)->handle(new JobFailed('sqs', jobOnSqsQueueUrl(), new RuntimeException('boom')));

    Event::assertDispatched(JobMetricsFailed::class, fn (JobMetricsFailed $event) => $event->queue === 'emails');
});

it('records an idle heartbeat for a worker given a queue URL under the queue name', function () {
    config(['queue-metrics.persistence.enabled' => true]);
    HeartbeatThrottleCache::reset();

    $repository = Mockery::mock(WorkerHeartbeatRepository::class);
    $repository->shouldReceive('recordHeartbeat')
        ->once()
        ->withArgs(fn (...$args): bool => $args[2] === 'emails' && $args[3] === WorkerState::IDLE);

    $listener = new LoopingListener(new RecordWorkerHeartbeatAction($repository));
    $listener->handle(new Looping('sqs', 'https://sqs.us-east-1.amazonaws.com/123456789012/emails'));
});
