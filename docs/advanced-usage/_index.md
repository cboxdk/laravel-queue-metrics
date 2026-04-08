---
title: "Advanced Usage"
description: "Deep dive into architecture, events, integrations, and performance optimization"
weight: 30
---

# Advanced Usage

This section covers advanced features, internal architecture, and optimization strategies for Queue Metrics for Laravel.

## Advanced Topics

### Architecture & Internals

Understand how Queue Metrics for Laravel works under the hood, including the event-driven architecture, data storage patterns, and internal components.

**Learn more:** [Architecture Overview](architecture)

### Events System

React to metrics changes and lifecycle events in your application. Build custom integrations, trigger alerts, and implement auto-scaling based on queue metrics.

**Learn more:** [Events System](events)

### Prometheus Integration

Export queue metrics to Prometheus for centralized monitoring with Grafana dashboards and alerting rules.

**Learn more:** [Prometheus Integration](prometheus)

### Performance Tuning

Optimize queue metrics collection for your specific workload, including storage configuration, batch processing, and resource management.

**Learn more:** [Performance Tuning](performance)

## When to Use Advanced Features

### Architecture Knowledge

Understanding the architecture helps when:
- Debugging complex issues
- Building custom storage drivers
- Extending the package with hooks
- Optimizing for specific use cases

### Events Integration

Use events when you need to:
- Send alerts based on queue health
- Trigger auto-scaling decisions
- Integrate with external monitoring
- Implement custom business logic

### Prometheus Export

Consider Prometheus integration for:
- Centralized monitoring infrastructure
- Long-term metrics retention
- Multi-service dashboards
- Advanced alerting rules

### Performance Optimization

Performance tuning is essential for:
- High-throughput systems (>10,000 jobs/min)
- Large-scale deployments
- Resource-constrained environments
- Cost optimization

## Prerequisites

Before diving into advanced topics, ensure you're familiar with:

- [Basic Usage](../basic-usage) - Facade API, HTTP endpoints, and commands
- [Configuration Reference](../configuration-reference) - Available configuration options
- Laravel's event system and service container

## Getting Started

Choose a topic based on your needs:

1. **Understanding the System:** Start with [Architecture](architecture)
2. **Integration & Automation:** Explore [Events](events)
3. **Monitoring & Observability:** Set up [Prometheus](prometheus)
4. **Scale & Optimize:** Review [Performance](performance)
