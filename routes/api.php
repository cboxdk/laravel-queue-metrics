<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use PHPeek\LaravelQueueMetrics\Http\Controllers\AllJobsController;
use PHPeek\LaravelQueueMetrics\Http\Controllers\AllQueuesController;
use PHPeek\LaravelQueueMetrics\Http\Controllers\AllServersController;
use PHPeek\LaravelQueueMetrics\Http\Controllers\AllWorkersController;
use PHPeek\LaravelQueueMetrics\Http\Controllers\ApiIndexController;
use PHPeek\LaravelQueueMetrics\Http\Controllers\HealthCheckController;
use PHPeek\LaravelQueueMetrics\Http\Controllers\JobMetricsController;
use PHPeek\LaravelQueueMetrics\Http\Controllers\OverviewController;
use PHPeek\LaravelQueueMetrics\Http\Controllers\PrometheusController;
use PHPeek\LaravelQueueMetrics\Http\Controllers\QueueDepthController;
use PHPeek\LaravelQueueMetrics\Http\Controllers\QueueMetricsController;
use PHPeek\LaravelQueueMetrics\Http\Controllers\ServerMetricsController;
use PHPeek\LaravelQueueMetrics\Http\Controllers\TrendAnalysisController;
use PHPeek\LaravelQueueMetrics\Http\Controllers\WorkerController;
use PHPeek\LaravelQueueMetrics\Http\Controllers\WorkerStatusController;
use PHPeek\LaravelQueueMetrics\Http\Middleware\ThrottlePrometheus;

Route::prefix('queue-metrics')
    ->middleware(config('queue-metrics.middleware', ['api']))
    ->group(function () {
        // API discovery index
        Route::get('/', ApiIndexController::class)
            ->name('queue-metrics.index');

        // Health check
        Route::get('/health', HealthCheckController::class)
            ->name('queue-metrics.health');

        // Overview (comprehensive)
        Route::get('/overview', OverviewController::class)
            ->name('queue-metrics.overview');

        // Comprehensive collection endpoints
        Route::get('/jobs', [AllJobsController::class, 'index'])
            ->name('queue-metrics.jobs.index');
        Route::get('/queues/all', [AllQueuesController::class, 'index'])
            ->name('queue-metrics.queues.all');
        Route::get('/servers', [AllServersController::class, 'index'])
            ->name('queue-metrics.servers.index');
        Route::get('/workers/all', [AllWorkersController::class, 'index'])
            ->name('queue-metrics.workers.all');

        // Job metrics (single job)
        Route::get('/jobs/{jobClass}', [JobMetricsController::class, 'show'])
            ->name('queue-metrics.jobs.show');

        // Queue metrics
        Route::get('/queues/{connection}/{queue}', [QueueMetricsController::class, 'show'])
            ->name('queue-metrics.queues.show');

        // Workers
        Route::get('/workers', [WorkerController::class, 'index'])
            ->name('queue-metrics.workers.index');

        // Worker status and heartbeats
        Route::get('/workers/status', [WorkerStatusController::class, 'index'])
            ->name('queue-metrics.workers.status');
        Route::get('/workers/status/{workerId}', [WorkerStatusController::class, 'show'])
            ->name('queue-metrics.workers.status.show');
        Route::post('/workers/detect-stale', [WorkerStatusController::class, 'detectStale'])
            ->name('queue-metrics.workers.detect-stale');

        // Queue depth
        Route::get('/queues', [QueueDepthController::class, 'index'])
            ->name('queue-metrics.queues.index');
        Route::get('/queues/{connection}/{queue}/depth', [QueueDepthController::class, 'show'])
            ->name('queue-metrics.queues.depth');

        // Server metrics
        Route::get('/server', [ServerMetricsController::class, 'index'])
            ->name('queue-metrics.server.index');
        Route::get('/server/health', [ServerMetricsController::class, 'health'])
            ->name('queue-metrics.server.health');

        // Trend analysis
        Route::get('/trends/queue-depth/{connection}/{queue}', [TrendAnalysisController::class, 'queueDepth'])
            ->name('queue-metrics.trends.queue-depth');
        Route::get('/trends/throughput/{connection}/{queue}', [TrendAnalysisController::class, 'throughput'])
            ->name('queue-metrics.trends.throughput');
        Route::get('/trends/worker-efficiency', [TrendAnalysisController::class, 'workerEfficiency'])
            ->name('queue-metrics.trends.worker-efficiency');

        // Prometheus export
        if (config('queue-metrics.prometheus.enabled', true)) {
            $route = Route::get('/prometheus', PrometheusController::class)
                ->name('queue-metrics.prometheus');

            // Apply optional rate limiting if enabled
            if (config('queue-metrics.prometheus.rate_limit.enabled', false)) {
                $route->middleware(ThrottlePrometheus::class);
            }
        }
    });
