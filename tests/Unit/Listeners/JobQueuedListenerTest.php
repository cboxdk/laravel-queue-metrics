<?php

declare(strict_types=1);

use Carbon\Carbon;
use Cbox\LaravelQueueMetrics\Listeners\JobQueuedListener;
use Cbox\LaravelQueueMetrics\Repositories\Contracts\JobMetricsRepository;
use Illuminate\Queue\Events\JobQueued;

beforeEach(function () {
    config(['queue-metrics.persistence.enabled' => true]);

    $this->repository = Mockery::mock(JobMetricsRepository::class);
    $this->listener = new JobQueuedListener($this->repository);
});

function queuedEventFor(?string $eventQueue, ?string $jobQueue): JobQueued
{
    $job = new stdClass;
    $job->queue = $jobQueue;

    $event = Mockery::mock(JobQueued::class);
    $event->connectionName = 'redis';
    $event->queue = $eventQueue;
    $event->job = $job;

    return $event;
}

function expectRecordedQueue(Mockery\MockInterface $repository, string $expectedQueue): void
{
    $repository->shouldReceive('recordQueuedAt')->once()->withArgs(
        function (string $jobClass, string $connection, string $queue, Carbon $queuedAt) use ($expectedQueue): bool {
            return $queue === $expectedQueue && $connection === 'redis';
        }
    );
}

it('records the queue carried by the event when the job instance has none', function () {
    expectRecordedQueue($this->repository, 'reports');

    $this->listener->handle(queuedEventFor(eventQueue: 'reports', jobQueue: null));
});

it('falls back to the job instance queue when the event carries none', function () {
    expectRecordedQueue($this->repository, 'emails');

    $this->listener->handle(queuedEventFor(eventQueue: null, jobQueue: 'emails'));
});

it('prefers the event queue over the job instance queue', function () {
    expectRecordedQueue($this->repository, 'resolved');

    $this->listener->handle(queuedEventFor(eventQueue: 'resolved', jobQueue: 'stale'));
});

it('falls back to default when neither the event nor the job carries a queue', function () {
    expectRecordedQueue($this->repository, 'default');

    $this->listener->handle(queuedEventFor(eventQueue: null, jobQueue: null));
});
