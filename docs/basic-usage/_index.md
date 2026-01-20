---
title: "Basic Usage"
description: "Essential features and interfaces for accessing queue metrics"
weight: 10
---

# Basic Usage

This section covers the fundamental ways to access and work with Laravel Queue Metrics in your application.

## Available Interfaces

Laravel Queue Metrics provides three primary interfaces for accessing metrics:

### Facade API

The recommended way to access metrics programmatically in your PHP code. The `QueueMetrics` facade provides a clean, type-safe interface with full IDE autocomplete support.

**Best for:** Background jobs, scheduled commands, application logic

**Learn more:** [Facade API](facade-api)

### HTTP API

RESTful endpoints for accessing metrics via HTTP requests. Perfect for dashboards, monitoring tools, and external integrations.

**Best for:** Frontend dashboards, external monitoring, API integrations

**Learn more:** [HTTP API Endpoints](api-endpoints)

### Artisan Commands

Command-line tools for metrics management, maintenance, and debugging during development.

**Best for:** Development, debugging, scheduled maintenance tasks

**Learn more:** [Artisan Commands](artisan-commands)

## Quick Examples

### Get Job Metrics

```php
use Cbox\LaravelQueueMetrics\Facades\QueueMetrics;

$metrics = QueueMetrics::getJobMetrics(\App\Jobs\ProcessOrder::class);
echo "Success rate: {$metrics->successRate}%\n";
```

### Check Queue Health

```bash
curl http://your-app.test/queue-metrics/queues/default
```

### View Active Workers

```bash
php artisan queue-metrics:workers
```

## Next Steps

Once you're familiar with the basic interfaces, explore:

- [Architecture](../advanced-usage/architecture) - Understand how metrics are collected
- [Events](../advanced-usage/events) - React to metrics changes
- [Performance Tuning](../advanced-usage/performance) - Optimize for your workload
