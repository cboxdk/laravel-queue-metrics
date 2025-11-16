<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueMetrics;

use Illuminate\Support\Facades\Event;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use PHPeek\LaravelQueueMetrics\Actions\CalculateJobMetricsAction;
use PHPeek\LaravelQueueMetrics\Actions\RecordJobCompletionAction;
use PHPeek\LaravelQueueMetrics\Actions\RecordJobFailureAction;
use PHPeek\LaravelQueueMetrics\Actions\RecordJobStartAction;
use PHPeek\LaravelQueueMetrics\Actions\RecordWorkerHeartbeatAction;
use PHPeek\LaravelQueueMetrics\Actions\TransitionWorkerStateAction;
use PHPeek\LaravelQueueMetrics\Console\DetectStaleWorkersCommand;
use PHPeek\LaravelQueueMetrics\Listeners\JobFailedListener;
use PHPeek\LaravelQueueMetrics\Listeners\JobProcessedListener;
use PHPeek\LaravelQueueMetrics\Listeners\JobProcessingListener;
use PHPeek\LaravelQueueMetrics\Contracts\QueueInspector;
use PHPeek\LaravelQueueMetrics\Repositories\Contracts\BaselineRepository;
use PHPeek\LaravelQueueMetrics\Repositories\Contracts\JobMetricsRepository;
use PHPeek\LaravelQueueMetrics\Repositories\Contracts\QueueMetricsRepository;
use PHPeek\LaravelQueueMetrics\Repositories\Contracts\WorkerHeartbeatRepository;
use PHPeek\LaravelQueueMetrics\Repositories\Contracts\WorkerRepository;
use PHPeek\LaravelQueueMetrics\Repositories\RedisBaselineRepository;
use PHPeek\LaravelQueueMetrics\Repositories\RedisJobMetricsRepository;
use PHPeek\LaravelQueueMetrics\Repositories\RedisQueueMetricsRepository;
use PHPeek\LaravelQueueMetrics\Repositories\RedisWorkerHeartbeatRepository;
use PHPeek\LaravelQueueMetrics\Repositories\RedisWorkerRepository;
use PHPeek\LaravelQueueMetrics\Config\QueueMetricsConfig;
use PHPeek\LaravelQueueMetrics\Config\StorageConfig;
use PHPeek\LaravelQueueMetrics\Services\LaravelQueueInspector;
use PHPeek\LaravelQueueMetrics\Services\MetricsQueryService;
use PHPeek\LaravelQueueMetrics\Services\ServerMetricsService;
use PHPeek\LaravelQueueMetrics\Storage\StorageManager;
use PHPeek\LaravelQueueMetrics\Utilities\PercentileCalculator;
use Gophpeek\SystemMetrics\SystemMetrics;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

final class LaravelQueueMetricsServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('queue-metrics')
            ->hasConfigFile('queue-metrics')
            ->hasRoute('api')
            ->hasMigration('2024_01_01_000001_create_queue_metrics_storage_tables')
            ->hasCommand(DetectStaleWorkersCommand::class);
    }

    public function packageRegistered(): void
    {
        // Register config classes
        $this->app->singleton(QueueMetricsConfig::class, function ($app) {
            return QueueMetricsConfig::fromConfig();
        });

        $this->app->singleton(StorageConfig::class, function ($app) {
            return $app->make(QueueMetricsConfig::class)->storage;
        });

        // Register storage manager
        $this->app->singleton(StorageManager::class, function ($app) {
            return new StorageManager($app->make(StorageConfig::class));
        });

        // Register repositories - all use StorageManager
        $this->app->singleton(JobMetricsRepository::class, function ($app) {
            return new RedisJobMetricsRepository($app->make(StorageManager::class));
        });

        $this->app->singleton(QueueMetricsRepository::class, function ($app) {
            return new RedisQueueMetricsRepository($app->make(StorageManager::class));
        });

        $this->app->singleton(WorkerRepository::class, function ($app) {
            return new RedisWorkerRepository($app->make(StorageManager::class));
        });

        $this->app->singleton(BaselineRepository::class, function ($app) {
            return new RedisBaselineRepository($app->make(StorageManager::class));
        });

        $this->app->singleton(WorkerHeartbeatRepository::class, function ($app) {
            return new RedisWorkerHeartbeatRepository($app->make(StorageManager::class));
        });

        // Register system metrics
        $this->app->singleton(SystemMetrics::class, function () {
            return new SystemMetrics();
        });

        // Register services
        $this->app->singleton(QueueInspector::class, LaravelQueueInspector::class);
        $this->app->singleton(MetricsQueryService::class);
        $this->app->singleton(ServerMetricsService::class);

        // Register utilities
        $this->app->singleton(PercentileCalculator::class);

        // Register actions
        $this->app->singleton(RecordJobStartAction::class);
        $this->app->singleton(RecordJobCompletionAction::class);
        $this->app->singleton(RecordJobFailureAction::class);
        $this->app->singleton(CalculateJobMetricsAction::class);
        $this->app->singleton(RecordWorkerHeartbeatAction::class);
        $this->app->singleton(TransitionWorkerStateAction::class);
    }

    public function packageBooted(): void
    {
        if (! config('queue-metrics.enabled', true)) {
            return;
        }

        // Register event listeners
        Event::listen(JobProcessing::class, JobProcessingListener::class);
        Event::listen(JobProcessed::class, JobProcessedListener::class);
        Event::listen(JobFailed::class, JobFailedListener::class);
    }
}
