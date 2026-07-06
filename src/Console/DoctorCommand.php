<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueMetrics\Console;

use Cbox\Telemetry\TelemetryManager;
use Illuminate\Console\Command;

/**
 * Console command to diagnose queue metrics configuration and flag
 * conflicting metric exposure setups.
 */
final class DoctorCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'queue-metrics:doctor';

    /**
     * The console command description.
     */
    protected $description = 'Diagnose queue metrics configuration and metric exposure';

    public function handle(): int
    {
        $enabled = (bool) config('queue-metrics.enabled', true);

        $this->components->twoColumnDetail('Queue metrics', $enabled ? '<info>enabled</info>' : '<comment>disabled</comment>');
        $this->components->twoColumnDetail('Persistence', config('queue-metrics.persistence.enabled', true) ? '<info>enabled</info>' : '<comment>disabled</comment>');

        $driver = config('queue-metrics.storage.driver', 'redis');
        $this->components->twoColumnDetail('Storage driver', is_string($driver) ? $driver : 'unknown');

        $prometheusEnabled = (bool) config('queue-metrics.prometheus.enabled', true);
        $this->components->twoColumnDetail('Prometheus exporter', $prometheusEnabled ? '<info>enabled</info>' : '<comment>disabled</comment>');

        $this->components->twoColumnDetail('Telemetry integration', $this->describeTelemetryIntegration());

        if ($enabled && $prometheusEnabled && $this->telemetryIntegrationActive()) {
            $this->newLine();
            $this->components->warn(
                'Queue metrics are exposed twice: the built-in Prometheus exporter and the '
                .'cboxdk/laravel-telemetry integration are both active. Scraping both doubles '
                .'storage and can skew dashboards. Disable one of them: set '
                .'QUEUE_METRICS_PROMETHEUS_ENABLED=false (recommended when laravel-telemetry '
                .'handles your metrics) or QUEUE_METRICS_TELEMETRY_ENABLED=false.'
            );
        }

        return self::SUCCESS;
    }

    private function describeTelemetryIntegration(): string
    {
        if (! class_exists(TelemetryManager::class)) {
            return '<comment>not installed</comment> (composer require cboxdk/laravel-telemetry)';
        }

        if (! $this->getLaravel()->bound(TelemetryManager::class)) {
            return '<comment>inactive</comment> (telemetry service provider is not registered)';
        }

        if (! config('queue-metrics.telemetry.enabled', true)) {
            return '<comment>disabled</comment> (queue-metrics.telemetry.enabled)';
        }

        if (! config('telemetry.enabled', true)) {
            return '<comment>inactive</comment> (telemetry.enabled is false)';
        }

        if (! $this->telemetryPublishesAnything()) {
            return '<comment>idle</comment> (all gauge and event toggles are off)';
        }

        return '<info>active</info>';
    }

    private function telemetryIntegrationActive(): bool
    {
        return class_exists(TelemetryManager::class)
            && $this->getLaravel()->bound(TelemetryManager::class)
            && (bool) config('queue-metrics.telemetry.enabled', true)
            && (bool) config('telemetry.enabled', true)
            && $this->telemetryPublishesAnything();
    }

    private function telemetryPublishesAnything(): bool
    {
        return (bool) config('queue-metrics.telemetry.events', true)
            || (bool) config('queue-metrics.telemetry.gauges.queues', true)
            || (bool) config('queue-metrics.telemetry.gauges.workers', true)
            || (bool) config('queue-metrics.telemetry.gauges.baselines', true);
    }
}
