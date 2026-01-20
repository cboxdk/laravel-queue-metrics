<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueMetrics\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * API discovery endpoint - provides links to all available endpoints.
 */
final class ApiIndexController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $baseUrl = url('/queue-metrics');

        return response()->json([
            'message' => 'Laravel Queue Metrics API',
            'version' => '1.0.0',
            'documentation' => 'https://github.com/Cbox/laravel-queue-metrics',
            'endpoints' => [
                'health' => [
                    'url' => "{$baseUrl}/health",
                    'method' => 'GET',
                    'description' => 'System health check and diagnostics',
                ],
                'overview' => [
                    'url' => "{$baseUrl}/overview",
                    'method' => 'GET',
                    'description' => 'Comprehensive overview of all queues, jobs, servers, and workers',
                ],
                'jobs' => [
                    'list' => [
                        'url' => "{$baseUrl}/jobs",
                        'method' => 'GET',
                        'description' => 'List all jobs with metrics',
                    ],
                    'show' => [
                        'url' => "{$baseUrl}/jobs/{jobClass}",
                        'method' => 'GET',
                        'description' => 'Get metrics for a specific job class',
                        'parameters' => [
                            'jobClass' => 'Job class name (e.g., App\\Jobs\\ProcessVideo)',
                        ],
                    ],
                ],
                'queues' => [
                    'list' => [
                        'url' => "{$baseUrl}/queues",
                        'method' => 'GET',
                        'description' => 'List all queues with depth information',
                    ],
                    'all' => [
                        'url' => "{$baseUrl}/queues/all",
                        'method' => 'GET',
                        'description' => 'List all queues with full metrics',
                    ],
                    'show' => [
                        'url' => "{$baseUrl}/queues/{connection}/{queue}",
                        'method' => 'GET',
                        'description' => 'Get metrics for a specific queue',
                        'parameters' => [
                            'connection' => 'Queue connection name (e.g., redis)',
                            'queue' => 'Queue name (e.g., default)',
                        ],
                    ],
                    'depth' => [
                        'url' => "{$baseUrl}/queues/{connection}/{queue}/depth",
                        'method' => 'GET',
                        'description' => 'Get queue depth information',
                        'parameters' => [
                            'connection' => 'Queue connection name (e.g., redis)',
                            'queue' => 'Queue name (e.g., default)',
                        ],
                    ],
                ],
                'workers' => [
                    'list' => [
                        'url' => "{$baseUrl}/workers",
                        'method' => 'GET',
                        'description' => 'List active workers with summary',
                    ],
                    'all' => [
                        'url' => "{$baseUrl}/workers/all",
                        'method' => 'GET',
                        'description' => 'List all workers with detailed metrics',
                    ],
                    'status' => [
                        'url' => "{$baseUrl}/workers/status",
                        'method' => 'GET',
                        'description' => 'Get status of all workers with heartbeats',
                    ],
                    'status_show' => [
                        'url' => "{$baseUrl}/workers/status/{workerId}",
                        'method' => 'GET',
                        'description' => 'Get status of a specific worker',
                        'parameters' => [
                            'workerId' => 'Worker ID',
                        ],
                    ],
                    'detect_stale' => [
                        'url' => "{$baseUrl}/workers/detect-stale",
                        'method' => 'POST',
                        'description' => 'Detect and mark stale workers',
                    ],
                ],
                'servers' => [
                    'list' => [
                        'url' => "{$baseUrl}/servers",
                        'method' => 'GET',
                        'description' => 'List all servers with detailed metrics',
                    ],
                    'index' => [
                        'url' => "{$baseUrl}/server",
                        'method' => 'GET',
                        'description' => 'Get current server metrics (CPU, memory, load)',
                    ],
                    'health' => [
                        'url' => "{$baseUrl}/server/health",
                        'method' => 'GET',
                        'description' => 'Get server health status',
                    ],
                ],
                'trends' => [
                    'queue_depth' => [
                        'url' => "{$baseUrl}/trends/queue-depth/{connection}/{queue}",
                        'method' => 'GET',
                        'description' => 'Get queue depth trend data',
                        'parameters' => [
                            'connection' => 'Queue connection name',
                            'queue' => 'Queue name',
                        ],
                    ],
                    'throughput' => [
                        'url' => "{$baseUrl}/trends/throughput/{connection}/{queue}",
                        'method' => 'GET',
                        'description' => 'Get throughput trend data',
                        'parameters' => [
                            'connection' => 'Queue connection name',
                            'queue' => 'Queue name',
                        ],
                    ],
                    'worker_efficiency' => [
                        'url' => "{$baseUrl}/trends/worker-efficiency",
                        'method' => 'GET',
                        'description' => 'Get worker efficiency trend data',
                    ],
                ],
                'prometheus' => config('queue-metrics.prometheus.enabled', true) ? [
                    'url' => "{$baseUrl}/prometheus",
                    'method' => 'GET',
                    'description' => 'Prometheus metrics export',
                ] : null,
            ],
            'examples' => [
                'Get overview' => "curl {$baseUrl}/overview",
                'List all jobs' => "curl {$baseUrl}/jobs",
                'Get specific job' => "curl {$baseUrl}/jobs/App\\\\Jobs\\\\ProcessVideo",
                'List all queues' => "curl {$baseUrl}/queues",
                'Get queue metrics' => "curl {$baseUrl}/queues/redis/default",
                'Check health' => "curl {$baseUrl}/health",
            ],
        ]);
    }
}
