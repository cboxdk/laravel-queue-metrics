# Laravel Queue Metrics - Project Overview

## Project Purpose
A production-ready Laravel queue monitoring package providing deep observability into queue systems, job execution, worker performance, and server resource metrics.

## Tech Stack
- **Language**: PHP 8.2+
- **Framework**: Laravel 11.0+ or 12.0+
- **Testing**: Pest 4.0
- **Storage**: Redis (primary) or Database (persistent)
- **Monitoring**: Prometheus export support
- **Dependencies**: gophpeek/system-metrics, spatie/laravel-prometheus

## Code Style & Conventions
- **Format**: Laravel Pint (PSR-12)
- **Analysis**: PHPStan level 8
- **Type Hints**: Full type declarations (PHP 8.2+)
- **Naming**: PSR-4 autoloading, camelCase for methods/properties
- **Class Style**: `readonly` classes with constructor injection
- **Docstrings**: PHPDoc with @param, @return, @throws
- **Access Modifiers**: Explicit public/private/protected, `final` on classes

## Commands
- **Test**: `composer test` (Pest)
- **Lint/Format**: `composer format` (Pint)
- **Static Analysis**: `composer analyse` (PHPStan)

## Code Structure
```
src/
├── Actions/              # Business logic (Record*, Calculate*, Transition*)
├── Listeners/            # Event handlers (job & worker lifecycle)
├── Repositories/         # Data access layer
├── Services/             # High-level operations
├── Storage/              # Storage driver implementations
├── DataTransferObjects/  # Immutable data objects
├── Http/                 # Controllers & routes
├── Commands/             # Artisan commands
├── Events/               # Custom events
└── Exceptions/           # Custom exceptions
```

## Current Event System
**Registered in LaravelQueueMetricsServiceProvider::packageBooted()**

**Job Lifecycle Events**:
- `JobQueued` → JobQueuedListener
- `JobProcessing` → JobProcessingListener
- `JobProcessed` → JobProcessedListener
- `JobFailed` → JobFailedListener
- `JobRetryRequested` → JobRetryRequestedListener
- `JobTimedOut` → JobTimedOutListener
- `JobExceptionOccurred` → JobExceptionOccurredListener

**Worker Lifecycle Events**:
- `WorkerStopping` → WorkerStoppingListener
- `Looping` → LoopingListener (loop iteration heartbeats)

## Worker ID Generation
Currently uses: `sprintf('worker_%s_%d', gethostname() ?: 'unknown', getmypid())`
This is a hostname + PID combination that works for standard workers but needs investigation for Horizon.
