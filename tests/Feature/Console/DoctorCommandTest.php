<?php

declare(strict_types=1);

use Cbox\Telemetry\TelemetryServiceProvider;
use PHPUnit\Framework\Assert;

beforeEach(function () {
    config([
        'queue-metrics.enabled' => true,
        'queue-metrics.prometheus.enabled' => true,
        'queue-metrics.telemetry.enabled' => true,
    ]);
});

function registerTelemetry(): void
{
    if (! class_exists(TelemetryServiceProvider::class)) {
        Assert::markTestSkipped('cboxdk/laravel-telemetry is not installed (Laravel 12+ only)');
    }

    app()->register(TelemetryServiceProvider::class);

    config([
        'telemetry.enabled' => true,
        'telemetry.store' => 'array',
    ]);
}

it('warns when metrics are exposed via both prometheus and the telemetry integration', function () {
    registerTelemetry();

    $this->artisan('queue-metrics:doctor')
        ->expectsOutputToContain('exposed twice')
        ->assertSuccessful();
})->group('functional');

it('does not warn about double exposure when the telemetry integration is disabled', function () {
    registerTelemetry();
    config(['queue-metrics.telemetry.enabled' => false]);

    $this->artisan('queue-metrics:doctor')
        ->doesntExpectOutputToContain('exposed twice')
        ->assertSuccessful();
})->group('functional');

it('does not warn about double exposure when prometheus is disabled', function () {
    registerTelemetry();
    config(['queue-metrics.prometheus.enabled' => false]);

    $this->artisan('queue-metrics:doctor')
        ->doesntExpectOutputToContain('exposed twice')
        ->assertSuccessful();
})->group('functional');

it('does not warn about double exposure when the host telemetry package is disabled', function () {
    registerTelemetry();
    config(['telemetry.enabled' => false]);

    $this->artisan('queue-metrics:doctor')
        ->doesntExpectOutputToContain('exposed twice')
        ->assertSuccessful();
})->group('functional');

it('does not warn when the telemetry manager is not bound', function () {
    $this->artisan('queue-metrics:doctor')
        ->doesntExpectOutputToContain('exposed twice')
        ->assertSuccessful();
})->group('functional');

it('does not warn when every telemetry publish toggle is off', function () {
    registerTelemetry();
    config([
        'queue-metrics.telemetry.gauges.queues' => false,
        'queue-metrics.telemetry.gauges.workers' => false,
        'queue-metrics.telemetry.gauges.baselines' => false,
        'queue-metrics.telemetry.events' => false,
    ]);

    $this->artisan('queue-metrics:doctor')
        ->doesntExpectOutputToContain('exposed twice')
        ->assertSuccessful();
})->group('functional');

it('reports core configuration state', function () {
    config(['queue-metrics.storage.driver' => 'database']);

    $this->artisan('queue-metrics:doctor')
        ->expectsOutputToContain('database')
        ->assertSuccessful();
})->group('functional');
