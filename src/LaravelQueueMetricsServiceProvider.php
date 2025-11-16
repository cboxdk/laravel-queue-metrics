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
use PHPeek\LaravelQueueMetrics\Listeners\JobFailedListener;
use PHPeek\LaravelQueueMetrics\Listeners\JobProcessedListener;
use PHPeek\LaravelQueueMetrics\Listeners\JobProcessingListener;
use PHPeek\LaravelQueueMetrics\Repositories\Contracts\BaselineRepository;
use PHPeek\LaravelQueueMetrics\Repositories\Contracts\JobMetricsRepository;
use PHPeek\LaravelQueueMetrics\Repositories\Contracts\QueueMetricsRepository;
use PHPeek\LaravelQueueMetrics\Repositories\Contracts\WorkerRepository;
use PHPeek\LaravelQueueMetrics\Repositories\RedisBaselineRepository;
use PHPeek\LaravelQueueMetrics\Repositories\RedisJobMetricsRepository;
use PHPeek\LaravelQueueMetrics\Repositories\RedisQueueMetricsRepository;
use PHPeek\LaravelQueueMetrics\Repositories\RedisWorkerRepository;
use PHPeek\LaravelQueueMetrics\Services\MetricsQueryService;
use PHPeek\LaravelQueueMetrics\Utilities\PercentileCalculator;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

final class LaravelQueueMetricsServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('queue-metrics')
            ->hasConfigFile('queue-metrics');
    }

    public function packageRegistered(): void
    {
        // Register repositories based on configured driver
        $this->app->singleton(JobMetricsRepository::class, function ($app) {
            return match (config('queue-metrics.storage.driver', 'redis')) {
                'redis' => new RedisJobMetricsRepository(),
                default => new RedisJobMetricsRepository(),
            };
        });

        $this->app->singleton(QueueMetricsRepository::class, function ($app) {
            return match (config('queue-metrics.storage.driver', 'redis')) {
                'redis' => new RedisQueueMetricsRepository(),
                default => new RedisQueueMetricsRepository(),
            };
        });

        $this->app->singleton(WorkerRepository::class, function ($app) {
            return match (config('queue-metrics.storage.driver', 'redis')) {
                'redis' => new RedisWorkerRepository(),
                default => new RedisWorkerRepository(),
            };
        });

        $this->app->singleton(BaselineRepository::class, function ($app) {
            return match (config('queue-metrics.storage.driver', 'redis')) {
                'redis' => new RedisBaselineRepository(),
                default => new RedisBaselineRepository(),
            };
        });

        // Register utilities
        $this->app->singleton(PercentileCalculator::class);

        // Register actions
        $this->app->singleton(RecordJobStartAction::class);
        $this->app->singleton(RecordJobCompletionAction::class);
        $this->app->singleton(RecordJobFailureAction::class);
        $this->app->singleton(CalculateJobMetricsAction::class);

        // Register services
        $this->app->singleton(MetricsQueryService::class);
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
