<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueMetrics;

use Cbox\LaravelQueueMetrics\Actions\CalculateJobMetricsAction;
use Cbox\LaravelQueueMetrics\Actions\RecordJobCompletionAction;
use Cbox\LaravelQueueMetrics\Actions\RecordJobFailureAction;
use Cbox\LaravelQueueMetrics\Actions\RecordJobStartAction;
use Cbox\LaravelQueueMetrics\Actions\RecordWorkerHeartbeatAction;
use Cbox\LaravelQueueMetrics\Actions\TransitionWorkerStateAction;
use Cbox\LaravelQueueMetrics\Commands\CalculateBaselinesCommand;
use Cbox\LaravelQueueMetrics\Commands\CleanupStaleWorkersCommand;
use Cbox\LaravelQueueMetrics\Config\QueueMetricsConfig;
use Cbox\LaravelQueueMetrics\Config\StorageConfig;
use Cbox\LaravelQueueMetrics\Console\CalculateQueueMetricsCommand;
use Cbox\LaravelQueueMetrics\Console\DetectStaleWorkersCommand;
use Cbox\LaravelQueueMetrics\Console\RecordTrendDataCommand;
use Cbox\LaravelQueueMetrics\Contracts\QueueInspector;
use Cbox\LaravelQueueMetrics\Exceptions\ConfigurationException;
use Cbox\LaravelQueueMetrics\Listeners\JobExceptionOccurredListener;
use Cbox\LaravelQueueMetrics\Listeners\JobFailedListener;
use Cbox\LaravelQueueMetrics\Listeners\JobProcessedListener;
use Cbox\LaravelQueueMetrics\Listeners\JobProcessingListener;
use Cbox\LaravelQueueMetrics\Listeners\JobQueuedListener;
use Cbox\LaravelQueueMetrics\Listeners\JobRetryRequestedListener;
use Cbox\LaravelQueueMetrics\Listeners\JobTimedOutListener;
use Cbox\LaravelQueueMetrics\Listeners\LoopingListener;
use Cbox\LaravelQueueMetrics\Listeners\WorkerStoppingListener;
use Cbox\LaravelQueueMetrics\Repositories\Contracts\BaselineRepository;
use Cbox\LaravelQueueMetrics\Repositories\Contracts\JobMetricsRepository;
use Cbox\LaravelQueueMetrics\Repositories\Contracts\QueueMetricsRepository;
use Cbox\LaravelQueueMetrics\Repositories\Contracts\WorkerHeartbeatRepository;
use Cbox\LaravelQueueMetrics\Repositories\Contracts\WorkerRepository;
use Cbox\LaravelQueueMetrics\Repositories\RedisBaselineRepository;
use Cbox\LaravelQueueMetrics\Repositories\RedisJobMetricsRepository;
use Cbox\LaravelQueueMetrics\Repositories\RedisQueueMetricsRepository;
use Cbox\LaravelQueueMetrics\Repositories\RedisWorkerHeartbeatRepository;
use Cbox\LaravelQueueMetrics\Repositories\RedisWorkerRepository;
use Cbox\LaravelQueueMetrics\Services\JobMetricsQueryService;
use Cbox\LaravelQueueMetrics\Services\LaravelQueueInspector;
use Cbox\LaravelQueueMetrics\Services\OverviewQueryService;
use Cbox\LaravelQueueMetrics\Services\QueueMetricsQueryService;
use Cbox\LaravelQueueMetrics\Services\RedisKeyScannerService;
use Cbox\LaravelQueueMetrics\Services\ServerMetricsService;
use Cbox\LaravelQueueMetrics\Services\WorkerMetricsQueryService;
use Cbox\LaravelQueueMetrics\Support\RedisMetricsStore;
use Cbox\LaravelQueueMetrics\Utilities\PercentileCalculator;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Queue\Events\JobRetryRequested;
use Illuminate\Queue\Events\JobTimedOut;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Support\Facades\Event;
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
            ->hasCommand(CalculateBaselinesCommand::class)
            ->hasCommand(CalculateQueueMetricsCommand::class)
            ->hasCommand(CleanupStaleWorkersCommand::class)
            ->hasCommand(DetectStaleWorkersCommand::class)
            ->hasCommand(RecordTrendDataCommand::class);
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

        // Register Redis metrics store - uses Laravel's Redis connection directly
        $this->app->singleton(RedisMetricsStore::class, function () {
            return new RedisMetricsStore;
        });

        // Register repositories from config (extensible Spatie-style)
        $this->registerRepositoriesFromConfig();

        // Register actions from config (extensible Spatie-style)
        $this->registerActionsFromConfig();

        // Register services
        $this->app->singleton(QueueInspector::class, LaravelQueueInspector::class);
        $this->app->singleton(RedisKeyScannerService::class);
        $this->app->singleton(JobMetricsQueryService::class);
        $this->app->singleton(QueueMetricsQueryService::class);
        $this->app->singleton(WorkerMetricsQueryService::class);
        $this->app->singleton(OverviewQueryService::class);
        $this->app->singleton(Services\Contracts\OverviewQueryInterface::class, OverviewQueryService::class);
        $this->app->singleton(ServerMetricsService::class);
        $this->app->singleton(Services\TrendAnalysisService::class);
        $this->app->singleton(Services\BaselineDeviationService::class);

        // Register utilities
        $this->app->singleton(PercentileCalculator::class);
    }

    /**
     * Register repositories from config - allows users to extend/replace repositories.
     */
    protected function registerRepositoriesFromConfig(): void
    {
        /** @var array<class-string, class-string> $repositories */
        $repositories = config('queue-metrics.repositories', [
            JobMetricsRepository::class => RedisJobMetricsRepository::class,
            QueueMetricsRepository::class => RedisQueueMetricsRepository::class,
            WorkerRepository::class => RedisWorkerRepository::class,
            BaselineRepository::class => RedisBaselineRepository::class,
            WorkerHeartbeatRepository::class => RedisWorkerHeartbeatRepository::class,
        ]);

        foreach ($repositories as $contract => $implementation) {
            $this->app->singleton($contract, $implementation);
        }
    }

    /**
     * Register actions from config - allows users to extend/replace actions.
     */
    protected function registerActionsFromConfig(): void
    {
        /** @var array<string, class-string> $actions */
        $actions = config('queue-metrics.actions', [
            'record_job_start' => RecordJobStartAction::class,
            'record_job_completion' => RecordJobCompletionAction::class,
            'record_job_failure' => RecordJobFailureAction::class,
            'calculate_job_metrics' => CalculateJobMetricsAction::class,
            'record_worker_heartbeat' => RecordWorkerHeartbeatAction::class,
            'transition_worker_state' => TransitionWorkerStateAction::class,
            'record_queue_depth_history' => Actions\RecordQueueDepthHistoryAction::class,
            'record_throughput_history' => Actions\RecordThroughputHistoryAction::class,
            'calculate_baselines' => Actions\CalculateBaselinesAction::class,
        ]);

        foreach ($actions as $key => $actionClass) {
            $this->app->singleton($actionClass);
        }
    }

    public function packageBooted(): void
    {
        if (! config('queue-metrics.enabled', true)) {
            return;
        }

        // Validate critical configuration values
        $this->validateConfiguration();

        // Register job lifecycle event listeners
        Event::listen(JobQueued::class, JobQueuedListener::class);
        Event::listen(JobProcessing::class, JobProcessingListener::class);
        Event::listen(JobProcessed::class, JobProcessedListener::class);
        Event::listen(JobFailed::class, JobFailedListener::class);
        Event::listen(JobRetryRequested::class, JobRetryRequestedListener::class);
        Event::listen(JobTimedOut::class, JobTimedOutListener::class);
        Event::listen(JobExceptionOccurred::class, JobExceptionOccurredListener::class);

        // Register worker lifecycle event listeners
        Event::listen(WorkerStopping::class, WorkerStoppingListener::class);
        Event::listen(Looping::class, LoopingListener::class);

        // Register scheduled tasks
        $this->registerScheduledTasks();
    }

    /**
     * Register scheduled tasks for queue metrics maintenance.
     */
    protected function registerScheduledTasks(): void
    {
        if (! config('queue-metrics.scheduling.enabled', true)) {
            return;
        }

        /** @var int $threshold */
        $threshold = config('queue-metrics.worker_heartbeat.stale_threshold', 60);

        // Schedule stale worker cleanup
        $this->app->booted(function () use ($threshold) {
            $scheduler = $this->app->make(\Illuminate\Console\Scheduling\Schedule::class);

            if (config('queue-metrics.scheduling.tasks.cleanup_stale_workers', true)) {
                $scheduler->command('queue-metrics:cleanup-stale-workers', [
                    '--threshold' => $threshold,
                ])->everyMinute();
            }

            if (config('queue-metrics.scheduling.tasks.calculate_baselines', true)) {
                // Schedule adaptive baseline calculation
                $this->scheduleAdaptiveBaselineCalculation($scheduler);
            }

            if (config('queue-metrics.scheduling.tasks.calculate_queue_metrics', true)) {
                // Schedule queue metrics calculation (aggregate job metrics into queue metrics)
                $scheduler->command('queue-metrics:calculate')
                    ->everyMinute()
                    ->withoutOverlapping();
            }

            if (config('queue-metrics.scheduling.tasks.record_trends', true)) {
                // Schedule trend data recording (every minute for real-time trends)
                $scheduler->command('queue-metrics:record-trends')
                    ->everyMinute()
                    ->withoutOverlapping();
            }
        });
    }

    /**
     * Schedule baseline calculation with adaptive intervals.
     */
    protected function scheduleAdaptiveBaselineCalculation(\Illuminate\Console\Scheduling\Schedule $scheduler): void
    {
        // Start with most frequent interval (1 minute)
        // The command itself will determine if it should actually run
        $scheduler->command('queue-metrics:calculate-baselines')
            ->everyMinute()
            ->skip(function () {
                // Check if we should skip based on baseline confidence
                // This allows adaptive scheduling without multiple schedule entries
                $baselineRepository = $this->app->make(
                    \Cbox\LaravelQueueMetrics\Repositories\Contracts\BaselineRepository::class
                );
                $queueRepository = $this->app->make(
                    \Cbox\LaravelQueueMetrics\Repositories\Contracts\QueueMetricsRepository::class
                );

                $queues = $queueRepository->listQueues();

                if (empty($queues)) {
                    return false; // Don't skip if no queues yet
                }

                // Get first queue's baseline to determine interval
                $firstQueue = reset($queues);
                $baseline = $baselineRepository->getBaseline(
                    $firstQueue['connection'],
                    $firstQueue['queue']
                );

                if ($baseline === null) {
                    return false; // Don't skip if no baseline exists
                }

                // Calculate minutes since last calculation
                $minutesSinceCalculation = $baseline->calculatedAt->diffInMinutes(now());

                // Determine required interval based on confidence
                /** @var array{low_confidence: int, medium_confidence: int, high_confidence: int, very_high_confidence: int} $intervals */
                $intervals = config('queue-metrics.baseline.intervals') ?? [
                    'low_confidence' => 5,
                    'medium_confidence' => 10,
                    'high_confidence' => 30,
                    'very_high_confidence' => 60,
                ];

                $requiredInterval = match (true) {
                    $baseline->confidenceScore >= 0.9 => $intervals['very_high_confidence'],
                    $baseline->confidenceScore >= 0.7 => $intervals['high_confidence'],
                    $baseline->confidenceScore >= 0.5 => $intervals['medium_confidence'],
                    default => $intervals['low_confidence'],
                };

                // Skip if not enough time has passed
                return $minutesSinceCalculation < $requiredInterval;
            })
            ->runInBackground();
    }

    /**
     * Validate critical configuration values to prevent runtime errors.
     *
     * @throws ConfigurationException
     */
    protected function validateConfiguration(): void
    {
        // Validate storage configuration
        $connection = config('queue-metrics.storage.connection');
        if (! is_string($connection) || empty($connection)) {
            throw ConfigurationException::invalidConnection(is_string($connection) ? $connection : '');
        }

        $prefix = config('queue-metrics.storage.prefix');
        if (! is_string($prefix) || empty($prefix)) {
            throw ConfigurationException::invalidPrefix(is_string($prefix) ? $prefix : '');
        }

        // Validate baseline configuration
        $slidingWindowDays = config('queue-metrics.baseline.sliding_window_days');
        if (! is_int($slidingWindowDays) && ! is_numeric($slidingWindowDays)) {
            // Treat non-numeric as 0 to trigger the next validation
            $slidingWindowDays = 0;
        }

        if ((int) $slidingWindowDays < 1) {
            throw ConfigurationException::invalidSlidingWindow((int) $slidingWindowDays);
        }

        $decayFactor = config('queue-metrics.baseline.decay_factor');
        if (! is_float($decayFactor) && ! is_numeric($decayFactor)) {
            // Treat non-numeric as -1 to trigger the next validation
            $decayFactor = -1;
        }

        if ((float) $decayFactor < 0 || (float) $decayFactor > 1) {
            throw ConfigurationException::invalidDecayFactor((float) $decayFactor);
        }

        // Validate worker heartbeat configuration
        $staleThreshold = config('queue-metrics.worker_heartbeat.stale_threshold');
        if (! is_int($staleThreshold) && ! is_numeric($staleThreshold)) {
            // Treat non-numeric as 0 to trigger the next validation
            $staleThreshold = 0;
        }

        if ((int) $staleThreshold < 1) {
            throw ConfigurationException::invalidStaleThreshold((int) $staleThreshold);
        }
    }
}
