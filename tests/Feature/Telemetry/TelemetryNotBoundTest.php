<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueMetrics\Tests\Feature\Telemetry;

use Cbox\LaravelQueueMetrics\Events\HealthScoreChanged;
use Cbox\LaravelQueueMetrics\Tests\TestCase;
use Illuminate\Support\Facades\Event;

/**
 * The telemetry classes are autoloadable in this test suite, but the
 * TelemetryServiceProvider is deliberately NOT registered — the state a
 * host app is in with `dont-discover` or a stale package cache. The
 * integration must stay fully inert instead of subscribing listeners
 * that would throw a BindingResolutionException on the first event.
 */
final class TelemetryNotBoundTest extends TestCase
{
    public function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('queue-metrics.enabled', true);
        $app['config']->set('queue-metrics.storage.driver', 'database');
    }

    public function test_it_does_not_subscribe_when_the_telemetry_manager_is_not_bound(): void
    {
        $this->assertFalse(Event::hasListeners(HealthScoreChanged::class));
    }

    public function test_dispatching_a_domain_event_does_not_throw(): void
    {
        event(new HealthScoreChanged('redis', 'default', 45.0, 80.0, 'degraded'));

        $this->assertTrue(true);
    }
}
