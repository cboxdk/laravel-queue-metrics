---
title: "Prometheus Integration"
description: "Export queue metrics to Prometheus for centralized monitoring with Grafana dashboards"
weight: 33
---

# Prometheus Integration

Laravel Queue Metrics provides native Prometheus metrics export for monitoring and alerting.

## Quick Start

### 1. Configure Prometheus

Add to your `prometheus.yml`:

```yaml
scrape_configs:
  - job_name: 'laravel-queues'
    static_configs:
      - targets: ['your-app.test']
    metrics_path: '/queue-metrics/prometheus'
    scrape_interval: 30s
```

### 2. Verify Export

Visit the metrics endpoint:

```bash
curl http://your-app.test/queue-metrics/prometheus
```

### 3. Query in Prometheus

```promql
# Queue depth
queue_depth{connection="redis",queue="default"}

# Job duration
job_duration_ms{job_class="App\\Jobs\\ProcessOrder"}

# Failure rate
job_failure_rate{job_class="App\\Jobs\\ProcessOrder"}
```

## Available Metrics

### Queue Metrics

```promql
# TYPE queue_depth gauge
# HELP Current depth of queue (pending + delayed + reserved)
queue_depth{connection="redis",queue="default"} 145

# TYPE queue_depth_pending gauge
# HELP Jobs ready to process
queue_depth_pending{connection="redis",queue="default"} 120

# TYPE queue_depth_delayed gauge
# HELP Jobs scheduled for future
queue_depth_delayed{connection="redis",queue="default"} 20

# TYPE queue_depth_reserved gauge
# HELP Jobs currently processing
queue_depth_reserved{connection="redis",queue="default"} 5

# TYPE queue_processing_rate gauge
# HELP Jobs processed per minute
queue_processing_rate{connection="redis",queue="default"} 12.5

# TYPE queue_failure_rate gauge
# HELP Percentage of failed jobs
queue_failure_rate{connection="redis",queue="default"} 2.3

# TYPE queue_health_score gauge
# HELP Queue health score (0-100)
queue_health_score{connection="redis",queue="default"} 87.5

# TYPE queue_oldest_job_age_seconds gauge
# HELP Age of oldest pending job in seconds
queue_oldest_job_age_seconds{connection="redis",queue="default"} 3600
```

### Job Metrics

```promql
# TYPE job_total_processed counter
# HELP Total jobs processed
job_total_processed{job_class="App\\Jobs\\ProcessOrder",connection="redis",queue="default"} 1234

# TYPE job_total_failed counter
# HELP Total jobs failed
job_total_failed{job_class="App\\Jobs\\ProcessOrder",connection="redis",queue="default"} 12

# TYPE job_total_queued gauge
# HELP Currently queued jobs
job_total_queued{job_class="App\\Jobs\\ProcessOrder",connection="redis",queue="default"} 45

# TYPE job_failure_rate gauge
# HELP Failure rate percentage
job_failure_rate{job_class="App\\Jobs\\ProcessOrder",connection="redis",queue="default"} 0.97

# TYPE job_duration_ms gauge
# HELP Average job duration in milliseconds
job_duration_ms{job_class="App\\Jobs\\ProcessOrder",connection="redis",queue="default"} 1234.56

# TYPE job_duration_p95_ms gauge
# HELP 95th percentile duration
job_duration_p95_ms{job_class="App\\Jobs\\ProcessOrder",connection="redis",queue="default"} 2500.00

# TYPE job_duration_p99_ms gauge
# HELP 99th percentile duration
job_duration_p99_ms{job_class="App\\Jobs\\ProcessOrder",connection="redis",queue="default"} 3800.00

# TYPE job_memory_mb gauge
# HELP Average memory usage in MB
job_memory_mb{job_class="App\\Jobs\\ProcessOrder",connection="redis",queue="default"} 45.2

# TYPE job_memory_p95_mb gauge
# HELP 95th percentile memory
job_memory_p95_mb{job_class="App\\Jobs\\ProcessOrder",connection="redis",queue="default"} 98.7

# TYPE job_throughput_per_minute gauge
# HELP Jobs per minute
job_throughput_per_minute{job_class="App\\Jobs\\ProcessOrder",connection="redis",queue="default"} 12.5
```

### Worker Metrics

```promql
# TYPE worker_count gauge
# HELP Active worker count
worker_count{connection="redis",queue="default"} 4

# TYPE worker_idle_count gauge
# HELP Idle worker count
worker_idle_count{connection="redis",queue="default"} 1

# TYPE worker_busy_count gauge
# HELP Busy worker count
worker_busy_count{connection="redis",queue="default"} 3

# TYPE worker_efficiency_percent gauge
# HELP Worker efficiency percentage
worker_efficiency_percent{connection="redis",queue="default"} 75.0

# TYPE worker_memory_mb gauge
# HELP Worker memory usage
worker_memory_mb{worker_id="worker-12345"} 128.5

# TYPE worker_cpu_percent gauge
# HELP Worker CPU usage
worker_cpu_percent{worker_id="worker-12345"} 45.2
```

### Server Metrics (if cboxdk/system-metrics installed)

```promql
# TYPE server_cpu_percent gauge
server_cpu_percent{hostname="web-1"} 45.2

# TYPE server_memory_percent gauge
server_memory_percent{hostname="web-1"} 62.5

# TYPE server_disk_percent gauge
server_disk_percent{hostname="web-1"} 64.0

# TYPE server_load_1min gauge
server_load_1min{hostname="web-1"} 2.5
```

## Grafana Dashboard

### Import Dashboard

Use this JSON or build your own:

```json
{
  "dashboard": {
    "title": "Laravel Queue Metrics",
    "panels": [
      {
        "title": "Queue Depth",
        "targets": [
          {
            "expr": "queue_depth{connection=\"redis\",queue=\"default\"}"
          }
        ],
        "type": "graph"
      },
      {
        "title": "Job Duration (P95)",
        "targets": [
          {
            "expr": "job_duration_p95_ms{connection=\"redis\"}"
          }
        ],
        "type": "graph"
      },
      {
        "title": "Failure Rate",
        "targets": [
          {
            "expr": "job_failure_rate{connection=\"redis\"}"
          }
        ],
        "type": "graph"
      },
      {
        "title": "Worker Efficiency",
        "targets": [
          {
            "expr": "worker_efficiency_percent{connection=\"redis\"}"
          }
        ],
        "type": "gauge"
      }
    ]
  }
}
```

### Key Panels

**1. Queue Overview**

```promql
# Total jobs in queue
sum(queue_depth) by (connection, queue)

# Processing rate
sum(queue_processing_rate) by (connection, queue)
```

**2. Job Performance**

```promql
# Average duration by job class
avg(job_duration_ms) by (job_class)

# P95 duration
job_duration_p95_ms

# Failure rate
job_failure_rate > 5
```

**3. Worker Health**

```promql
# Worker count
worker_count

# Worker efficiency
worker_efficiency_percent

# Idle vs busy
worker_busy_count / worker_count * 100
```

**4. System Health**

```promql
# Queue health score
queue_health_score

# Jobs failing
rate(job_total_failed[5m])

# Queue depth growth
deriv(queue_depth[5m])
```

## Alerting Rules

### Prometheus Alerts

Create `alerts.yml`:

```yaml
groups:
  - name: queue_alerts
    interval: 30s
    rules:
      - alert: QueueDepthHigh
        expr: queue_depth > 1000
        for: 5m
        labels:
          severity: warning
        annotations:
          summary: "Queue depth high"
          description: "{{ $labels.queue }} has {{ $value }} jobs"

      - alert: QueueDepthCritical
        expr: queue_depth > 5000
        for: 2m
        labels:
          severity: critical
        annotations:
          summary: "Queue depth critical"
          description: "{{ $labels.queue }} has {{ $value }} jobs"

      - alert: JobFailureRateHigh
        expr: job_failure_rate > 10
        for: 10m
        labels:
          severity: warning
        annotations:
          summary: "High job failure rate"
          description: "{{ $labels.job_class }} failing at {{ $value }}%"

      - alert: JobSlowPerformance
        expr: job_duration_p95_ms > 10000
        for: 15m
        labels:
          severity: warning
        annotations:
          summary: "Job performance degraded"
          description: "{{ $labels.job_class }} P95: {{ $value }}ms"

      - alert: NoActiveWorkers
        expr: worker_count == 0
        for: 2m
        labels:
          severity: critical
        annotations:
          summary: "No active workers"
          description: "{{ $labels.queue }} has no workers"

      - alert: WorkerEfficiencyLow
        expr: worker_efficiency_percent < 30
        for: 30m
        labels:
          severity: info
        annotations:
          summary: "Worker efficiency low"
          description: "Efficiency: {{ $value }}%"

      - alert: QueueHealthPoor
        expr: queue_health_score < 50
        for: 5m
        labels:
          severity: critical
        annotations:
          summary: "Queue health critical"
          description: "{{ $labels.queue }} health: {{ $value }}/100"

      - alert: OldJobsPending
        expr: queue_oldest_job_age_seconds > 3600
        for: 5m
        labels:
          severity: warning
        annotations:
          summary: "Old jobs pending"
          description: "Oldest job: {{ $value }}s old"
```

### Alertmanager Configuration

Route alerts to different channels:

```yaml
# alertmanager.yml
route:
  group_by: ['alertname', 'queue']
  group_wait: 30s
  group_interval: 5m
  repeat_interval: 4h
  receiver: 'slack-ops'

  routes:
    - match:
        severity: critical
      receiver: 'pagerduty'

    - match:
        severity: warning
      receiver: 'slack-ops'

    - match:
        severity: info
      receiver: 'slack-monitoring'

receivers:
  - name: 'slack-ops'
    slack_configs:
      - api_url: 'https://hooks.slack.com/services/YOUR/SLACK/WEBHOOK'
        channel: '#ops-alerts'
        title: 'Queue Alert: {{ .GroupLabels.alertname }}'

  - name: 'pagerduty'
    pagerduty_configs:
      - service_key: 'YOUR_PAGERDUTY_KEY'

  - name: 'slack-monitoring'
    slack_configs:
      - api_url: 'https://hooks.slack.com/services/YOUR/SLACK/WEBHOOK'
        channel: '#monitoring'
```

## Recording Rules

Pre-calculate expensive queries:

```yaml
# recording_rules.yml
groups:
  - name: queue_metrics
    interval: 30s
    rules:
      # Total queue depth across all queues
      - record: queue:depth:total
        expr: sum(queue_depth) by (connection)

      # Average job duration by connection
      - record: job:duration:avg_by_connection
        expr: avg(job_duration_ms) by (connection)

      # Total jobs processed per hour
      - record: job:processed:rate_1h
        expr: sum(rate(job_total_processed[1h])) by (connection, queue)

      # Total jobs failed per hour
      - record: job:failed:rate_1h
        expr: sum(rate(job_total_failed[1h])) by (connection, queue)

      # Worker utilization
      - record: worker:utilization:percent
        expr: worker_busy_count / worker_count * 100

      # Queue health average
      - record: queue:health:avg
        expr: avg(queue_health_score) by (connection)
```

## PromQL Query Examples

### Queue Analysis

```promql
# Queue depth trend (last hour)
increase(queue_depth[1h])

# Queue processing rate average
avg_over_time(queue_processing_rate[5m])

# Queues with depth > threshold
queue_depth > 500

# Queue depth by connection
sum(queue_depth) by (connection)
```

### Job Performance

```promql
# Slowest jobs (P95)
topk(10, job_duration_p95_ms)

# Jobs with high failure rate
job_failure_rate > 5

# Job throughput comparison
job_throughput_per_minute

# Duration increase over time
rate(job_duration_ms[1h])
```

### Worker Monitoring

```promql
# Worker count by queue
worker_count

# Worker efficiency trend
avg_over_time(worker_efficiency_percent[15m])

# Workers using high memory
worker_memory_mb > 500

# Worker CPU usage
avg(worker_cpu_percent) by (connection, queue)
```

### Alerting Queries

```promql
# Jobs backing up
deriv(queue_depth[5m]) > 10

# Consistent failures
rate(job_total_failed[5m]) > 0.1

# No progress
rate(job_total_processed[5m]) == 0

# Health declining
deriv(queue_health_score[10m]) < -10
```

## Best Practices

### 1. Label Strategy

Use consistent labels across all metrics:

```promql
# Good - consistent labels
{connection="redis", queue="default", job_class="App\\Jobs\\ProcessOrder"}

# Bad - inconsistent
{conn="redis", q="default"}
```

### 2. Scrape Intervals

- **30 seconds**: Standard for most metrics
- **15 seconds**: High-frequency critical queues
- **1 minute**: Low-priority monitoring

### 3. Retention

Configure based on your needs:

```yaml
# prometheus.yml
storage:
  tsdb:
    retention.time: 15d  # Keep 15 days of data
    retention.size: 50GB # Or 50GB max
```

### 4. High Cardinality

Avoid creating too many unique label combinations:

```promql
# Good - low cardinality
job_duration_ms{job_class="App\\Jobs\\ProcessOrder"}

# Bad - high cardinality (if you have unique job_ids)
job_duration_ms{job_id="12345"}
```

### 5. Grafana Variables

Use template variables for flexibility:

```
$connection: redis, database
$queue: default, high-priority, emails
$job: App\Jobs\ProcessOrder, App\Jobs\SendEmail
```

Then use in queries:

```promql
queue_depth{connection="$connection", queue="$queue"}
```

## Troubleshooting

### Metrics Not Appearing

1. **Verify endpoint accessible**:
   ```bash
   curl http://your-app.test/queue-metrics/prometheus
   ```

2. **Check Prometheus config**:
   ```bash
   promtool check config prometheus.yml
   ```

3. **View Prometheus targets**:
   Visit `http://prometheus:9090/targets`

### High Scrape Duration

If scraping takes >5 seconds:

1. **Reduce queue count** being monitored
2. **Increase scrape interval** to 60s
3. **Use recording rules** for expensive queries
4. **Cache metrics** in Laravel (not recommended)

### Missing Labels

Ensure labels are present in the metric:

```php
// config/queue-metrics.php
'prometheus' => [
    'namespace' => 'laravel_queue',
    'include_hostname' => true, // Add hostname label
],
```

## Next Steps

- [API Endpoints](api-endpoints.md) - HTTP API reference
- [Configuration Reference](configuration-reference.md) - All configuration options
