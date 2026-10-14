<?php

declare(strict_types=1);

use Cbox\LaravelQueueMetrics\Events\JobMetricsCompleted;
use Cbox\LaravelQueueMetrics\Events\JobMetricsFailed;
use Cbox\LaravelQueueMetrics\Listeners\JobFailedListener;
use Cbox\LaravelQueueMetrics\Listeners\JobProcessedListener;
use Cbox\LaravelQueueMetrics\Listeners\JobProcessingListener;
use Cbox\LaravelQueueMetrics\Repositories\Contracts\JobMetricsRepository;
use Cbox\LaravelQueueMetrics\Repositories\Contracts\WorkerHeartbeatRepository;
use Cbox\LaravelQueueMetrics\Support\JobStartTimeCache;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;

class FailsItselfWithoutThrowingJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        $this->fail(new RuntimeException('rejected by the job itself'));
    }
}

beforeEach(function () {
    config([
        'queue-metrics.enabled' => true,
        'queue-metrics.persistence.enabled' => true,
    ]);

    JobStartTimeCache::reset();
    Event::fake([JobMetricsCompleted::class, JobMetricsFailed::class]);

    $this->jobMetricsRepository = Mockery::mock(JobMetricsRepository::class);
    $this->workerHeartbeatRepository = Mockery::mock(WorkerHeartbeatRepository::class);

    $this->app->instance(JobMetricsRepository::class, $this->jobMetricsRepository);
    $this->app->instance(WorkerHeartbeatRepository::class, $this->workerHeartbeatRepository);
});

it('records a job that failed itself once, as a failure and not also as a completion', function () {
    $job = Mockery::mock(Job::class);
    $job->shouldReceive('getJobId')->andReturn('self-failed');
    $job->shouldReceive('payload')->andReturn(['displayName' => 'App\\Jobs\\Rejecting']);
    $job->shouldReceive('getQueue')->andReturn('default');
    $job->shouldReceive('hasFailed')->andReturn(true);

    $this->jobMetricsRepository->shouldReceive('recordStart')->once();
    $this->jobMetricsRepository->shouldReceive('recordFailure')->once();
    $this->jobMetricsRepository->shouldNotReceive('recordCompletion');
    $this->workerHeartbeatRepository->shouldReceive('recordHeartbeat')->twice();

    app(JobProcessingListener::class)->handle(new JobProcessing('redis', $job));
    app(JobFailedListener::class)->handle(new JobFailed('redis', $job, new RuntimeException('rejected')));
    app(JobProcessedListener::class)->handle(new JobProcessed('redis', $job));

    Event::assertDispatchedTimes(JobMetricsFailed::class, 1);
    Event::assertNotDispatched(JobMetricsCompleted::class);
});

it('does not count a job that calls fail() as completed when Laravel raises JobProcessed after JobFailed', function () {
    Event::listen(JobProcessing::class, JobProcessingListener::class);
    Event::listen(JobProcessed::class, JobProcessedListener::class);
    Event::listen(JobFailed::class, JobFailedListener::class);

    $this->jobMetricsRepository->shouldReceive('recordStart')->once();
    $this->jobMetricsRepository->shouldReceive('recordFailure')->once();
    $this->jobMetricsRepository->shouldNotReceive('recordCompletion');
    $this->workerHeartbeatRepository->shouldReceive('recordHeartbeat')->twice();

    $jobProcessedRaised = false;
    Event::listen(JobProcessed::class, function () use (&$jobProcessedRaised): void {
        $jobProcessedRaised = true;
    });

    dispatch_sync(new FailsItselfWithoutThrowingJob);

    expect($jobProcessedRaised)->toBeTrue();
    Event::assertDispatchedTimes(JobMetricsFailed::class, 1);
    Event::assertNotDispatched(JobMetricsCompleted::class);
});
