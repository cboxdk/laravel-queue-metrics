# Changelog

All notable changes to `laravel-queue-metrics` will be documented in this file.

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
