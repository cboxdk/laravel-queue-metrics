<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueMetrics\Tests\Feature\Telemetry;

use Cbox\LaravelQueueMetrics\Events\HealthScoreChanged;
use Cbox\LaravelQueueMetrics\Tests\TestCase;
use Cbox\Telemetry\TelemetryManager;
use Cbox\Telemetry\TelemetryServiceProvider;
use Illuminate\Support\Facades\Event;

final class TelemetryDisabledTest extends TestCase
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
        $app['config']->set('queue-metrics.telemetry.enabled', false);
        $app['config']->set('telemetry.enabled', true);
        $app['config']->set('telemetry.store', 'array');
    }

    public function test_it_does_not_subscribe_to_package_events_when_disabled(): void
    {
        $this->assertFalse(Event::hasListeners(HealthScoreChanged::class));
    }
}
