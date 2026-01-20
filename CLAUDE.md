<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to enhance the user's satisfaction building Laravel applications.

## Foundational Context
This application is a Laravel application and its main Laravel ecosystems package & versions are below. You are an expert with them all. Ensure you abide by these specific packages & versions.

- php - 8.4.14
- laravel/framework (LARAVEL) - v12
- laravel/prompts (PROMPTS) - v0
- statamic/cms (STATAMIC) - v5
- laravel/mcp (MCP) - v0
- laravel/pint (PINT) - v1
- laravel/sail (SAIL) - v1
- phpunit/phpunit (PHPUNIT) - v11

## Conventions
- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts
- Do not create verification scripts or tinker when tests cover that functionality and prove it works. Unit and feature tests are more important.

## Application Structure & Architecture
- Stick to existing directory structure - don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling
- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Replies
- Be concise in your explanations - focus on what's important rather than explaining obvious details.

## Documentation Files
- You must only create documentation files if explicitly requested by the user.


=== boost rules ===

## Laravel Boost
- Laravel Boost is an MCP server that comes with powerful tools designed specifically for this application. Use them.

## Artisan
- Use the `list-artisan-commands` tool when you need to call an Artisan command to double check the available parameters.

## URLs
- Whenever you share a project URL with the user you should use the `get-absolute-url` tool to ensure you're using the correct scheme, domain / IP, and port.

## Tinker / Debugging
- You should use the `tinker` tool when you need to execute PHP to debug code or query Eloquent models directly.
- Use the `database-query` tool when you only need to read from the database.

## Reading Browser Logs With the `browser-logs` Tool
- You can read browser logs, errors, and exceptions using the `browser-logs` tool from Boost.
- Only recent browser logs will be useful - ignore old logs.

## Searching Documentation (Critically Important)
- Boost comes with a powerful `search-docs` tool you should use before any other approaches. This tool automatically passes a list of installed packages and their versions to the remote Boost API, so it returns only version-specific documentation specific for the user's circumstance. You should pass an array of packages to filter on if you know you need docs for particular packages.
- The 'search-docs' tool is perfect for all Laravel related packages, including Laravel, Inertia, Livewire, Filament, Tailwind, Pest, Nova, Nightwatch, etc.
- You must use this tool to search for Laravel-ecosystem documentation before falling back to other approaches.
- Search the documentation before making code changes to ensure we are taking the correct approach.
- Use multiple, broad, simple, topic based queries to start. For example: `['rate limiting', 'routing rate limiting', 'routing']`.
- Do not add package names to queries - package information is already shared. For example, use `test resource table`, not `filament 4 test resource table`.

### Available Search Syntax
- You can and should pass multiple queries at once. The most relevant results will be returned first.

1. Simple Word Searches with auto-stemming - query=authentication - finds 'authenticate' and 'auth'
2. Multiple Words (AND Logic) - query=rate limit - finds knowledge containing both "rate" AND "limit"
3. Quoted Phrases (Exact Position) - query="infinite scroll" - Words must be adjacent and in that order
4. Mixed Queries - query=middleware "rate limit" - "middleware" AND exact phrase "rate limit"
5. Multiple Queries - queries=["authentication", "middleware"] - ANY of these terms


=== php rules ===

## PHP

- Always use curly braces for control structures, even if it has one line.

### Constructors
- Use PHP 8 constructor property promotion in `__construct()`.
    - <code-snippet>public function __construct(public GitHub $github) { }</code-snippet>
- Do not allow empty `__construct()` methods with zero parameters.

### Type Declarations
- Always use explicit return type declarations for methods and functions.
- Use appropriate PHP type hints for method parameters.

<code-snippet name="Explicit Return Types and Method Params" lang="php">
protected function isAccessible(User $user, ?string $path = null): bool
{
    ...
}
</code-snippet>

## Comments
- Prefer PHPDoc blocks over comments. Never use comments within the code itself unless there is something _very_ complex going on.

## PHPDoc Blocks
- Add useful array shape type definitions for arrays when appropriate.

## Enums
- Typically, keys in an Enum should be TitleCase. For example: `FavoritePerson`, `BestLake`, `Monthly`.


=== herd rules ===

## Laravel Herd

- The application is served by Laravel Herd and will be available at: https?://[kebab-case-project-dir].test. Use the `get-absolute-url` tool to generate URLs for the user to ensure valid URLs.
- You must not run any commands to make the site available via HTTP(s). It is _always_ available through Laravel Herd.


=== laravel/core rules ===

## Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using the `list-artisan-commands` tool.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Database
- Always use proper Eloquent relationship methods with return type hints. Prefer relationship methods over raw queries or manual joins.
- Use Eloquent models and relationships before suggesting raw database queries
- Avoid `DB::`; prefer `Model::query()`. Generate code that leverages Laravel's ORM capabilities rather than bypassing them.
- Generate code that prevents N+1 query problems by using eager loading.
- Use Laravel's query builder for very complex database operations.

### Model Creation
- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `list-artisan-commands` to check the available options to `php artisan make:model`.

### APIs & Eloquent Resources
- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

### Controllers & Validation
- Always create Form Request classes for validation rather than inline validation in controllers. Include both validation rules and custom error messages.
- Check sibling Form Requests to see if the application uses array or string based validation rules.

### Queues
- Use queued jobs for time-consuming operations with the `ShouldQueue` interface.

### Authentication & Authorization
- Use Laravel's built-in authentication and authorization features (gates, policies, Sanctum, etc.).

### URL Generation
- When generating links to other pages, prefer named routes and the `route()` function.

### Configuration
- Use environment variables only in configuration files - never use the `env()` function directly outside of config files. Always use `config('app.name')`, not `env('APP_NAME')`.

### Testing
- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

### Vite Error
- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.


=== laravel/v12 rules ===

## Laravel 12

- Use the `search-docs` tool to get version specific documentation.
- Since Laravel 11, Laravel has a new streamlined file structure which this project uses.

### Laravel 12 Structure
- No middleware files in `app/Http/Middleware/`.
- `bootstrap/app.php` is the file to register middleware, exceptions, and routing files.
- `bootstrap/providers.php` contains application specific service providers.
- **No app\Console\Kernel.php** - use `bootstrap/app.php` or `routes/console.php` for console configuration.
- **Commands auto-register** - files in `app/Console/Commands/` are automatically available and do not require manual registration.

### Database
- When modifying a column, the migration must include all of the attributes that were previously defined on the column. Otherwise, they will be dropped and lost.
- Laravel 11 allows limiting eagerly loaded records natively, without external packages: `$query->latest()->limit(10);`.

### Models
- Casts can and likely should be set in a `casts()` method on a model rather than the `$casts` property. Follow existing conventions from other models.


=== pint/core rules ===

## Laravel Pint Code Formatter

- You must run `vendor/bin/pint --dirty` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test`, simply run `vendor/bin/pint` to fix any formatting issues.


=== phpunit/core rules ===

## PHPUnit Core

- This application uses PHPUnit for testing. All tests must be written as PHPUnit classes. Use `php artisan make:test --phpunit {name}` to create a new test.
- If you see a test using "Pest", convert it to PHPUnit.
- Every time a test has been updated, run that singular test.
- When the tests relating to your feature are passing, ask the user if they would like to also run the entire test suite to make sure everything is still passing.
- Tests should test all of the happy paths, failure paths, and weird paths.
- You must not remove any tests or test files from the tests directory without approval. These are not temporary or helper files, these are core to the application.

### Running Tests
- Run the minimal number of tests, using an appropriate filter, before finalizing.
- To run all tests: `php artisan test`.
- To run all tests in a file: `php artisan test tests/Feature/ExampleTest.php`.
- To filter on a particular test name: `php artisan test --filter=testName` (recommended after making a change to a related file).
</laravel-boost-guidelines>


=== cbox documentation rules ===

## Cbox Documentation System

This application automatically imports and displays documentation from GitHub releases. Understanding this system is critical for working with documentation packages.

### Core Philosophy

**Major Version Management**
- Store ONE entry per major version (v1, v2, v3) - not individual releases
- Automatically keep only the latest release within each major version
- When importing version 1.2.1 after 1.2.0, the system updates the existing v1 entry
- URLs use major version: `/docs/{package}/v1`, `/docs/{package}/v2`

**Version Comparison Logic**
- System uses PHP's `version_compare()` to determine if new version is newer
- Only updates if new version is strictly newer than existing
- Skips import if existing version is same or newer
- Example: 1.2.1 > 1.2.0 → updates | 1.2.0 <= 1.2.0 → skips

### Files NOT Used on Website

**README.md - GitHub Only**
- README.md is NEVER displayed on the Cbox website
- README.md is only for GitHub repository display
- If documentation exists in a `/docs` folder, README.md is completely ignored
- Do NOT reference README.md in documentation guides or examples

**Files Used on Website**
- All `.md` files in the `/docs` folder
- All image/asset files in `/docs` directory
- `_index.md` files for directory landing pages (optional but recommended)

### Documentation Directory Structure

**Required Structure**
```
docs/
├── _index.md                    # Optional: Main docs landing page
├── introduction.md              # Recommended: Getting started
├── installation.md              # Recommended: Setup instructions
├── quickstart.md               # Recommended: Quick examples
├── basic-usage/                # Feature directories
│   ├── _index.md              # Optional: Section landing page
│   ├── feature-one.md
│   └── feature-two.md
└── advanced-usage/
    ├── _index.md
    ├── advanced-feature.md
    └── expert-topics.md
```

**Directory Naming Rules**
- Use lowercase with hyphens: `basic-usage/`, `advanced-features/`
- Keep names short but descriptive: `api-reference/`, `platform-support/`
- Avoid deep nesting (max 2-3 levels recommended)

### Metadata Requirements (Frontmatter)

**Required Fields** (extracted automatically)
```yaml
---
title: "Page Title"           # REQUIRED - Used in navigation, page header, SEO
description: "Brief summary"  # REQUIRED - Used in navigation, meta tags, SEO
weight: 99                    # OPTIONAL - Lower numbers appear first (default: 99)
hidden: false                 # OPTIONAL - Set true to hide from navigation
---
```

**How Metadata Is Used**

**Title Field**
- **Navigation Sidebar**: Displays as clickable link text
- **Page Header**: Shown as `<h1>` on the page (if no `# heading` in content)
- **Browser Tab**: Used in `<title>` tag
- **SEO**: Used in meta tags for search engines
- **Breadcrumbs**: Used in navigation breadcrumbs (future feature)

**Description Field**
- **Navigation Tooltip**: May be shown on hover (future feature)
- **Meta Description**: Used in `<meta name="description">` tag
- **Search Results**: Displayed in search engine results
- **Social Sharing**: Used when sharing on social media
- **SEO**: Critical for search ranking and click-through rate

**Weight Field**
- **Navigation Order**: Controls order in sidebar
- **Not visible to users**: Only affects sort order
- **Directory-wide**: Weight applies within current directory only
- **Index pages**: Use weight to position directory sections

**Hidden Field**
- **Navigation**: If `true`, page won't appear in sidebar
- **Direct Access**: Page is still accessible via direct URL
- **Use Cases**: Draft pages, admin docs, deprecated content
- **Default**: `false` - all pages are visible

**Complete Metadata Example**
```yaml
---
title: "CPU Usage Calculation"
description: "Deep dive into calculating CPU usage percentages from raw time counters"
weight: 25
hidden: false
---

# CPU Usage Calculation

This guide explains how to calculate CPU usage percentages...
```

**Title Best Practices**
- Use Title Case: "Getting Started", "API Reference", "Error Handling"
- Keep under 50 characters for optimal display
- Be specific: "CPU Metrics" not just "Metrics"
- Avoid redundancy: Don't repeat package name in every title
- Match content: Title should reflect page purpose
- Unique titles: No duplicate titles in navigation

**Description Best Practices**
- One clear sentence summarizing the page
- 60-160 characters for optimal SEO (120 characters ideal)
- Focus on user value: what they'll learn or accomplish
- Action-oriented: "Get", "Learn", "Understand", "Monitor"
- Avoid generic phrases: "This page describes..."
- Include key terms for SEO

**Good Description Examples**
```yaml
# ✅ Specific and action-oriented
description: "Get raw CPU time counters and per-core metrics from the system"

# ✅ Clear user value
description: "Master the Result<T> pattern for explicit error handling without exceptions"

# ✅ Concise with key terms
description: "Monitor resource usage for individual processes or process groups"

# ❌ Too generic
description: "This page describes CPU metrics and how to use them"

# ❌ Too long (>160 chars)
description: "This comprehensive guide will walk you through everything you need to know about CPU metrics including how to get them, what they mean, and how to interpret the results in various scenarios"

# ❌ No user value
description: "CPU metrics documentation page"
```

**Weight System Deep Dive**
- Default weight: 99 (if not specified)
- Lower numbers appear first: 1, 2, 3... 99
- Same weight = alphabetical sort by title
- Weight scope: Only within current directory
- Recommended ranges:
  - 1-10: Critical pages (introduction, installation, quickstart)
  - 11-30: Common features (basic usage guides)
  - 31-70: Advanced features (complex topics)
  - 71-99: Reference material (API docs, appendices)

**Weight Organization Example**
```yaml
# docs/introduction.md
weight: 1        # First page - overview

# docs/installation.md
weight: 2        # Setup instructions

# docs/quickstart.md
weight: 3        # Quick examples

# docs/basic-usage/cpu-metrics.md
weight: 10       # First basic feature

# docs/basic-usage/memory-metrics.md
weight: 11       # Second basic feature

# docs/advanced-usage/custom-implementations.md
weight: 50       # Advanced topic

# docs/api-reference.md
weight: 90       # Reference material at end
```

**Hidden Pages Use Cases**
```yaml
# Draft content not ready for users
---
title: "Kubernetes Integration"
description: "Deploy metrics collection in Kubernetes clusters"
hidden: true     # Still being written
---

# Deprecated content kept for reference
---
title: "Legacy API v1"
description: "Old API documentation (deprecated)"
hidden: true     # Don't show in navigation
---

# Internal documentation
---
title: "Development Setup"
description: "Local development environment setup for contributors"
hidden: true     # Only for maintainers
---
```

### Index Files (_index.md)

**Purpose**
- Creates landing pages for directory sections
- Provides overview of section contents
- Optional but recommended for better UX

**When to Use _index.md**
- ✅ For major sections with 3+ child pages
- ✅ When you want custom intro text for a section
- ✅ For directories that need explanation (e.g., "Architecture")
- ❌ Not required for simple directories
- ❌ System auto-creates virtual indexes if missing

**Example _index.md**
```markdown
---
title: "Basic Usage"
description: "Essential features for getting started with the package"
weight: 1
---

# Basic Usage

This section covers the fundamental features you'll use daily:

- CPU and memory monitoring
- Disk usage tracking
- Network statistics
- System uptime

Start with the "System Overview" guide for a quick introduction.
```

### Metadata Quality Levels

The system assigns quality levels based on metadata completeness:

**Complete** ✅
- All files have `title` and `description`
- All directories have `_index.md` files
- Consistent weight ordering
- No missing or empty metadata

**Partial** ⚠️
- Most files have metadata but some missing
- Some directories lack `_index.md` (system creates virtual ones)
- Generally functional but could be improved

**Minimal** ⚠️
- Many files missing metadata
- Multiple directories without indexes
- May have auto-generated titles (from filenames)
- Navigation still works but UX is degraded

### Navigation Building Logic

**Hierarchical Tree Structure**
1. System scans `/docs` folder recursively
2. Extracts frontmatter from each `.md` file
3. Groups files by directory
4. Sorts by weight (ascending), then title (alphabetically)
5. Builds nested tree structure for navigation
6. Creates virtual `_index.md` for missing directory indexes

**Path-Based Routing**
- File path determines URL
- `docs/introduction.md` → `/docs/{package}/v1/introduction`
- `docs/basic-usage/cpu-metrics.md` → `/docs/{package}/v1/basic-usage/cpu-metrics`
- Remove `.md` extension from URLs
- Preserve directory structure in URLs

**Current Page Highlighting**
- Navigation component tracks `current_path`
- Compares file path to current URL
- Applies active styles to matching navigation item

### Links and URLs

**Internal Documentation Links**
- Use relative paths to link between documentation pages
- Path is relative to current file location
- Remove `.md` extension from link targets
- System automatically converts to proper URLs

**Link Syntax Examples**
```markdown
# Link to sibling file in same directory
[Installation Guide](installation)

# Link to file in parent directory
[Back to Introduction](../introduction)

# Link to file in subdirectory
[CPU Metrics](basic-usage/cpu-metrics)

# Link to file in different subdirectory
[Platform Comparison](../platform-support/comparison)

# Link with anchor to heading
[Error Handling](advanced-usage/error-handling#result-pattern)
```

**How Link Resolution Works**
1. Markdown: `[CPU Guide](basic-usage/cpu-metrics)`
2. Resolves to file: `docs/basic-usage/cpu-metrics.md`
3. Renders as URL: `/docs/{package}/v1/basic-usage/cpu-metrics`
4. Navigation highlights current page

**External Links**
```markdown
# External links use full URLs
[GitHub Repository](https://github.com/owner/repo)
[Official Website](https://example.com)

# Always include https:// for external links
✅ [Example](https://example.com)
❌ [Example](example.com)
```

**Link Best Practices**
- ✅ Use descriptive link text: `[View API Reference](api-reference)`
- ❌ Avoid generic text: `[Click here](api-reference)` or `[Read more](guide)`
- ✅ Keep paths relative: `[Guide](../guide)`
- ❌ Don't hardcode: `[Guide](/docs/package/v1/guide)`
- ✅ Test all links after import
- ❌ Don't link to README.md (it's not displayed)

### Asset Handling

**Image References**
- Use relative paths in markdown: `![Diagram](../images/architecture.png)`
- Always include alt text for accessibility: `![Architecture Diagram](image.png)`
- System automatically rewrites to absolute URLs during render
- Supports paths relative to current file or document root
- All assets copied to: `public/docs/{package}/v1/`

**Image Syntax Examples**
```markdown
# Image in same directory
![Performance Chart](performance.png)

# Image in subdirectory
![Diagram](images/architecture.png)

# Image in parent images folder
![Logo](../images/logo.svg)

# Image with title tooltip
![Chart](chart.png "CPU Performance Over Time")
```

**Supported Asset Types**
- Images: `.png`, `.jpg`, `.jpeg`, `.gif`, `.svg`, `.webp`
- Can be in any subdirectory within `/docs`
- Common patterns: `/docs/images/`, `/docs/assets/`, `/docs/screenshots/`

**Asset Organization**
```
docs/
├── images/              # Shared images for all docs
│   ├── logo.png
│   └── architecture.svg
├── basic-usage/
│   ├── cpu-chart.png   # Feature-specific image
│   └── cpu-metrics.md
└── screenshots/         # UI screenshots
    └── dashboard.png
```

### Code Blocks

**Syntax Highlighting**
- Supported languages: PHP, JavaScript, Bash, JSON, YAML, XML, HTML, Markdown, SQL, Dockerfile
- Specify language after opening fence: \`\`\`php, \`\`\`bash, \`\`\`json
- Code blocks automatically get copy button
- Custom GitHub Dark theme with Cbox brand colors

**Best Practices**
```markdown
# ✅ Good - Specify language
\`\`\`php
$metrics = SystemMetrics::cpu()->get();
\`\`\`

# ❌ Avoid - No language specified
\`\`\`
$metrics = SystemMetrics::cpu()->get();
\`\`\`
```

### Import Process (Automated)

**How It Works**
1. Command: `php artisan docs:import {owner} {repo}`
2. Fetches all GitHub releases via API
3. For each release:
   - Extracts major version (1.2.3 → v1)
   - Compares with existing entry for that major version
   - Skips if existing is newer or same
   - Updates if new version is newer
4. Clones repository with `git clone --depth 1 --single-branch`
5. Copies docs and assets to storage
6. Scans `/docs` folder and builds navigation structure
7. Creates/updates Statamic entry with metadata

**Storage Locations**
- Git repos: `storage/app/docs/{package}/v1/repo/`
- Public assets: `public/docs/{package}/v1/`
- Statamic entries: `content/collections/documentation/{package}-{major_version}.md`

### Common Patterns

**Standard Documentation Set**
```
docs/
├── introduction.md       # What is this package?
├── installation.md       # How to install
├── quickstart.md        # 5-minute getting started
├── basic-usage/         # Core features
│   ├── _index.md
│   ├── feature-1.md
│   └── feature-2.md
├── advanced-usage/      # Complex scenarios
│   ├── _index.md
│   └── advanced.md
├── api-reference.md     # Complete API docs
└── testing.md          # How to test
```

**Minimal Documentation Set**
```
docs/
├── introduction.md      # Overview
├── installation.md      # Setup
└── quickstart.md       # Examples
```

### Quality Guidelines for LLMs

**When Creating Documentation Structure**

✅ **DO**
- Add frontmatter to every `.md` file
- Use descriptive titles and descriptions
- Create `_index.md` for major sections
- Use weight to control order
- Keep directory nesting shallow (2-3 levels max)
- Use lowercase-with-hyphens for file/folder names
- Specify language for code blocks
- Use relative paths for images

❌ **DON'T**
- Reference README.md in docs (it's not displayed)
- Create deeply nested directories (>3 levels)
- Use spaces or special characters in filenames
- Omit frontmatter metadata
- Use generic titles like "Page 1", "Document"
- Hardcode absolute URLs for assets
- Forget to specify code block languages

**Metadata Quality Checklist**
- [ ] Every `.md` file has `title` and `description`
- [ ] Titles are unique and descriptive
- [ ] Descriptions are 60-160 characters
- [ ] Major sections have `_index.md` files
- [ ] Weight values create logical ordering
- [ ] No hidden files unless intentional
- [ ] File names match content (no generic names)
- [ ] Directory structure is logical and shallow

### Example Documentation Entry

**File**: `docs/basic-usage/cpu-metrics.md`

```markdown
---
title: "CPU Metrics"
description: "Get raw CPU time counters and per-core metrics from the system"
weight: 10
---

# CPU Metrics

Monitor CPU usage and performance with real-time metrics.

## Getting CPU Statistics

\`\`\`php
use Cbox\SystemMetrics\SystemMetrics;

$cpu = SystemMetrics::cpu()->get();

echo "CPU Cores: {$cpu->cores}\n";
echo "User Time: {$cpu->user}ms\n";
echo "System Time: {$cpu->system}ms\n";
\`\`\`

## Per-Core Metrics

\`\`\`php
foreach ($cpu->perCore as $core) {
    echo "Core {$core->id}: {$core->usage}%\n";
}
\`\`\`

## Platform Support

- ✅ Linux: Full support via `/proc/stat`
- ✅ macOS: Full support via `host_processor_info()`

See [Platform Comparison](../platform-support/comparison) for details.
```

### Troubleshooting

**Navigation Not Showing**
- Check frontmatter exists and is valid YAML
- Verify `title` and `description` are present
- Ensure file is `.md` extension
- Check `hidden: true` is not set
- Verify file is in `/docs` folder (not root)

**Images Not Loading**
- Use relative paths: `![](../images/file.png)`
- Verify image exists in repository
- Check file extension is supported
- Ensure image is within `/docs` directory
- Run import again to copy assets

**Wrong Page Order**
- Add `weight` to frontmatter
- Lower numbers appear first (1, 2, 3...)
- Default weight is 99
- Same weight = alphabetical by title

**Code Not Highlighting**
- Specify language: \`\`\`php not just \`\`\`
- Supported: php, js, bash, json, yaml, xml, html, md, sql, dockerfile
- Check spelling of language name
- Ensure code block is properly closed

### SEO and URL Structure

**URL Pattern**
- Base: `/docs/{package}/{major_version}/{page_path}`
- Package: `system-metrics`, `laravel-helpers`
- Major Version: `v1`, `v2`, `v3`
- Page Path: Mirrors file structure without `.md`

**URL Examples**
```
# Root documentation page
File: docs/introduction.md
URL:  /docs/system-metrics/v1/introduction

# Nested in directory
File: docs/basic-usage/cpu-metrics.md
URL:  /docs/system-metrics/v1/basic-usage/cpu-metrics

# Directory index
File: docs/basic-usage/_index.md
URL:  /docs/system-metrics/v1/basic-usage/_index

# Deep nesting
File: docs/advanced/features/custom.md
URL:  /docs/system-metrics/v1/advanced/features/custom
```

**SEO Metadata Generated**
```html
<!-- From frontmatter -->
<title>CPU Metrics - System Metrics v1 - Cbox</title>
<meta name="description" content="Get raw CPU time counters and per-core metrics from the system">

<!-- Auto-generated -->
<meta property="og:title" content="CPU Metrics">
<meta property="og:description" content="Get raw CPU time counters...">
<meta property="og:url" content="https://cbox.com/docs/system-metrics/v1/basic-usage/cpu-metrics">
<meta property="og:type" content="article">

<!-- Canonical URL -->
<link rel="canonical" href="https://cbox.com/docs/system-metrics/v1/basic-usage/cpu-metrics">
```

**SEO Best Practices**
- ✅ Unique title and description for each page
- ✅ Keep URLs short and descriptive
- ✅ Use kebab-case for readability: `cpu-metrics` not `cpumetrics`
- ✅ Include keywords in title and description
- ✅ Descriptive file names that match content
- ❌ Don't stuff keywords
- ❌ Don't use generic titles like "Page 1"
- ❌ Don't create duplicate content

**URL Optimization**
```yaml
# ✅ Good URL structure
File: docs/basic-usage/cpu-metrics.md
URL:  /docs/system-metrics/v1/basic-usage/cpu-metrics
SEO:  Clear, descriptive, hierarchical

# ✅ Good file naming
cpu-metrics.md           # Clear purpose
error-handling.md        # Descriptive
platform-comparison.md   # Specific

# ❌ Poor URL structure
File: docs/stuff/page1.md
URL:  /docs/system-metrics/v1/stuff/page1
SEO:  Unclear, generic, no value

# ❌ Poor file naming
file1.md      # Generic
doc.md        # Vague
metrics.md    # Too broad
```

**Metadata Impact on SEO**

**Title Impact**
- Search engine result headline
- Social media share title
- Browser bookmark name
- Navigation link text

**Description Impact**
- Search result snippet
- Social media preview text
- May influence click-through rate
- Helps search engines understand content

**Weight Impact**
- No direct SEO effect
- Affects user navigation experience
- May indirectly affect engagement metrics

**Hidden Impact**
- Page not in sitemap (if implemented)
- Not linked from navigation
- Still accessible via direct URL
- May be indexed by search engines unless robots meta tag added
