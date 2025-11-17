# Laravel Horizon Support - Implementation Recommendations

## Executive Summary

Our laravel-queue-metrics package **currently works with Horizon**, as Horizon workers fire the same standard Laravel queue events (JobProcessing, JobProcessed, WorkerStopping, Looping). However, we're **missing Horizon-specific context** that would enable better metrics and supervisor tracking.

## Current State

### What Works
✅ Job metrics collection (standard events fire the same way)
✅ Worker heartbeats (LoopingListener captures Looping events)
✅ Worker stopping (WorkerStoppingListener fires on shutdown)
✅ All job lifecycle tracking works identically

### What's Missing
❌ Supervisor identification (can't correlate worker to parent supervisor)
❌ Horizon-specific metrics (balancing, rebalancing, supervisor health)
❌ Worker context enrichment (can't distinguish Horizon vs standard workers)
❌ Dynamic worker scaling tracking

## Required Changes for Full Horizon Support

### 1. Worker Identification Service (Priority: HIGH)

**Current Issue**: Worker ID is `worker_hostname_pid`
- Works for distinguishing workers
- Doesn't include supervisor context
- Can't tell if worker is under Horizon

**Solution**: Create `WorkerIdentificationService`

```
Responsibilities:
- Detect if running under Horizon
- Extract supervisor name (from args or env vars)
- Extract worker group name
- Build comprehensive worker ID including context
- Fallback to current implementation for standard workers

Implementation:
- Check for `--supervisor-name` in argv or $_SERVER['argv']
- Check for environment variables (Horizon may set these)
- Check parent process information if possible
- Use MasterSupervisor detection if Laravel Horizon is installed

New ID format:
- Standard: worker_hostname_pid
- Horizon: worker_horizon_supervisor-1_hostname_pid
```

### 2. Horizon Detection & Context Utility (Priority: HIGH)

**Create new utility class**: `src/Utilities/HorizonDetector.php`

```
Methods needed:
- isRunningUnderHorizon(): bool
  * Check if horizon:work command is running (not queue:work)
  * Check for Horizon supervisor context
  
- getSupervisorName(): ?string
  * Extract from argv: --supervisor-name=supervisor-1
  * Check environment variables
  * Default fallback: null
  
- getWorkersName(): ?string
  * Extract from argv: --workers-name=worker-pool-1
  * Used for worker group identification
  
- getParentSupervisorPid(): ?int
  * Extract from argv: --parent-id=123
  * Useful for process hierarchy tracking

Usage:
$horizonContext = HorizonDetector::detectContext();
if ($horizonContext->isHorizon()) {
    $supervisorName = $horizonContext->getSupervisor();
}
```

### 3. Enhanced WorkerStoppingListener (Priority: MEDIUM)

**File**: `src/Listeners/WorkerStoppingListener.php`

Changes needed:
```php
private function getWorkerId(): string
{
    $horizonContext = HorizonDetector::detectContext();
    
    if ($horizonContext->isHorizon()) {
        return sprintf(
            'worker_horizon_%s_%s_%d',
            $horizonContext->getSupervisor(),
            gethostname() ?: 'unknown',
            getmypid()
        );
    }
    
    // Standard worker
    return sprintf(
        'worker_%s_%d',
        gethostname() ?: 'unknown',
        getmypid()
    );
}
```

### 4. Enhanced LoopingListener (Priority: MEDIUM)

**File**: `src/Listeners/LoopingListener.php`

Same worker ID enhancement as WorkerStoppingListener.

### 5. Extended Worker Metadata Repository (Priority: LOW)

**Enhancement to RedisWorkerRepository**:

```
New fields to capture (in recordHeartbeat):
- supervisor_name: ?string     // Horizon supervisor this worker belongs to
- is_horizon_worker: bool       // Identifies as Horizon worker
- parent_supervisor_pid: ?int   // Parent supervisor process ID
- workers_name: ?string         // Worker pool/group name
```

**Benefits**:
- Better worker tracking and grouping
- Enables supervisor-level metrics
- Allows querying by supervisor
- Helps detect worker ownership

### 6. Horizon-Aware Worker Detection Command (Priority: MEDIUM)

**Create**: `src/Commands/DetectHorizonWorkersCommand.php` (if useful)

Or enhance existing `DetectStaleWorkersCommand` to understand Horizon.

### 7. Data Models Enhancement (Priority: LOW)

**Update WorkerDTO** to include:
```php
?string $supervisorName
bool $isHorizonWorker
?int $parentSupervisorPid
?string $workersName
```

## Detection Mechanism Details

### How Horizon Calls Workers

When Horizon's supervisor process spawns workers, it likely uses:
```bash
php artisan horizon:work \
    --supervisor-name=supervisor-1 \
    --parent-id=1234 \
    --workers-name=workers \
    --queue=default \
    --timeout=60
```

These arguments are passed to the Laravel Artisan command which may be available in:
- `$_SERVER['argv']` - Direct argv access
- Command instance arguments (if we hook into console)
- Environment variables (if Horizon sets them)

### Fallback Detection Methods

1. **Check process arguments** (most reliable):
   ```php
   // From $_SERVER['argv'] or proc_get_status()
   // Look for: --supervisor-name=, --parent-id=, --workers-name=
   ```

2. **Environment variables** (if Horizon sets them):
   ```php
   // $_ENV['HORIZON_SUPERVISOR']
   // $_ENV['HORIZON_PARENT_ID']
   ```

3. **Parent process analysis** (as last resort):
   ```php
   // Check parent process info
   // Look for "horizon:supervisor" in parent process name
   ```

## Implementation Roadmap

### Phase 1: Core Detection (Week 1)
- [ ] Create HorizonDetector utility
- [ ] Create WorkerIdentificationService
- [ ] Add unit tests for detection

### Phase 2: Listener Updates (Week 2)
- [ ] Update WorkerStoppingListener
- [ ] Update LoopingListener
- [ ] Update RecordWorkerHeartbeatAction to use new service
- [ ] Add integration tests

### Phase 3: Data Model Enhancement (Week 3)
- [ ] Extend WorkerDTO
- [ ] Update repository methods
- [ ] Migrate existing worker data handling

### Phase 4: Testing & Documentation (Week 4)
- [ ] Create Horizon-specific tests
- [ ] Document Horizon setup
- [ ] Add examples in README

## Test Coverage Needs

```php
// New tests needed:

// 1. Detection tests
test('detects running under Horizon') {}
test('extracts supervisor name from argv') {}
test('extracts parent PID from argv') {}
test('falls back to standard worker context') {}

// 2. Listener tests
test('generates correct ID for Horizon worker') {}
test('generates correct ID for standard worker') {}
test('WorkerStoppingListener includes supervisor') {}
test('LoopingListener captures Horizon context') {}

// 3. Integration tests
test('Horizon-spawned workers recorded with supervisor') {}
test('worker metrics grouped by supervisor') {}
test('can query workers by supervisor') {}
```

## Configuration Considerations

Could add to `config/queue-metrics.php`:
```php
'horizon' => [
    'enabled' => env('QUEUE_METRICS_HORIZON_ENABLED', true),
    'capture_supervisor_metrics' => true,
    'capture_balancing_metrics' => false, // Not from events
],
```

## Backwards Compatibility

All changes must:
- ✅ Work with standard `queue:work` workers
- ✅ Work with `queue:listen` (deprecated)
- ✅ Work with `horizon:work` workers
- ✅ Maintain existing worker ID format for non-Horizon
- ✅ Not break existing metrics or storage

## Integration with Existing Code

### Affected Classes
- `WorkerStoppingListener` - needs worker ID enhancement
- `LoopingListener` - needs worker ID enhancement
- `RecordWorkerHeartbeatAction` - should receive enhanced worker ID
- `TransitionWorkerStateAction` - receives worker ID from listener
- `RedisWorkerRepository` - can store enhanced metadata

### No Changes Needed
- `JobQueuedListener`, `JobProcessingListener`, etc. - these fire identically
- `RecordJobStartAction`, `RecordJobCompletionAction` - no worker context
- Event registration in ServiceProvider - same events fire

## Additional Research Needed

1. Confirm exact argv format that Horizon uses
2. Check if Horizon sets any environment variables
3. Test with real Horizon deployment
4. Verify supervisor name customization works
5. Check if process parent info is available via proc_get_status()

## Resources

- Horizon config: `config/horizon.php`
- Horizon commands: `php artisan horizon`, `php artisan horizon:supervisor`
- Detection points: `--supervisor-name`, `--parent-id`, `--workers-name` argv
- Horizon documentation: https://laravel.com/docs/12.x/horizon

## Success Criteria

✅ Detect Horizon workers correctly in 95%+ of cases
✅ Maintain backwards compatibility with standard workers
✅ Include supervisor name in worker tracking
✅ Enable supervisor-level metrics queries
✅ Pass comprehensive test suite
✅ Zero performance overhead for standard workers
✅ Clear documentation for Horizon users
