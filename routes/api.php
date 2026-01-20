<?php

declare(strict_types=1);

use Cbox\LaravelQueueMetrics\Http\Controllers\AllJobsController;
use Cbox\LaravelQueueMetrics\Http\Controllers\AllQueuesController;
use Cbox\LaravelQueueMetrics\Http\Controllers\AllServersController;
use Cbox\LaravelQueueMetrics\Http\Controllers\AllWorkersController;
use Cbox\LaravelQueueMetrics\Http\Controllers\ApiIndexController;
use Cbox\LaravelQueueMetrics\Http\Controllers\HealthCheckController;
use Cbox\LaravelQueueMetrics\Http\Controllers\JobMetricsController;
use Cbox\LaravelQueueMetrics\Http\Controllers\OverviewController;
use Cbox\LaravelQueueMetrics\Http\Controllers\PrometheusController;
use Cbox\LaravelQueueMetrics\Http\Controllers\QueueDepthController;
use Cbox\LaravelQueueMetrics\Http\Controllers\QueueMetricsController;
use Cbox\LaravelQueueMetrics\Http\Controllers\ServerMetricsController;
use Cbox\LaravelQueueMetrics\Http\Controllers\WorkerController;
use Cbox\LaravelQueueMetrics\Http\Controllers\WorkerStatusController;
use Illuminate\Support\Facades\Route;

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

        // Prometheus export
        if (config('queue-metrics.prometheus.enabled', true)) {
            Route::get('/prometheus', PrometheusController::class)
                ->middleware(config('queue-metrics.prometheus.middleware', []))
                ->name('queue-metrics.prometheus');
        }
    });
