---
title: "HTTP API Endpoints"
description: "RESTful HTTP endpoints for accessing queue metrics via HTTP requests"
weight: 11
---

# HTTP API Endpoints

Complete HTTP API reference for Laravel Queue Metrics.

## Base URL

All endpoints are prefixed with `/queue-metrics`.

**Note**: The prefix is hardcoded in the routes and cannot be changed via configuration.

## Authentication

By default, API endpoints use the `api` middleware. To add authentication, you can register custom middleware in your service provider:

```php
// In a service provider boot() method
config(['queue-metrics.middleware' => ['api', 'auth:sanctum']]);
```

Or use route middleware in your application's route files to wrap the package routes with additional protection.

## Response Format

All responses return JSON:

```json
{
    "data": { /* Response data */ },
    "meta": {
        "generated_at": "2024-01-15T10:30:00Z",
        "package_version": "1.0.0"
    }
}
```

Error responses:

```json
{
    "error": "Error message",
    "code": 400
}
```

### Trend Data Structure

Many endpoints now include embedded trend data. Trend objects have the following structure:

```json
{
    "available": true,
    "statistics": {
        "current": 173.0,
        "average": 165.8,
        "min": 98.0,
        "max": 245.0,
        "std_dev": 28.4
    },
    "trend": {
        "slope": 0.08,
        "direction": "stable",
        "confidence": 0.78
    },
    "forecast": {
        "next_value": 175.2,
        "next_timestamp": 1705318800
    }
}
```

**Fields**:
- `available` - Whether trend data is available (requires sufficient historical data)
- `statistics` - Statistical summary of the metric
  - `current` - Current value
  - `average` - Mean value over analysis window
  - `min` / `max` - Range of values
  - `std_dev` - Standard deviation
- `trend` - Trend analysis
  - `slope` - Rate of change
  - `direction` - `increasing`, `decreasing`, or `stable`
  - `confidence` - Confidence score (0.0-1.0)
- `forecast` - Predicted next value
  - `next_value` - Forecasted value
  - `next_timestamp` - Unix timestamp for forecast

If `available` is `false`, other fields may be `null` or omitted.

## Endpoints

### GET /queue-metrics/overview

Get system-wide overview of all queues, jobs, and workers.

**Query Parameters**:
- `full` - Include detailed metrics with trends (optional, set to `1` for full data)

**Response**:

```json
{
    "total_queues": 3,
    "total_jobs_processed": 12456,
    "total_jobs_failed": 234,
    "total_active_workers": 8,
    "health_score": 87.5,

    "queues": {
        "redis:default": {
            "connection": "redis",
            "queue": "default",
            "depth": {
                "pending": 45,
                "delayed": 12,
                "reserved": 3,
                "total": 60
            },
            "health": {
                "score": 85.0,
                "status": "healthy"
            },
            "trends": {
                "depth": {
                    "available": true,
                    "statistics": {
                        "current": 60,
                        "average": 55.3,
                        "min": 32,
                        "max": 95,
                        "std_dev": 15.2
                    },
                    "trend": {
                        "slope": 0.12,
                        "direction": "increasing",
                        "confidence": 0.85
                    },
                    "forecast": {
                        "next_value": 62.5,
                        "next_timestamp": 1705318800
                    }
                },
                "throughput": {
                    "available": true,
                    "statistics": {
                        "current": 12.5,
                        "average": 11.8
                    }
                }
            }
        }
    },

    "jobs": {
        "App\\Jobs\\ProcessOrder": {
            "redis:default": {
                "total_processed": 1234,
                "total_failed": 12,
                "failure_rate": 0.97,
                "duration": {
                    "average": 1234.56,
                    "p95": 2500.00
                }
            }
        }
    },

    "workers": {
        "worker-12345": {
            "worker_id": "worker-12345",
            "status": "busy",
            "jobs_processed": 145,
            "memory_mb": 128.5
        }
    },

    "servers": {
        "web-1": {
            "hostname": "web-1",
            "active_workers": 4,
            "cpu_percent": 45.2,
            "memory_percent": 62.1
        }
    },

    "metadata": {
        "generated_at": "2024-01-15T10:30:00Z",
        "package_version": "1.0.0"
    }
}
```

**Examples**:

```bash
# Basic overview
curl http://your-app.test/queue-metrics/overview

# Full overview with trend data
curl http://your-app.test/queue-metrics/overview?full=1
```

---

### GET /queue-metrics/jobs/{jobClass}

Get aggregated metrics for a specific job class across all queues where it has executed.

**Path Parameters**:
- `jobClass` - Fully qualified job class name (URL encoded)

**Query Parameters**: None (endpoint aggregates across all queues automatically)

**Response**:

```json
{
    "data": {
        "job_class": "App\\Jobs\\ProcessOrder",
        "total_executions": 1000,
        "total_failures": 25,
        "avg_duration_ms": 1250.50,
        "avg_memory_mb": 45.25,
        "failure_rate": 2.5,
        "throughput_per_minute": 12.5,
        "by_queue": [
            {
                "connection": "redis",
                "queue": "default",
                "executions": 800,
                "failures": 15,
                "avg_duration_ms": 1200.00,
                "avg_memory_mb": 43.50,
                "failure_rate": 1.875,
                "throughput_per_minute": 10.0
            },
            {
                "connection": "redis",
                "queue": "high-priority",
                "executions": 150,
                "failures": 8,
                "avg_duration_ms": 1500.00,
                "avg_memory_mb": 52.00,
                "failure_rate": 5.33,
                "throughput_per_minute": 2.0
            },
            {
                "connection": "sync",
                "queue": "sync",
                "executions": 50,
                "failures": 2,
                "avg_duration_ms": 1100.00,
                "avg_memory_mb": 40.00,
                "failure_rate": 4.0,
                "throughput_per_minute": 0.5
            }
        ],
        "calculated_at": "2025-01-18T10:30:45Z"
    }
}
```

**Response Fields**:

**Top-level aggregated metrics** (across all queues):
- `job_class` - Fully qualified job class name
- `total_executions` - Sum of executions across all queues
- `total_failures` - Sum of failures across all queues
- `avg_duration_ms` - Weighted average duration (weighted by execution count per queue)
- `avg_memory_mb` - Weighted average memory usage (weighted by execution count per queue)
- `failure_rate` - Percentage calculated as `(total_failures / total_executions) * 100`
- `throughput_per_minute` - Sum of throughput across all queues
- `calculated_at` - Timestamp when metrics were calculated

**Per-queue breakdown** (`by_queue` array):
Each element contains metrics for a specific connection/queue combination where the job executed:
- `connection` - Queue connection name
- `queue` - Queue name
- `executions` - Number of executions on this queue
- `failures` - Number of failures on this queue
- `avg_duration_ms` - Average duration on this queue
- `avg_memory_mb` - Average memory usage on this queue
- `failure_rate` - Failure rate percentage for this queue
- `throughput_per_minute` - Jobs processed per minute on this queue

**Aggregation Logic**:

The endpoint aggregates metrics across all queues where the job class has executed:

1. **Totals**: `total_executions` and `total_failures` are simple sums across all queues
2. **Weighted Averages**: `avg_duration_ms` and `avg_memory_mb` are calculated using weighted averages based on execution counts:
   ```
   avg_duration = Σ(queue_duration × queue_executions) / total_executions
   avg_memory = Σ(queue_memory × queue_executions) / total_executions
   ```
3. **Failure Rate**: Calculated from totals: `(total_failures / total_executions) * 100`
4. **Throughput**: Sum of throughput across all queues (assumes non-overlapping time windows)

**Examples**:

```bash
# Get aggregated metrics for a job class
curl "http://your-app.test/queue-metrics/jobs/App%5CJobs%5CProcessOrder"

# PHP client
$response = Http::get('http://your-app.test/queue-metrics/jobs/' . urlencode(\App\Jobs\ProcessOrder::class));
$data = $response->json()['data'];

echo "Total executions: {$data['total_executions']}\n";
echo "Overall failure rate: {$data['failure_rate']}%\n";
echo "Weighted avg duration: {$data['avg_duration_ms']}ms\n";

// Analyze per-queue performance
foreach ($data['by_queue'] as $queueMetrics) {
    echo "{$queueMetrics['connection']}:{$queueMetrics['queue']} - ";
    echo "{$queueMetrics['executions']} executions, ";
    echo "{$queueMetrics['failure_rate']}% failure rate\n";
}
```

**Use Cases**:

- **Overall Job Health**: Quickly assess job performance across your entire system
- **Queue Comparison**: Compare how the same job performs on different queues (e.g., high-priority vs default)
- **Performance Analysis**: Identify if certain queues have different performance characteristics
- **Capacity Planning**: Use aggregated throughput to understand overall job processing capacity
- **Failure Analysis**: Identify queues with higher failure rates for the same job class

---

### GET /queue-metrics/jobs

Get metrics for all job classes.

**Query Parameters**: None

**Response**:

```json
{
    "App\\Jobs\\ProcessOrder": {
        "redis:default": { /* JobMetricsData */ },
        "redis:high-priority": { /* JobMetricsData */ }
    },
    "App\\Jobs\\SendEmail": {
        "redis:default": { /* JobMetricsData */ }
    }
}
```

**Example**:

```bash
curl http://your-app.test/queue-metrics/jobs
```

---

### GET /queue-metrics/queues/{queue}

Get metrics for a specific queue with embedded trend data.

**Path Parameters**:
- `queue` - Queue name

**Query Parameters**:
- `connection` - Queue connection (default: `default`)

**Response**:

```json
{
    "connection": "redis",
    "queue": "default",

    "depth": {
        "pending": 145,
        "delayed": 23,
        "reserved": 5,
        "total": 173,
        "oldest_job_age_seconds": 3600
    },

    "processing_rate": 12.5,
    "failure_rate": 2.3,
    "active_worker_count": 4,

    "health": {
        "score": 85.0,
        "status": "healthy",
        "issues": []
    },

    "trends": {
        "depth": {
            "available": true,
            "statistics": {
                "current": 173,
                "average": 165.8,
                "min": 98,
                "max": 245,
                "std_dev": 28.4
            },
            "trend": {
                "slope": 0.08,
                "direction": "stable",
                "confidence": 0.78
            },
            "forecast": {
                "next_value": 175.2,
                "next_timestamp": 1705318800
            }
        },
        "throughput": {
            "available": true,
            "statistics": {
                "current": 12.5,
                "average": 11.9,
                "min": 8.2,
                "max": 16.7,
                "std_dev": 2.1
            },
            "trend": {
                "slope": 0.05,
                "direction": "increasing",
                "confidence": 0.82
            },
            "forecast": {
                "next_value": 12.8,
                "next_timestamp": 1705318800
            }
        }
    }
}
```

**Example**:

```bash
curl "http://your-app.test/queue-metrics/queues/default?connection=redis"
```

---

### GET /queue-metrics/queues

Get metrics for all queues. Each queue includes embedded trend data (depth and throughput trends).

**Query Parameters**: None

**Response**:

```json
{
    "redis": {
        "default": {
            "connection": "redis",
            "queue": "default",
            "depth": { /* ... */ },
            "trends": {
                "depth": { /* TrendData */ },
                "throughput": { /* TrendData */ }
            }
        },
        "high-priority": { /* QueueMetricsData with trends */ },
        "emails": { /* QueueMetricsData with trends */ }
    },
    "database": {
        "default": { /* QueueMetricsData with trends */ }
    }
}
```

**Note**: See the `/queues/{queue}` endpoint documentation above for the complete trend data structure.

**Example**:

```bash
curl http://your-app.test/queue-metrics/queues
```

---

### GET /queue-metrics/workers

Get information about active workers with efficiency trends.

**Query Parameters**:
- `connection` - Filter by connection (optional)
- `queue` - Filter by queue (optional)

**Response**:

```json
{
    "data": {
        "workers": [
            {
                "worker_id": "worker-12345-pid-1234",
                "pid": 1234,
                "connection": "redis",
                "queue": "default",
                "status": "busy",
                "current_job": "App\\Jobs\\ProcessOrder",
                "jobs_processed": 145,
                "jobs_failed": 3,
                "memory_mb": 128.5,
                "cpu_percent": 45.2,
                "last_heartbeat": "2024-01-15T10:29:55Z"
            },
            {
                "worker_id": "worker-12346-pid-1235",
                "pid": 1235,
                "connection": "redis",
                "queue": "default",
                "status": "idle",
                "current_job": null,
                "jobs_processed": 89,
                "jobs_failed": 1,
                "memory_mb": 95.2,
                "cpu_percent": 12.5,
                "last_heartbeat": "2024-01-15T10:29:58Z"
            }
        ],
        "efficiency_trend": {
            "available": true,
            "statistics": {
                "current": 85.2,
                "average": 82.7,
                "min": 75.3,
                "max": 92.1,
                "std_dev": 4.8
            },
            "trend": {
                "slope": 0.03,
                "direction": "stable",
                "confidence": 0.76
            },
            "forecast": {
                "next_value": 85.5,
                "next_timestamp": 1705318800
            }
        }
    }
}
```

**Examples**:

```bash
# All workers
curl http://your-app.test/queue-metrics/workers

# Filter by connection
curl "http://your-app.test/queue-metrics/workers?connection=redis"

# Filter by queue
curl "http://your-app.test/queue-metrics/workers?connection=redis&queue=default"
```

---

### POST /queue-metrics/baselines/calculate

Trigger baseline calculation.

**Query Parameters**:
- `connection` - Queue connection (optional, all if omitted)
- `queue` - Queue name (optional, all if omitted)
- `job_class` - Job class (optional, aggregated if omitted)

**Response**:

```json
{
    "message": "Baseline calculation started",
    "connection": "redis",
    "queue": "default",
    "job_class": "App\\Jobs\\ProcessOrder"
}
```

**Examples**:

```bash
# Calculate for all
curl -X POST http://your-app.test/queue-metrics/baselines/calculate

# Calculate for specific job
curl -X POST "http://your-app.test/queue-metrics/baselines/calculate?connection=redis&queue=default&job_class=App%5CJobs%5CProcessOrder"
```

---

### GET /queue-metrics/baselines/{connection}/{queue}

Get calculated baseline for a queue.

**Path Parameters**:
- `connection` - Queue connection
- `queue` - Queue name

**Query Parameters**:
- `job_class` - Specific job class (optional)

**Response**:

```json
{
    "connection": "redis",
    "queue": "default",
    "job_class": "App\\Jobs\\ProcessOrder",

    "avg_duration_ms": 1150.00,
    "avg_memory_mb": 42.5,
    "avg_cpu_time_ms": 850.00,

    "calculated_at": "2024-01-15T00:00:00Z",
    "sample_count": 100
}
```

---

### GET /queue-metrics/server/metrics

Get current server resource metrics.

**Query Parameters**: None

**Response**:

```json
{
    "hostname": "web-server-1",
    "timestamp": "2024-01-15T10:30:00Z",

    "cpu": {
        "percent": 45.2,
        "load_1min": 2.5,
        "load_5min": 2.1,
        "load_15min": 1.8
    },

    "memory": {
        "total_mb": 16384,
        "used_mb": 10240,
        "free_mb": 6144,
        "percent": 62.5
    },

    "disk": {
        "total_gb": 500,
        "used_gb": 320,
        "free_gb": 180,
        "percent": 64.0
    },

    "network": {
        "rx_bytes_per_sec": 1048576,
        "tx_bytes_per_sec": 524288
    }
}
```

**Example**:

```bash
curl http://your-app.test/queue-metrics/server/metrics
```

**Note**: Requires [cboxdk/system-metrics](https://github.com/cboxdk/system-metrics) package.

---

### GET /queue-metrics/server/health

Get server health assessment.

**Query Parameters**: None

**Response**:

```json
{
    "status": "healthy",
    "score": 87.5,

    "checks": {
        "cpu": {
            "status": "ok",
            "value": 45.2,
            "threshold": 80.0
        },
        "memory": {
            "status": "ok",
            "value": 62.5,
            "threshold": 85.0
        },
        "disk": {
            "status": "warning",
            "value": 89.0,
            "threshold": 85.0
        },
        "queues": {
            "status": "ok",
            "depth": 145,
            "threshold": 1000
        }
    },

    "issues": [
        "Disk usage high: 89%"
    ]
}
```

---

### GET /queue-metrics/prometheus

Get metrics in Prometheus format.

**Query Parameters**: None

**Response** (text/plain):

```
# HELP queue_depth Current depth of queue
# TYPE queue_depth gauge
queue_depth{connection="redis",queue="default"} 145

# HELP queue_processing_rate Jobs processed per minute
# TYPE queue_processing_rate gauge
queue_processing_rate{connection="redis",queue="default"} 12.5

# HELP job_duration_ms Average job duration in milliseconds
# TYPE job_duration_ms gauge
job_duration_ms{job_class="App\\Jobs\\ProcessOrder",connection="redis",queue="default"} 1234.56

# HELP job_failure_rate Percentage of failed jobs
# TYPE job_failure_rate gauge
job_failure_rate{job_class="App\\Jobs\\ProcessOrder",connection="redis",queue="default"} 0.97

# HELP worker_count Active worker count
# TYPE worker_count gauge
worker_count{connection="redis",queue="default"} 4
```

See [Prometheus Integration](prometheus.md) for detailed setup.

---

### GET /queue-metrics/health

Health check endpoint for load balancers.

**Query Parameters**: None

**Response**:

```json
{
    "status": "healthy",
    "timestamp": "2024-01-15T10:30:00Z"
}
```

**HTTP Status Codes**:
- `200` - Healthy
- `503` - Unhealthy (queues critical)

---

## Error Responses

### 400 Bad Request

```json
{
    "error": "Invalid job class provided",
    "code": 400
}
```

### 404 Not Found

```json
{
    "error": "Job class not found",
    "code": 404
}
```

### 500 Internal Server Error

```json
{
    "error": "Failed to retrieve metrics",
    "code": 500,
    "message": "Redis connection failed"
}
```

## Rate Limiting

The API respects Laravel's rate limiting configuration. Add custom throttling:

```php
'api' => [
    'middleware' => ['api', 'throttle:60,1'], // 60 requests per minute
],
```

## CORS

Enable CORS if accessing from different domains:

```php
// app/Http/Kernel.php
protected $middleware = [
    \Fruitcake\Cors\HandleCors::class,
];
```

## Client Examples

### JavaScript (Fetch)

```javascript
async function getQueueMetrics() {
    const response = await fetch('http://your-app.test/queue-metrics/overview');
    const data = await response.json();

    console.log('Health Score:', data.health_score);
    console.log('Active Workers:', data.total_active_workers);
}
```

### PHP (Guzzle)

```php
use GuzzleHttp\Client;

$client = new Client(['base_uri' => 'http://your-app.test']);

$response = $client->get('/queue-metrics/overview');
$data = json_decode($response->getBody(), true);

echo "Health Score: {$data['health_score']}\n";
```

### Python (Requests)

```python
import requests

response = requests.get('http://your-app.test/queue-metrics/overview')
data = response.json()

print(f"Health Score: {data['health_score']}")
print(f"Active Workers: {data['total_active_workers']}")
```

### cURL

```bash
# With authentication
curl -H "Authorization: Bearer YOUR_TOKEN" \
     http://your-app.test/queue-metrics/overview

# With custom headers
curl -H "Accept: application/json" \
     -H "X-Tenant-ID: tenant-123" \
     http://your-app.test/queue-metrics/overview
```

## Webhooks

While the package doesn't provide webhooks out of the box, you can build them using events:

```php
use Cbox\LaravelQueueMetrics\Events\HealthScoreChanged;

Event::listen(HealthScoreChanged::class, function ($event) {
    if ($event->toStatus === 'critical') {
        Http::post('https://your-webhook.com/alerts', [
            'event' => 'health_critical',
            'score' => $event->newScore,
            'timestamp' => now()->toIso8601String(),
        ]);
    }
});
```

## Next Steps

- [Prometheus Integration](../advanced-usage/prometheus.md) - Set up monitoring dashboards
- [Facade API](facade-api.md) - Programmatic access
- [Events](../advanced-usage/events.md) - React to metrics changes
