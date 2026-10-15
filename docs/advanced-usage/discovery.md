---
title: "Queue and Job Discovery"
description: "How the package finds the queues and job classes it reports on, and how retired ones age out of discovery"
weight: 36
---

# Queue and Job Discovery

The package keeps two discovery lists: the queues it has seen jobs run on, and
the job classes it has seen run on each queue. Several calculations iterate over
these lists instead of scanning the whole keyspace. This page covers how entries
get onto the lists, which features read them, and how an entry for a job class or
queue you no longer use ages out.

## How Entries Are Recorded

Discovery is push-based. Every time a worker starts a job, the `JobProcessing`
listener calls `JobMetricsRepository::recordStart()`, which marks two entries as
seen:

- the queue, as `{connection}:{queue}` (for example `redis:emails`)
- the job class on that queue, as `{connection}:{queue}:{jobClass}`, with each
  segment escaped as described in
  [Escaped Segments](architecture.md#escaped-segments)
  (for example `redis:reports~cnightly:App\Jobs\Report`)

You can register an entry yourself, for example to list a queue before its
first job has run:

```php
use Cbox\LaravelQueueMetrics\Repositories\Contracts\JobMetricsRepository;
use Cbox\LaravelQueueMetrics\Repositories\Contracts\QueueMetricsRepository;

app(QueueMetricsRepository::class)->markQueueDiscovered('redis', 'emails');
app(JobMetricsRepository::class)->markJobDiscovered('redis', 'emails', App\Jobs\SendEmail::class);
```

An entry registered this way ages out like any other: register it again
within the retention period to keep it.

Dispatching a job does not mark anything as seen; only a job starting does. A
queue that receives jobs but has no worker running them for longer than the
retention period drops out of discovery until a worker starts one of its jobs.

## What Reads Discovery

| Reader | Uses |
|--------|------|
| `QueueMetricsRepository::listQueues()` | the queue list |
| `JobMetricsRepository::listJobs()` | the job class list |
| `queue-metrics:calculate` (`CalculateQueueMetricsAction`) | the queue list to choose which queues to calculate, the job class list to aggregate each queue |
| `queue-metrics:calculate-baselines` (`CalculateBaselinesAction`) | the queue list |
| Adaptive baseline scheduling | the queue list, to decide whether the baseline command can skip a run |
| `GET /queue-metrics/jobs/{jobClass}` (`getAggregatedJobMetrics()`) | the job class list, to find every queue the class runs on |

`QueueMetrics::getAllQueuesWithMetrics()` and `QueueMetrics::getAllJobsWithMetrics()`
do **not** read discovery. They find queues and job classes by scanning the
per-job-class `jobs:` and `queued:` metric keys, which expire on the `raw` TTL
(1 hour by default) once the job class stops running.

## Retention and Ageing

Each entry carries the time it was last seen, and is dropped once it has not
been seen for `queue-metrics.storage.ttl.aggregated` seconds (7 days by
default). Entries age out individually: a job class that was renamed or deleted,
or a queue nothing dispatches to any more, drops off the lists after the
retention period no matter how busy the rest of the application is.

```php
// config/queue-metrics.php
'storage' => [
    'ttl' => [
        // Also how long a queue or job class stays discovered
        // after it last ran.
        'aggregated' => 604800, // 7 days
    ],
],
```

The boundary is the same on both drivers: an entry last seen exactly one
retention period ago has expired. Seeing an entry again resets its clock.

A shorter `aggregated` TTL lets a retired queue or job class drop out sooner. But
a job that runs less often than the TTL then drops out between runs, and is only
listed again when it next starts.

### Redis Driver

Since v3.5.0 each list is a sorted set scored by the Unix time the entry was
last seen:

| Key | Type | Members |
|-----|------|---------|
| `{prefix}:discovery:queues:seen` | sorted set | `{connection}:{queue}` |
| `{prefix}:discovery:jobs:seen` | sorted set | `{connection}:{queue}:{jobClass}` (escaped) |

`{prefix}` is `queue-metrics.storage.prefix` (`queue_metrics` by default), and
Laravel's Redis connection prefix comes before it on the server.

- **On a job start** the entry is added with `ZADD` and the current time as its
  score. This replaces the `SADD` earlier versions ran, inside the same
  transaction, so a job start sends the same number of commands as before. The
  key also gets the `aggregated` TTL with `EXPIRE`. That is only a safety net: if
  nothing has been seen for a whole retention period, every entry has expired
  anyway.
- **On a read** entries last seen one retention period ago or earlier are
  removed with `ZREMRANGEBYSCORE`, then the rest are read with `ZRANGE`.
  Expired entries are removed only when something reads the list, such as the
  scheduled `queue-metrics:calculate`. Reading therefore writes, so
  `queue-metrics.storage.connection` must point at a writable Redis, not a
  read-only replica.

Every command touches a single key, so discovery works on a
[Redis Cluster](redis-cluster.md) where the keys hash to different slots.

To see what is discovered and when each entry was last seen:

```bash
redis-cli ZRANGE laravel_database_queue_metrics:discovery:queues:seen 0 -1 WITHSCORES
redis-cli ZRANGE laravel_database_queue_metrics:discovery:jobs:seen 0 -1 WITHSCORES
```

Replace `laravel_database_` with your connection's prefix (`database.redis.options.prefix`).

### Database Driver

The database driver stores discovery entries as rows in the
`queue_metrics_sets` table (with the default prefix) under the keys `discovery:queues` and
`discovery:jobs`. Since v3.4.2 each row has its own `expires_at`, which every
job start pushes forward by the `aggregated` TTL. Expired rows are hidden from
reads and removed by `queue-metrics:cleanup-database`.

## Upgrading to v3.5.0

Before v3.5.0 the Redis driver kept discovery in plain sets,
`{prefix}:discovery:queues` and `{prefix}:discovery:jobs`. Every job start
refreshed the TTL of the whole set, so an entry never expired while any other
job ran. The sorted sets use new keys because a key that holds a set cannot hold
a sorted set; Redis rejects sorted-set commands on it with `WRONGTYPE`.

No action is needed to upgrade. During a rolling deploy:

- **Workers on v3.5.0** write only the new sorted sets. They never write the old
  plain sets.
- **Readers on v3.5.0** read the new sorted sets *and* merge the old plain sets,
  so entries that workers still on v3.4 write are listed too. Nothing is lost
  while old and new workers run side by side.
- **Readers still on v3.4**, such as a scheduler host upgraded last, read only
  the old plain sets. Until they are upgraded they do not see a queue or job
  class that has only run on v3.5.0 workers. Upgrade the host that runs
  `queue-metrics:calculate` together with, or before, the workers. The old sets are read only if the
  key holds a plain set, so a key of any other type is ignored rather than
  causing an error.
- **The old plain sets expire on their own.** Only v3.4 workers refresh their
  TTL, so each one disappears at most one `aggregated` TTL (7 days by default)
  after the last v3.4 worker started its last job. Until then, an entry that
  exists only in an old set is still listed, as it was in v3.4. Versions before
  v2.0 wrote the old sets without a TTL; since v3.5.1 a reader gives such a set
  the `aggregated` TTL the first time it reads it, so it expires too.
- **Job class entries written before v3.4.1**, which cannot be read back with
  the right queue name, are removed from both the old set and the sorted set the
  first time they are read, as in v3.4.2.

To drop the old sets right away once every worker runs v3.5.0, delete them:

```bash
redis-cli DEL laravel_database_queue_metrics:discovery:queues laravel_database_queue_metrics:discovery:jobs
```

That drops entries which exist only in the old sets, typically queues and job
classes that have not run since the upgrade. Each one is listed again the next
time one of its jobs starts.

On a Redis Cluster the two keys may sit on different slots, so delete them one
at a time.

### Rolling back

v3.4 reads only the old plain sets, which v3.5.0 does not write. After a rollback
to v3.4, discovery lists only what is still in those sets until each queue and
job class runs again on v3.4 and is re-registered. Snapshots and baselines for a
queue that is missing in the meantime are not calculated until it reappears.
