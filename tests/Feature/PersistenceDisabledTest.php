<?php

declare(strict_types=1);

use Cbox\LaravelQueueMetrics\Actions\RecordJobCompletionAction;
use Cbox\LaravelQueueMetrics\Actions\RecordJobFailureAction;
use Cbox\LaravelQueueMetrics\Actions\RecordJobStartAction;
use Cbox\LaravelQueueMetrics\Actions\RecordWorkerHeartbeatAction;
use Cbox\LaravelQueueMetrics\Actions\TransitionWorkerStateAction;
use Cbox\LaravelQueueMetrics\Events\JobMetricsCompleted;
use Cbox\LaravelQueueMetrics\Events\JobMetricsFailed;
use Cbox\LaravelQueueMetrics\Listeners\JobExceptionOccurredListener;
use Cbox\LaravelQueueMetrics\Listeners\JobFailedListener;
use Cbox\LaravelQueueMetrics\Listeners\JobProcessedListener;
use Cbox\LaravelQueueMetrics\Listeners\JobProcessingListener;
use Cbox\LaravelQueueMetrics\Listeners\JobQueuedListener;
use Cbox\LaravelQueueMetrics\Listeners\JobRetryRequestedListener;
use Cbox\LaravelQueueMetrics\Listeners\JobTimedOutListener;
use Cbox\LaravelQueueMetrics\Listeners\LoopingListener;
use Cbox\LaravelQueueMetrics\Listeners\WorkerStoppingListener;
use Cbox\LaravelQueueMetrics\Repositories\Contracts\JobMetricsRepository;
use Cbox\LaravelQueueMetrics\Repositories\Contracts\WorkerHeartbeatRepository;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Queue\Events\JobRetryRequested;
use Illuminate\Queue\Events\JobTimedOut;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    config([
        'queue-metrics.enabled' => true,
        'queue-metrics.persistence.enabled' => false,
    ]);

    $this->jobMetricsRepository = Mockery::mock(JobMetricsRepository::class);
    $this->workerHeartbeatRepository = Mockery::mock(WorkerHeartbeatRepository::class);
});

/*
|--------------------------------------------------------------------------
| Events still fire with persistence disabled
|--------------------------------------------------------------------------
*/

it('fires JobMetricsCompleted event when persistence is disabled', function () {
    Event::fake([JobMetricsCompleted::class]);

    $recordJobCompletion = new RecordJobCompletionAction($this->jobMetricsRepository);
    $recordWorkerHeartbeat = new RecordWorkerHeartbeatAction($this->workerHeartbeatRepository);

    $this->jobMetricsRepository->shouldNotReceive('recordCompletion');
    $this->workerHeartbeatRepository->shouldNotReceive('recordHeartbeat');

    $listener = new JobProcessedListener($recordJobCompletion, $recordWorkerHeartbeat);

    $job = Mockery::mock(\Illuminate\Contracts\Queue\Job::class);
    $job->shouldReceive('getJobId')->andReturn('job-1');
    $job->shouldReceive('payload')->andReturn([
        'displayName' => 'App\Jobs\TestJob',
        'pushedAt' => microtime(true) - 0.1,
    ]);
    $job->shouldReceive('getQueue')->andReturn('default');

    $event = new JobProcessed('redis', $job);
    $listener->handle($event);

    Event::assertDispatched(JobMetricsCompleted::class, function ($e) {
        return $e->jobClass === 'App\Jobs\TestJob'
            && $e->connection === 'redis'
            && $e->queue === 'default';
    });
})->group('functional');

it('fires JobMetricsFailed event when persistence is disabled', function () {
    Event::fake([JobMetricsFailed::class]);

    $recordJobFailure = new RecordJobFailureAction($this->jobMetricsRepository);
    $recordWorkerHeartbeat = new RecordWorkerHeartbeatAction($this->workerHeartbeatRepository);

    $this->jobMetricsRepository->shouldNotReceive('recordFailure');
    $this->workerHeartbeatRepository->shouldNotReceive('recordHeartbeat');

    $listener = new JobFailedListener($recordJobFailure, $recordWorkerHeartbeat);

    $job = Mockery::mock(\Illuminate\Contracts\Queue\Job::class);
    $job->shouldReceive('getJobId')->andReturn('job-2');
    $job->shouldReceive('payload')->andReturn([
        'displayName' => 'App\Jobs\FailingJob',
        'pushedAt' => microtime(true) - 0.05,
    ]);
    $job->shouldReceive('getQueue')->andReturn('default');

    $exception = new RuntimeException('Test failure');
    $event = new JobFailed('redis', $job, $exception);
    $listener->handle($event);

    Event::assertDispatched(JobMetricsFailed::class, function ($e) {
        return $e->jobClass === 'App\Jobs\FailingJob'
            && str_contains($e->exceptionMessage, 'Test failure');
    });
})->group('functional');

/*
|--------------------------------------------------------------------------
| No repository calls with persistence disabled
|--------------------------------------------------------------------------
*/

it('skips repository calls in JobProcessingListener when persistence is disabled', function () {
    $recordJobStart = new RecordJobStartAction($this->jobMetricsRepository);
    $recordWorkerHeartbeat = new RecordWorkerHeartbeatAction($this->workerHeartbeatRepository);

    $this->jobMetricsRepository->shouldNotReceive('recordStart');
    $this->workerHeartbeatRepository->shouldNotReceive('recordHeartbeat');

    $listener = new JobProcessingListener($recordJobStart, $recordWorkerHeartbeat);

    $job = Mockery::mock(\Illuminate\Contracts\Queue\Job::class);
    $job->shouldReceive('getJobId')->andReturn('job-3');
    $job->shouldReceive('payload')->andReturn(['displayName' => 'App\Jobs\TestJob']);
    $job->shouldReceive('getQueue')->andReturn('default');

    $event = new JobProcessing('redis', $job);
    $listener->handle($event);
})->group('functional');

it('skips repository calls in JobQueuedListener when persistence is disabled', function () {
    $this->jobMetricsRepository->shouldNotReceive('recordQueuedAt');

    $listener = new JobQueuedListener($this->jobMetricsRepository);

    $job = new stdClass;
    $job->queue = 'default';

    $event = Mockery::mock(JobQueued::class);
    $event->connectionName = 'redis';
    $event->job = $job;

    $listener->handle($event);
})->group('functional');

it('skips repository calls in JobRetryRequestedListener when persistence is disabled', function () {
    $this->jobMetricsRepository->shouldNotReceive('recordRetryRequested');

    $listener = new JobRetryRequestedListener($this->jobMetricsRepository);

    $jobStdClass = new stdClass;
    $jobStdClass->id = 'job-4';
    $jobStdClass->connection = 'redis';
    $jobStdClass->queue = 'default';

    $event = Mockery::mock(JobRetryRequested::class);
    $event->job = $jobStdClass;
    $event->shouldReceive('payload')->andReturn([
        'displayName' => 'App\Jobs\RetryableJob',
        'attempts' => 2,
    ]);

    $listener->handle($event);
})->group('functional');

it('skips repository calls in JobTimedOutListener when persistence is disabled', function () {
    $this->jobMetricsRepository->shouldNotReceive('recordTimeout');

    $listener = new JobTimedOutListener($this->jobMetricsRepository);

    $job = Mockery::mock(\Illuminate\Contracts\Queue\Job::class);
    $job->shouldReceive('getJobId')->andReturn('job-5');
    $job->shouldReceive('payload')->andReturn(['displayName' => 'App\Jobs\SlowJob']);
    $job->shouldReceive('getQueue')->andReturn('default');

    $event = new JobTimedOut('redis', $job);
    $listener->handle($event);
})->group('functional');

it('skips repository calls in JobExceptionOccurredListener when persistence is disabled', function () {
    $this->jobMetricsRepository->shouldNotReceive('recordException');

    $listener = new JobExceptionOccurredListener($this->jobMetricsRepository);

    $job = Mockery::mock(\Illuminate\Contracts\Queue\Job::class);
    $job->shouldReceive('getJobId')->andReturn('job-6');
    $job->shouldReceive('payload')->andReturn(['displayName' => 'App\Jobs\BrokenJob']);
    $job->shouldReceive('getQueue')->andReturn('default');

    $exception = new RuntimeException('Something broke');
    $event = new JobExceptionOccurred('redis', $job, $exception);
    $listener->handle($event);
})->group('functional');

it('skips repository calls in WorkerStoppingListener when persistence is disabled', function () {
    $transitionWorkerState = new TransitionWorkerStateAction($this->workerHeartbeatRepository);
    $this->workerHeartbeatRepository->shouldNotReceive('transitionState');

    $listener = new WorkerStoppingListener($transitionWorkerState);

    $event = new WorkerStopping(0);
    $listener->handle($event);
})->group('functional');

it('skips repository calls in LoopingListener when persistence is disabled', function () {
    $recordWorkerHeartbeat = new RecordWorkerHeartbeatAction($this->workerHeartbeatRepository);
    $this->workerHeartbeatRepository->shouldNotReceive('recordHeartbeat');

    $listener = new LoopingListener($recordWorkerHeartbeat);

    $event = new Looping('redis', 'default');
    $listener->handle($event);
})->group('functional');

/*
|--------------------------------------------------------------------------
| Scheduled tasks not registered when persistence disabled
|--------------------------------------------------------------------------
*/

it('does not register scheduled tasks when persistence is disabled', function () {
    config([
        'queue-metrics.enabled' => true,
        'queue-metrics.persistence.enabled' => false,
        'queue-metrics.scheduling.enabled' => true,
    ]);

    $schedule = app(Schedule::class);
    $events = $schedule->events();

    $queueMetricsEvents = array_filter($events, function ($event) {
        return str_contains($event->command ?? '', 'queue-metrics:');
    });

    expect($queueMetricsEvents)->toBeEmpty();
})->group('functional');

/*
|--------------------------------------------------------------------------
| Persistence enabled (default) still works
|--------------------------------------------------------------------------
*/

it('calls repository when persistence is enabled', function () {
    config(['queue-metrics.persistence.enabled' => true]);

    $this->jobMetricsRepository->shouldReceive('recordQueuedAt')->once();

    $listener = new JobQueuedListener($this->jobMetricsRepository);

    $job = new stdClass;
    $job->queue = 'default';

    $event = Mockery::mock(JobQueued::class);
    $event->connectionName = 'redis';
    $event->job = $job;

    $listener->handle($event);
})->group('functional');
