<?php

declare(strict_types=1);

use Cbox\LaravelQueueMetrics\Actions\RecordJobCompletionAction;
use Cbox\LaravelQueueMetrics\Actions\RecordWorkerHeartbeatAction;
use Cbox\LaravelQueueMetrics\Events\JobMetricsCompleted;
use Cbox\LaravelQueueMetrics\Listeners\JobProcessedListener;
use Cbox\LaravelQueueMetrics\Repositories\Contracts\JobMetricsRepository;
use Cbox\LaravelQueueMetrics\Repositories\Contracts\WorkerHeartbeatRepository;
use Cbox\LaravelQueueMetrics\Support\DebouncedJobTracker;
use Illuminate\Contracts\Queue\Job as QueueJob;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    DebouncedJobTracker::flush();

    $this->jobMetricsRepository = Mockery::mock(JobMetricsRepository::class);
    $this->workerHeartbeatRepository = Mockery::mock(WorkerHeartbeatRepository::class);

    $this->recordCompletion = new RecordJobCompletionAction($this->jobMetricsRepository);
    $this->recordHeartbeat = new RecordWorkerHeartbeatAction($this->workerHeartbeatRepository);

    $this->listener = new JobProcessedListener(
        $this->recordCompletion,
        $this->recordHeartbeat,
    );

    config([
        'queue-metrics.enabled' => true,
        'queue-metrics.persistence.enabled' => true,
    ]);
});

afterEach(function () {
    DebouncedJobTracker::flush();
    Mockery::close();
});

it('skips performance metrics for debounced jobs', function () {
    Event::fake([JobMetricsCompleted::class]);

    $job = Mockery::mock(QueueJob::class);
    $job->shouldReceive('getJobId')->andReturn('job-debounced');
    $job->shouldReceive('payload')->andReturn([
        'displayName' => 'App\Jobs\SyncData',
        'pushedAt' => microtime(true),
    ]);
    $job->shouldReceive('getQueue')->andReturn('default');

    DebouncedJobTracker::mark('job-debounced');

    $event = new JobProcessed('redis', $job);

    $this->jobMetricsRepository->shouldNotReceive('recordCompletion');
    $this->workerHeartbeatRepository->shouldNotReceive('recordHeartbeat');

    $this->listener->handle($event);

    Event::assertNotDispatched(JobMetricsCompleted::class);
})->group('functional');

it('records performance metrics for normal jobs', function () {
    Event::fake([JobMetricsCompleted::class]);

    $job = Mockery::mock(QueueJob::class);
    $job->shouldReceive('getJobId')->andReturn('job-normal');
    $job->shouldReceive('payload')->andReturn([
        'displayName' => 'App\Jobs\ProcessOrder',
        'pushedAt' => microtime(true),
    ]);
    $job->shouldReceive('getQueue')->andReturn('default');

    // NOT marked as debounced

    $event = new JobProcessed('redis', $job);

    $this->jobMetricsRepository->shouldReceive('recordCompletion')->once();
    $this->workerHeartbeatRepository->shouldReceive('recordHeartbeat')->once();

    $this->listener->handle($event);

    Event::assertDispatched(JobMetricsCompleted::class);
})->group('functional');
