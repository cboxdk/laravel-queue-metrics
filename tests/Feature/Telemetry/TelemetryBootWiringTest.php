<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueMetrics\Tests\Feature\Telemetry;

use Cbox\LaravelQueueMetrics\Events\BaselineRecalculated;
use Cbox\LaravelQueueMetrics\Events\HealthScoreChanged;
use Cbox\LaravelQueueMetrics\Events\JobMetricsDebounced;
use Cbox\LaravelQueueMetrics\Events\QueueDepthThresholdExceeded;
use Cbox\LaravelQueueMetrics\Events\WorkerEfficiencyChanged;
use Cbox\LaravelQueueMetrics\Telemetry\Contracts\ProvidesTelemetrySnapshot;
use Cbox\LaravelQueueMetrics\Tests\TestCase;
use Cbox\Telemetry\Facades\Telemetry;
use Cbox\Telemetry\TelemetryManager;
use Cbox\Telemetry\TelemetryServiceProvider;
use Illuminate\Support\Facades\Event;
use Mockery;

final class TelemetryBootWiringTest extends TestCase
{
    protected function setUp(): void
    {
        if (! class_exists(TelemetryManager::class)) {
            $this->markTestSkipped('cboxdk/laravel-telemetry is not installed (Laravel 12+ only)');
        }

        parent::setUp();
    }

    protected function getPackageProviders($app)
    {
        return [TelemetryServiceProvider::class, ...parent::getPackageProviders($app)];
    }

    public function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('queue-metrics.enabled', true);
        $app['config']->set('queue-metrics.storage.driver', 'database');
        $app['config']->set('telemetry.enabled', true);
        $app['config']->set('telemetry.store', 'array');
    }

    public function test_it_subscribes_to_package_events_at_boot(): void
    {
        foreach ([
            HealthScoreChanged::class,
            QueueDepthThresholdExceeded::class,
            JobMetricsDebounced::class,
            WorkerEfficiencyChanged::class,
            BaselineRecalculated::class,
        ] as $event) {
            $this->assertTrue(Event::hasListeners($event), "No listener registered for {$event}");
        }
    }

    public function test_it_registers_the_telemetry_provider_with_the_manager(): void
    {
        $snapshot = Mockery::mock(ProvidesTelemetrySnapshot::class);
        $snapshot->shouldReceive('snapshot')->andReturn([
            'queues' => [],
            'workers' => [
                'count' => ['total' => 1, 'active' => 1, 'idle' => 0],
                'utilization' => ['current_busy_percent' => 100.0, 'lifetime_busy_percent' => 50.0],
            ],
            'baselines' => [],
        ]);

        $this->app->instance(ProvidesTelemetrySnapshot::class, $snapshot);

        $families = $this->app->make(TelemetryManager::class)->collect();
        $names = array_map(fn ($family) => $family->name(), $families);

        $this->assertContains('queue_metrics.workers.count', $names);
    }

    public function test_it_binds_a_default_snapshot_implementation(): void
    {
        $this->assertTrue($this->app->bound(ProvidesTelemetrySnapshot::class));
    }

    public function test_dispatched_domain_events_reach_telemetry(): void
    {
        $fake = Telemetry::fake();

        event(new HealthScoreChanged('redis', 'default', 45.0, 80.0, 'degraded'));

        $fake->assertCounterIncremented('queue_metrics.health.changes', [
            'connection' => 'redis',
            'queue' => 'default',
            'severity' => 'critical',
        ]);
    }
}
