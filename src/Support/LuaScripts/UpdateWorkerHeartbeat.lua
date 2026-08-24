-- Update worker heartbeat atomically.
--
-- Single-key on purpose: the read-modify-write on the worker hash is the part
-- that must be atomic (time accounting, jobs_processed, peak memory). The
-- worker index lives in a different cluster slot, so touching it here would be
-- a CROSSSLOT error on Redis Cluster; the caller updates it with plain
-- single-key commands instead.
--
-- KEYS[1]: worker hash key
-- ARGV[1]: worker_id
-- ARGV[2]: connection
-- ARGV[3]: queue
-- ARGV[4]: state (idle/busy/crashed)
-- ARGV[5]: current_job_id (or empty string)
-- ARGV[6]: current_job_class (or empty string)
-- ARGV[7]: pid
-- ARGV[8]: hostname
-- ARGV[9]: memory_usage_mb
-- ARGV[10]: cpu_usage_percent
-- ARGV[11]: current_timestamp
-- ARGV[12]: ttl

local workerKey = KEYS[1]

local workerId = ARGV[1]
local connection = ARGV[2]
local queue = ARGV[3]
local newState = ARGV[4]
local currentJobId = ARGV[5]
local currentJobClass = ARGV[6]
local pid = ARGV[7]
local hostname = ARGV[8]
local memoryUsageMb = tonumber(ARGV[9])
local cpuUsagePercent = tonumber(ARGV[10])
local now = tonumber(ARGV[11])
local ttl = tonumber(ARGV[12])

-- Get existing data (atomic read)
local existingData = redis.call('HGETALL', workerKey)
local existing = {}
for i = 1, #existingData, 2 do
    existing[existingData[i]] = existingData[i + 1]
end

-- Parse existing values with defaults
local previousState = existing['state'] or nil
local lastHeartbeat = tonumber(existing['last_heartbeat']) or now
local idleTime = tonumber(existing['idle_time_seconds']) or 0.0
local busyTime = tonumber(existing['busy_time_seconds']) or 0.0
local jobsProcessed = tonumber(existing['jobs_processed']) or 0
local previousPeakMemory = tonumber(existing['peak_memory_usage_mb']) or 0.0
local lastStateChange = tonumber(existing['last_state_change']) or now

-- Calculate time since last heartbeat
local timeSinceLastHeartbeat = now - lastHeartbeat

-- Update time in previous state
if previousState == 'idle' then
    idleTime = idleTime + timeSinceLastHeartbeat
elseif previousState == 'busy' then
    busyTime = busyTime + timeSinceLastHeartbeat
end

-- Increment jobs_processed if transitioning from busy to idle
if previousState == 'busy' and newState == 'idle' and currentJobId == '' then
    jobsProcessed = jobsProcessed + 1
end

-- Update last_state_change if state changed
if previousState ~= newState then
    lastStateChange = now
end

-- Track peak memory
local peakMemoryUsageMb = math.max(previousPeakMemory, memoryUsageMb)

-- Write all data atomically
redis.call('HSET', workerKey,
    'worker_id', workerId,
    'connection', connection,
    'queue', queue,
    'state', newState,
    'last_heartbeat', now,
    'last_state_change', lastStateChange,
    'current_job_id', currentJobId,
    'current_job_class', currentJobClass,
    'idle_time_seconds', idleTime,
    'busy_time_seconds', busyTime,
    'jobs_processed', jobsProcessed,
    'pid', pid,
    'hostname', hostname,
    'memory_usage_mb', memoryUsageMb,
    'cpu_usage_percent', cpuUsagePercent,
    'peak_memory_usage_mb', peakMemoryUsageMb
)

-- Set TTL on the worker key
redis.call('EXPIRE', workerKey, ttl)

-- Return updated jobs_processed for verification
return jobsProcessed
