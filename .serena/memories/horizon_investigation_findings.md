# Laravel Horizon Investigation Findings

## 1. Horizon Architecture vs Standard Queue Workers

### Standard Queue Workers
- Single process: `php artisan queue:work`
- Processes jobs directly from queue
- Minimal overhead
- Works with any queue driver (Redis, Database, File, SQS, etc.)
- Basic event system: JobQueued, JobProcessing, JobProcessed, JobFailed, Looping, WorkerStopping

### Horizon Architecture
- **Master Process**: Runs as daemon, manages supervisors
- **Supervisors**: Named supervisor processes (default: "supervisor-1") that manage worker pools
- **Workers**: Actually spawned using `php artisan horizon:work` (not `queue:work`)
- **Redis Only**: Requires Redis for configuration and synchronization
- **Configuration Driven**: All worker config in `config/horizon.php`
- **Balancing**: Three strategies: auto, simple, or false
- **Parent-Child Relationship**: MasterSupervisor → Supervisor → Workers

### Key Process Structure
```
Master Process (php artisan horizon)
└─ Supervisor-1 (php artisan horizon:supervisor --supervisor-name=supervisor-1)
   ├─ Worker Process 1 (php artisan horizon:work)
   ├─ Worker Process 2 (php artisan horizon:work)
   └─ Worker Process 3 (php artisan horizon:work)
```

## 2. Worker Identification in Horizon

### Current Implementation (Standard Workers)
Uses: `sprintf('worker_%s_%d', gethostname() ?: 'unknown', getmypid())`
Example: `worker_server1_12345`

### Horizon Worker Identification
When Horizon spawns workers, it can pass:
- `--supervisor-name`: Name of the parent supervisor
- `--workers-name`: Custom identifier for worker group
- Parent process ID context available
- Can customize via `MasterSupervisor::determineNameUsing()`

**Problem**: Current worker ID generation (hostname + PID) doesn't include supervisor information, making it difficult to correlate Horizon workers with their parent supervisors.

## 3. Horizon-Specific Events

### Standard Queue Worker Events (Already Supported)
- JobQueued, JobProcessing, JobProcessed, JobFailed, JobRetryRequested, JobTimedOut, JobExceptionOccurred
- WorkerStopping, Looping

### Horizon-Specific Considerations
- **No New Events**: Horizon doesn't emit additional Laravel events
- **Uses Standard Queue Events**: Horizon-spawned workers still fire standard Laravel queue events
- **Worker Identification Context**: The challenge is that we need to know:
  - Is this worker running under Horizon?
  - Which supervisor is it managed by?
  - This context isn't available in standard events

### Detection Mechanism
Key insight: When `horizon:work` is called, Horizon likely sets environment variables or arguments:
- Probable: `HORIZON_SUPERVISOR` or similar environment variable
- Probable: Command-line arguments like `--supervisor=supervisor-1`
- Need to detect supervisor context to identify Horizon workers

## 4. Current Listener Compatibility Issues

### WorkerStoppingListener
**Status**: Works with both standard and Horizon workers
- Uses `WorkerStopping` event (fired by both)
- Current ID generation doesn't capture Horizon supervisor context
- **Issue**: Can't distinguish between Horizon and standard workers or which supervisor they belong to

### LoopingListener
**Status**: Works with both, but may miss Horizon-specific context
- Uses `Looping` event (fired on each loop iteration)
- Captures heartbeats correctly for standard workers
- **Issue**: Doesn't capture supervisor information for Horizon workers
- **Issue**: May not capture true Horizon health metrics (supervisor balancing, dynamic worker scaling)

## 5. Horizon-Specific Metrics We're Missing

### Worker-Level Metrics
1. **Supervisor Association**: Which supervisor manages this worker?
2. **Horizon Status**: Is worker running under Horizon or standalone?
3. **Supervisor-Level Metrics**:
   - Supervisor uptime
   - Supervisor restart frequency
   - Balancing activity (workers added/removed per queue)
4. **Worker Pool Metrics**:
   - Processes per queue (from Horizon's perspective)
   - Worker rebalancing events
   - Supervisor coordination overhead

### Horizon Dashboard Data
- Horizon has `horizon:snapshot` command for metrics
- Stores in Redis under `horizon:` prefix
- Provides: job throughput, wait times, failures
- Our package could integrate with this data

## 6. Environment Detection Strategy

### How to Detect Horizon Workers
1. Check environment variables set by Horizon supervisor process
2. Check process arguments/command line
3. Look for `--supervisor-name` argument
4. Check parent process ID
5. Check if `HORIZON_SUPERVISOR` env var is set
6. Check if running under `horizon:work` vs `queue:work`

### Recommended Approach
Create a worker identification service that:
- Detects if running under Horizon
- Extracts supervisor name (if Horizon)
- Falls back to current implementation for standard workers
- Provides unified worker identity across both environments

## 7. Events NOT Fired by Standard Listeners

Horizon fires these through its own system, NOT through Laravel events:
- Supervisor lifecycle events (start, stop, restart)
- Worker rebalancing events
- Supervisor health checks
- Worker auto-scaling triggers

These are handled internally by Horizon's MasterSupervisor and not exposed as Laravel events.

## 8. Storage Considerations for Horizon

### Redis Connection Name
- Horizon uses a Redis connection named `horizon` (configured in horizon.php)
- Our package uses queue-metrics Redis for storage
- Could be same or different Redis instance

### Supervisor Name Customization
- Can be customized via `MasterSupervisor::determineNameUsing()`
- Default: "supervisor-1"
- Important for multi-environment deployments (e.g., Kubernetes)
- Should include in worker identity for reliable tracking

## Key Takeaways

1. **No New Events to Listen To**: Horizon uses standard Laravel queue events
2. **Worker ID Challenge**: Current implementation doesn't capture Horizon supervisor context
3. **Detection Needed**: Must detect when running under Horizon and extract supervisor name
4. **Supervisor Information**: Should include in worker identification string
5. **Metrics Gap**: Missing supervisor-level metrics and balancing activity
6. **Integration Point**: Could read Horizon's Redis snapshot data for additional insights
7. **Backwards Compatibility**: Changes must work for both Horizon and standard workers
