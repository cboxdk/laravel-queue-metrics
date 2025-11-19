<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueMetrics\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPeek\LaravelQueueMetrics\Repositories\Contracts\JobMetricsRepository;
use PHPeek\LaravelQueueMetrics\Tests\Feature\Support\TestJob;
use PHPeek\LaravelQueueMetrics\Tests\TestCase;

/**
 * @group redis
 */
final class EventListenersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        // Skip entire test class if Redis is not available
        if (! getenv('REDIS_AVAILABLE')) {
            $this->markTestSkipped('Requires Redis - run with redis group');
        }

        parent::setUp();
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('queue.default', 'sync');
        $app['config']->set('queue-metrics.enabled', true);
        $app['config']->set('queue-metrics.storage.driver', 'redis');
        $app['config']->set('queue-metrics.storage.connection', 'default');
    }

    public function test_job_queued_event_records_metrics(): void
    {
        // TODO: This test requires actual Redis connection and event listeners
        // Skip for now as it's environment-dependent
        $this->markTestSkipped('Requires Redis connection and full event system');

        // Dispatch a test job
        TestJob::dispatch();

        // Verify JobQueued listener recorded the queued timestamp
        // Note: This works even with sync queue
        $repository = app(JobMetricsRepository::class);
        $metrics = $repository->getMetrics(
            TestJob::class,
            'sync',
            'default'
        );

        // With sync queue, the job is processed immediately
        // so we should see completion metrics
        $this->assertGreaterThan(0, $metrics['total_processed'] ?? 0);
    }

    public function test_job_processing_event_records_start(): void
    {
        Queue::fake();

        TestJob::dispatch();

        // With Queue::fake(), events don't fire
        // We need to test with a real queue worker
        $this->markTestSkipped('Requires real queue worker to test JobProcessing event');
    }

    public function test_metrics_are_recorded_with_sync_queue(): void
    {
        $this->markTestSkipped('Requires Redis connection - run with redis group');

        // This test verifies that metrics ARE recorded even with sync queue
        config(['queue.default' => 'sync']);

        TestJob::dispatch();

        $repository = app(JobMetricsRepository::class);
        $metrics = $repository->getMetrics(
            TestJob::class,
            'sync',
            'default'
        );

        // Verify metrics were recorded
        $this->assertIsArray($metrics);
        $this->assertArrayHasKey('total_processed', $metrics);
    }
}
