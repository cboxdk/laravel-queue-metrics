---
title: "Requirements"
description: "PHP, Laravel, and package dependencies enforced by Queue Metrics for Laravel"
weight: 4
---

# Requirements

These are the dependencies Composer enforces when you install Queue Metrics for Laravel. They come directly from the package `composer.json` and are the versions the dependency resolver checks against.

## PHP

- **PHP**: `^8.3 | ^8.4 | ^8.5`

## Laravel

The package depends on `illuminate/contracts`, which tracks the Laravel release line:

- **illuminate/contracts**: `^11.0 | ^12.0 | ^13.0` (Laravel 11, 12, or 13)

## Package Dependencies

- **cboxdk/system-metrics**: `^3.0`
- **spatie/laravel-package-tools**: `^1.16`
- **spatie/laravel-prometheus**: `^1.3`

## Installation

Install the package with Composer:

```bash
composer require cboxdk/laravel-queue-metrics
```

For setup steps after installation, see the [Installation](installation.md) guide.
