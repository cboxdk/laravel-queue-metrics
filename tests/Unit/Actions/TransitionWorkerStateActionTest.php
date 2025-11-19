<?php

declare(strict_types=1);

use Carbon\Carbon;
use PHPeek\LaravelQueueMetrics\Actions\TransitionWorkerStateAction;
use PHPeek\LaravelQueueMetrics\Enums\WorkerState;
use PHPeek\LaravelQueueMetrics\Repositories\Contracts\WorkerHeartbeatRepository;

beforeEach(function () {
    $this->repository = Mockery::mock(WorkerHeartbeatRepository::class);
    $this->action = new TransitionWorkerStateAction($this->repository);

    Carbon::setTestNow('2024-01-15 10:30:00');
    config(['queue-metrics.enabled' => true]);
});

afterEach(function () {
    Carbon::setTestNow();
    Mockery::close();
});

it('transitions worker to new state with default transition time', function () {
    $this->repository->shouldReceive('transitionState')
        ->once()
        ->with(
            'worker-123',
            WorkerState::BUSY,
            Mockery::type(Carbon::class),
        );

    $this->action->execute(
        workerId: 'worker-123',
        newState: WorkerState::BUSY,
    );
})->group('functional');

it('transitions worker to new state with specific transition time', function () {
    $transitionTime = Carbon::parse('2024-01-15 14:00:00');

    $this->repository->shouldReceive('transitionState')
        ->once()
        ->with(
            'worker-456',
            WorkerState::IDLE,
            $transitionTime,
        );

    $this->action->execute(
        workerId: 'worker-456',
        newState: WorkerState::IDLE,
        transitionTime: $transitionTime,
    );
})->group('functional');

it('does nothing when metrics are disabled', function () {
    config(['queue-metrics.enabled' => false]);

    $this->repository->shouldNotReceive('transitionState');

    $this->action->execute(
        workerId: 'worker-123',
        newState: WorkerState::BUSY,
    );
})->group('functional');

it('handles transition to idle state', function () {
    $this->repository->shouldReceive('transitionState')
        ->once()
        ->with(
            'worker-789',
            WorkerState::IDLE,
            Mockery::type(Carbon::class),
        );

    $this->action->execute(
        workerId: 'worker-789',
        newState: WorkerState::IDLE,
    );
})->group('functional');

it('handles transition to busy state', function () {
    $this->repository->shouldReceive('transitionState')
        ->once()
        ->with(
            'worker-abc',
            WorkerState::BUSY,
            Mockery::type(Carbon::class),
        );

    $this->action->execute(
        workerId: 'worker-abc',
        newState: WorkerState::BUSY,
    );
})->group('functional');

it('handles transition to terminated state', function () {
    $this->repository->shouldReceive('transitionState')
        ->once()
        ->with(
            'worker-xyz',
            WorkerState::STOPPED,
            Mockery::type(Carbon::class),
        );

    $this->action->execute(
        workerId: 'worker-xyz',
        newState: WorkerState::STOPPED,
    );
})->group('functional');

it('uses current time when transition time is not provided', function () {
    Carbon::setTestNow('2024-01-15 16:45:30');

    $this->repository->shouldReceive('transitionState')
        ->once()
        ->with(
            'worker-test',
            WorkerState::BUSY,
            Mockery::on(function ($time) {
                return $time instanceof Carbon
                    && $time->timestamp === Carbon::parse('2024-01-15 16:45:30')->timestamp;
            }),
        );

    $this->action->execute(
        workerId: 'worker-test',
        newState: WorkerState::BUSY,
    );
})->group('functional');

it('handles worker IDs with special characters', function () {
    $this->repository->shouldReceive('transitionState')
        ->once()
        ->with(
            'worker-123-abc-xyz',
            WorkerState::IDLE,
            Mockery::type(Carbon::class),
        );

    $this->action->execute(
        workerId: 'worker-123-abc-xyz',
        newState: WorkerState::IDLE,
    );
})->group('functional');
