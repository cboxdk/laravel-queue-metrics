---
title: "Redis Cluster"
description: "Run the metrics store on a Redis Cluster and monitor cluster-hosted queues"
weight: 35
---

# Redis Cluster

Since v3.3.0 the Redis metrics store works on a Redis Cluster: key scanning
covers every master node, queue-depth reads use the same `{hash tag}` keys
Laravel writes on a cluster, and the transaction and pipeline write paths issue
commands individually so they work on a slot-routed connection. Single-node
connections behave exactly as before.

## Requirements

- **phpredis** with a `\RedisCluster` connection. Cluster detection checks the
  underlying client, so a Predis cluster connection is treated as a single node
  and is not supported for cluster mode.
- **Laravel 13 for cluster-hosted queues.** Laravel's own `RedisQueue` can only
  push to a cluster on Laravel 13, where it wraps queue keys in a `{hash tag}`.
  On Laravel 11 and 12 you can still store *metrics* on a cluster; the queues
  themselves must live on a non-cluster Redis.

## Configuration

Point the storage connection at a cluster connection defined in
`config/database.php`:

```php
// config/database.php
'redis' => [
    'client' => 'phpredis',
    'options' => [
        'cluster' => 'redis',
    ],
    'clusters' => [
        'metrics' => [
            ['host' => '10.0.0.1', 'port' => 7000],
            ['host' => '10.0.0.2', 'port' => 7000],
            ['host' => '10.0.0.3', 'port' => 7000],
        ],
    ],
],
```

```php
// config/queue-metrics.php
'storage' => [
    'driver' => 'redis',
    'connection' => 'metrics',
],
```

## Behavior differences on a cluster

- `transaction()` is not atomic across slots: commands are issued individually
  instead of through `MULTI`/`EXEC`, so an exception mid-callback can leave a
  partial write. All built-in callers tolerate this.
- `scanKeys()` scans each master node and merges the results.
- Worker heartbeats update the worker hash through a single-key Lua script
  (the read-modify-write that must be atomic) and the worker index with plain
  single-key commands. The two writes are not atomic with each other, but a
  missed index update self-heals on the next heartbeat seconds later.

## Verification

The test suite runs against a real 3-master cluster in CI (see the
`test-with-redis-cluster` job), covering key scanning, hash-tagged depth
reads, and the transaction and pipeline write paths.
