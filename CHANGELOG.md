# Changelog

All notable changes to `laravel-queue-metrics` will be documented in this file.

## v2.2.0 - Add Laravel 13 support - 2026-04-05

### What's Changed

* feat: Add Laravel 13 support with orchestra/testbench ^11.0
* ci: Add Laravel 13 to test matrix (unit + Redis integration)
* docs: Update version references in README and documentation

**Full Changelog**: https://github.com/cboxdk/laravel-queue-metrics/compare/v2.1.1...v2.2.0

## v2.1.1 - Fix addToSet type error & CI hardening - 2026-04-04

### What's Changed

* fix: Wrap `$serverKey` in array for `PipelineWrapper::addToSet()` in `recordHostnameMetrics()` — caused `TypeError` in production
* fix: Resolve PHPStan level 9 errors (`new static` → `new self`, typed array extraction in `PrometheusService`)
* ci: Add `pull_request` triggers to PHPStan, Pint, and test workflows so they gate PRs
* ci: Limit `push` triggers to `main` branch to prevent duplicate CI runs
* ci: Add concurrency groups to cancel stale workflow runs

**Full Changelog**: https://github.com/cboxdk/laravel-queue-metrics/compare/v2.1.0...v2.1.1

## v2.1.0 - Fix type casting in artisan commands - 2026-04-04

### What's Changed

* fix: Cast CLI option and config values to int in `CleanupStaleWorkersCommand` — `$this->option()` returns `string|null`, but `cleanupStaleWorkers()` requires `int`
* fix: Extract depth integer from nested array in `RecordTrendDataCommand` — `getAllQueuesWithMetrics()` returns depth as `['total' => ..., 'pending' => ...]`, but `execute()` expects `int`
* tests: Add unit tests for both commands

**Full Changelog**: https://github.com/cboxdk/laravel-queue-metrics/compare/v2.0.0...v2.1.0

## v2.0.0 - Rebranded to Cbox & Quality of Life Improvements - 2026-01-20

### What's Changed

* chore(deps): bump dependabot/fetch-metadata from 2.4.0 to 2.5.0 by @dependabot[bot] in https://github.com/cboxdk/laravel-queue-metrics/pull/2

### New Contributors

* @dependabot[bot] made their first contribution in https://github.com/cboxdk/laravel-queue-metrics/pull/2

**Full Changelog**: https://github.com/cboxdk/laravel-queue-metrics/commits/v2.0.0

## v1.5.0 - 2026-01-09

### What's Changed

* fix: Cast job IDs to string in all listeners for database queue driver compatibility
  - Laravel's database queue driver returns int job IDs while Redis/SQS return strings
  - All listeners now cast `$job->getJobId()` to string for consistent handling
  - Repository interfaces accept `string|int` with internal string casting
  

**Full Changelog**: https://github.com/cboxdk/laravel-queue-metrics/compare/v1.4.0...v1.5.0

## v1.4.2 - 2026-01-08

### What's Changed

* fix: Cast job IDs to string in all listeners for consistency
  - Ensures consistent string type for job IDs throughout listener logic
  - Fixes potential type issues with database queue driver returning int IDs
  

**Full Changelog**: https://github.com/cboxdk/laravel-queue-metrics/compare/v1.4.1...v1.4.2

## v1.4.1 - 2026-01-08

### What's Changed

* fix: Accept both string and int job IDs from Laravel queue drivers
  - Laravel's database queue driver returns int job IDs while Redis/SQS drivers return string IDs
  - Changed `$jobId` parameter type from `string` to `string|int` in all actions and repository interfaces
  

**Full Changelog**: https://github.com/cboxdk/laravel-queue-metrics/compare/v1.4.0...v1.4.1

## v1.4.0 - 2026-01-02

### What's Changed

* feat: Add PHP 8.5 support
* Update all dependencies to latest versions

**Full Changelog**: https://github.com/cboxdk/laravel-queue-metrics/compare/v1.3.1...v1.4.0

## v1.3.1 - 2026-01-01

### What's Changed

* fix: Achieve PHPStan Level 9 compliance with empty baseline
  - Remove redundant type checks and operators
  - Add proper type annotations and validation guards
  - Fix Carbon timestamp casting for binary operations
  - Clear baseline from 32 errors to 0
  

**Full Changelog**: https://github.com/cboxdk/laravel-queue-metrics/compare/v1.3.0...v1.3.1

## v1.3.0 - 2025-11-20

### What's Changed

* refactor!: Restructure metrics response for clear abstraction separation by @sylvesterdamgaard in https://github.com/cboxdk/laravel-queue-metrics/pull/3

**Full Changelog**: https://github.com/cboxdk/laravel-queue-metrics/compare/v1.2.0...v1.3.0

## v1.2.0 - 2025-11-20

### What's Changed

* Fix race conditions and implement queue metrics aggregation by @sylvesterdamgaard in https://github.com/cboxdk/laravel-queue-metrics/pull/2

**Full Changelog**: https://github.com/cboxdk/laravel-queue-metrics/compare/v1.1.0...v1.2.0

## v1.1.0 - 2025-11-19

### What's Changed

* fix(redis): use spread operator for variadic Redis set operations by @sylvesterdamgaard in https://github.com/cboxdk/laravel-queue-metrics/pull/1

### New Contributors

* @sylvesterdamgaard made their first contribution in https://github.com/cboxdk/laravel-queue-metrics/pull/1

**Full Changelog**: https://github.com/cboxdk/laravel-queue-metrics/compare/v0.0.1...v1.1.0

## v1.0.0 - 2025-11-19

**Full Changelog**: https://github.com/cboxdk/laravel-queue-metrics/commits/v1.0.0
