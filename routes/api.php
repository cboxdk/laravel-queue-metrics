<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use PHPeek\LaravelQueueMetrics\Http\Controllers\HealthCheckController;
use PHPeek\LaravelQueueMetrics\Http\Controllers\JobMetricsController;
use PHPeek\LaravelQueueMetrics\Http\Controllers\OverviewController;
use PHPeek\LaravelQueueMetrics\Http\Controllers\PrometheusController;
use PHPeek\LaravelQueueMetrics\Http\Controllers\QueueDepthController;
use PHPeek\LaravelQueueMetrics\Http\Controllers\QueueMetricsController;
use PHPeek\LaravelQueueMetrics\Http\Controllers\ServerMetricsController;
use PHPeek\LaravelQueueMetrics\Http\Controllers\WorkerController;
use PHPeek\LaravelQueueMetrics\Http\Controllers\WorkerStatusController;

Route::prefix(config('queue-metrics.api.prefix', 'queue-metrics'))
    ->middleware(config('queue-metrics.api.middleware', ['api']))
    ->group(function () {
        // Health check
        Route::get('/health', HealthCheckController::class)
            ->name('queue-metrics.health');

        // Overview
        Route::get('/overview', OverviewController::class)
            ->name('queue-metrics.overview');

        // Job metrics
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
                ->name('queue-metrics.prometheus');
        }
    });
