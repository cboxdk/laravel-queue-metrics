<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueMetrics\Config;

/**
 * Main configuration class for queue metrics package.
 */
final readonly class QueueMetricsConfig
{
    /**
     * @param  array<string, array<int>>  $windows
     * @param  array<string, int>  $retention
     * @param  array<string, mixed>  $performance
     * @param  array<string, mixed>  $api
     * @param  array<string, mixed>  $prometheus
     * @param  array<string, mixed>  $workerHeartbeat
     */
    public function __construct(
        public bool $enabled,
        public StorageConfig $storage,
        public array $windows,
        public array $retention,
        public array $performance,
        public array $api,
        public array $prometheus,
        public array $workerHeartbeat,
    ) {}

    public static function fromConfig(): self
    {
        /** @var array<string, mixed> */
        $config = config('queue-metrics', []);

        /** @var array<string, array<int>> */
        $windows = is_array($config['windows'] ?? null) ? $config['windows'] : [
            'short' => [60, 300, 900],
            'medium' => [3600],
            'long' => [86400],
        ];

        /** @var array<string, int> */
        $retention = is_array($config['retention'] ?? null) ? $config['retention'] : [
            'raw_metrics' => 3600,
            'aggregated_metrics' => 604800,
            'baselines' => 2592000,
        ];

        /** @var array<string, mixed> */
        $performance = is_array($config['performance'] ?? null) ? $config['performance'] : [
            'batch_size' => 100,
            'percentile_samples' => 1000,
            'baseline_samples' => 100,
        ];

        /** @var array<string, mixed> */
        $api = is_array($config['api'] ?? null) ? $config['api'] : [
            'enabled' => true,
            'prefix' => 'queue-metrics',
            'middleware' => ['api'],
        ];

        /** @var array<string, mixed> */
        $prometheus = is_array($config['prometheus'] ?? null) ? $config['prometheus'] : [
            'enabled' => true,
            'namespace' => 'laravel_queue',
        ];

        /** @var array<string, mixed> */
        $workerHeartbeat = is_array($config['worker_heartbeat'] ?? null) ? $config['worker_heartbeat'] : [
            'stale_threshold' => 60,
            'auto_detect_schedule' => '* * * * *',
        ];

        /** @var array<string, mixed> */
        $storage = is_array($config['storage'] ?? null) ? $config['storage'] : [];

        return new self(
            enabled: is_bool($config['enabled'] ?? null) ? $config['enabled'] : true,
            storage: StorageConfig::fromArray($storage),
            windows: $windows,
            retention: $retention,
            performance: $performance,
            api: $api,
            prometheus: $prometheus,
            workerHeartbeat: $workerHeartbeat,
        );
    }

    public function getRetentionSeconds(string $type): int
    {
        return (int) ($this->retention[$type] ?? 3600);
    }

    public function getBatchSize(): int
    {
        $batchSize = $this->performance['batch_size'] ?? 100;

        return is_numeric($batchSize) ? (int) $batchSize : 100;
    }

    public function getPercentileSamples(): int
    {
        $samples = $this->performance['percentile_samples'] ?? 1000;

        return is_numeric($samples) ? (int) $samples : 1000;
    }

    public function getBaselineSamples(): int
    {
        $samples = $this->performance['baseline_samples'] ?? 100;

        return is_numeric($samples) ? (int) $samples : 100;
    }

    public function getStaleThreshold(): int
    {
        $threshold = $this->workerHeartbeat['stale_threshold'] ?? 60;

        return is_numeric($threshold) ? (int) $threshold : 60;
    }

    public function getPrometheusNamespace(): string
    {
        $namespace = $this->prometheus['namespace'] ?? 'laravel_queue';

        return is_string($namespace) ? $namespace : 'laravel_queue';
    }

    public function isApiEnabled(): bool
    {
        return (bool) ($this->api['enabled'] ?? true);
    }

    public function isPrometheusEnabled(): bool
    {
        return (bool) ($this->prometheus['enabled'] ?? true);
    }
}
