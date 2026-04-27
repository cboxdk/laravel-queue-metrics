<?php

declare(strict_types=1);

use Carbon\Carbon;
use Cbox\LaravelQueueMetrics\Actions\RecordJobDebouncedAction;
use Cbox\LaravelQueueMetrics\Actions\RecordWorkerHeartbeatAction;
use Cbox\LaravelQueueMetrics\Enums\WorkerState;
use Cbox\LaravelQueueMetrics\Events\JobMetricsDebounced;
use Cbox\LaravelQueueMetrics\Listeners\JobDebouncedListener;
use Cbox\LaravelQueueMetrics\Repositories\Contracts\JobMetricsRepository;
use Cbox\LaravelQueueMetrics\Repositories\Contracts\WorkerHeartbeatRepository;
use Cbox\LaravelQueueMetrics\Support\DebouncedJobTracker;
use Illuminate\Contracts\Queue\Job as QueueJob;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    DebouncedJobTracker::flush();

    $this->jobMetricsRepository = Mockery::mock(JobMetricsRepository::class);
    $this->workerHeartbeatRepository = Mockery::mock(WorkerHeartbeatRepository::class);

    $this->recordDebounced = new RecordJobDebouncedAction($this->jobMetricsRepository);
    $this->recordHeartbeat = new RecordWorkerHeartbeatAction($this->workerHeartbeatRepository);

    $this->listener = new JobDebouncedListener(
        $this->recordDebounced,
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

it('marks the job as debounced in the tracker', function () {
    Event::fake([JobMetricsDebounced::class]);

    $event = createDebouncedEvent('job-123', 'App\Jobs\SyncData', 'redis', 'default');

    $this->jobMetricsRepository->shouldReceive('recordDebounced')->once();
    $this->workerHeartbeatRepository->shouldReceive('recordHeartbeat')->once();

    $this->listener->handle($event);

    // DebouncedJobTracker::mark() was called, but wasDebounced() hasn't been
    // called yet so the mark should still be there
    expect(DebouncedJobTracker::wasDebounced('job-123'))->toBeTrue();
})->group('functional');

it('records debounced metric via repository', function () {
    Event::fake([JobMetricsDebounced::class]);

    $event = createDebouncedEvent('job-456', 'App\Jobs\ProcessOrder', 'redis', 'orders');

    $this->jobMetricsRepository->shouldReceive('recordDebounced')
        ->once()
        ->with(
            'job-456',
            'App\Jobs\ProcessOrder',
            'redis',
            'orders',
            Mockery::type(Carbon::class),
        );

    $this->workerHeartbeatRepository->shouldReceive('recordHeartbeat')->once();

    $this->listener->handle($event);
})->group('functional');

it('dispatches JobMetricsDebounced event', function () {
    Event::fake([JobMetricsDebounced::class]);

    $event = createDebouncedEvent('job-789', 'App\Jobs\SendEmail', 'sqs', 'emails');

    $this->jobMetricsRepository->shouldReceive('recordDebounced')->once();
    $this->workerHeartbeatRepository->shouldReceive('recordHeartbeat')->once();

    $this->listener->handle($event);

    Event::assertDispatched(JobMetricsDebounced::class, function ($e) {
        return $e->jobId === 'job-789'
            && $e->jobClass === 'App\Jobs\SendEmail'
            && $e->connection === 'sqs'
            && $e->queue === 'emails';
    });
})->group('functional');

it('records worker heartbeat as idle', function () {
    Event::fake([JobMetricsDebounced::class]);

    $event = createDebouncedEvent('job-abc', 'App\Jobs\Test', 'redis', 'default');

    $this->jobMetricsRepository->shouldReceive('recordDebounced')->once();

    $this->workerHeartbeatRepository->shouldReceive('recordHeartbeat')
        ->once()
        ->withArgs(function ($workerId, $connection, $queue, $state) {
            return is_string($workerId)
                && $connection === 'redis'
                && $queue === 'default'
                && $state === WorkerState::IDLE;
        });

    $this->listener->handle($event);
})->group('functional');

it('skips persistence when disabled', function () {
    Event::fake([JobMetricsDebounced::class]);
    config(['queue-metrics.persistence.enabled' => false]);

    $event = createDebouncedEvent('job-skip', 'App\Jobs\Test', 'redis', 'default');

    $this->jobMetricsRepository->shouldNotReceive('recordDebounced');
    $this->workerHeartbeatRepository->shouldNotReceive('recordHeartbeat');

    $this->listener->handle($event);

    // Event should still be dispatched (it's for downstream consumers, not persistence)
    Event::assertDispatched(JobMetricsDebounced::class);
})->group('functional');

/**
 * Helper to create a JobDebounced-like event object.
 * Since Illuminate\Queue\Events\JobDebounced may not exist in test environment
 * (Laravel < 13.6), we create a compatible anonymous class.
 */
function createDebouncedEvent(string $jobId, string $jobClass, string $connectionName, string $queue): object
{
    $job = Mockery::mock(QueueJob::class);
    $job->shouldReceive('getJobId')->andReturn($jobId);
    $job->shouldReceive('payload')->andReturn(['displayName' => $jobClass]);
    $job->shouldReceive('getQueue')->andReturn($queue);

    $command = new stdClass;

    return new class($connectionName, $job, $command)
    {
        public function __construct(
            public $connectionName,
            public $job,
            public $command,
        ) {}
    };
}
