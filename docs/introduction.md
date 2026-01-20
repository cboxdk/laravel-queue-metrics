---
title: "Introduction"
description: "Comprehensive queue monitoring and metrics collection for Laravel applications"
weight: 1
---

# Laravel Queue Metrics

Welcome to Laravel Queue Metrics - a comprehensive package for queue monitoring and metrics collection in Laravel applications.

## What is Laravel Queue Metrics?

Laravel Queue Metrics provides deep visibility into your Laravel queue operations, helping you monitor performance, identify bottlenecks, and optimize your background job processing.

### Key Features

- **Real-time Metrics**: Track job execution times, success rates, and failure patterns
- **Queue Health Monitoring**: Monitor queue sizes, worker status, and system resources
- **Multiple Storage Backends**: Choose between Redis (recommended) or database storage
- **Prometheus Integration**: Export metrics for monitoring with Prometheus and Grafana
- **Flexible API**: HTTP endpoints and PHP facade for programmatic access
- **Event System**: React to metrics changes and integrate with your application
- **Performance Optimized**: Minimal overhead with efficient data structures

## Getting Started

Choose your path based on your needs:

### Quick Start (5 Minutes)

Want to get up and running immediately? Follow the [Quick Start Guide](quickstart) to install and start collecting metrics in minutes.

### Complete Installation

For detailed installation instructions including storage backend selection and configuration options, see the [Installation Guide](installation).

### Configuration

Need to customize behavior? Check the [Configuration Reference](configuration-reference) for all available options.

## Core Features

### Basic Usage

Learn how to access metrics and integrate with your application:

- [Facade API](basic-usage/facade-api) - Primary developer interface for programmatic access
- [HTTP API](basic-usage/api-endpoints) - REST endpoints for metrics retrieval
- [Artisan Commands](basic-usage/artisan-commands) - CLI tools for metrics management

### Advanced Features

Deep dive into advanced capabilities:

- [Architecture Overview](advanced-usage/architecture) - Understand how the package works internally
- [Events System](advanced-usage/events) - React to metrics changes and lifecycle events
- [Prometheus Integration](advanced-usage/prometheus) - Export metrics for monitoring infrastructure
- [Performance Tuning](advanced-usage/performance) - Optimize for your specific workload

## Need Help?

- **Quick answers**: See the [Quick Start Guide](quickstart)
- **Configuration issues**: Check the [Configuration Reference](configuration-reference)
- **Found a bug?**: Report it on [GitHub Issues](https://github.com/cboxdk/laravel-queue-metrics/issues)

## Package Requirements

- **PHP**: 8.3 or higher
- **Laravel**: 11.0 or higher (12.0+ recommended)
- **Storage**: Redis (recommended) or Database

## License

Laravel Queue Metrics is open-source software licensed under the MIT license.
