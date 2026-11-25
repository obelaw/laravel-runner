---
name: runner-development
description: Create, configure, manage, schedule, and execute task runners using the obelaw/laravel-runner package. Use this skill when generating runner files, configuring priorities, tags, dependencies, or schedules, running runner Artisan commands, or structuring background/scheduled tasks with lifecycle hooks and execution tracking.
---

# Runner Development Guide (`obelaw/laravel-runner`)

The `obelaw/laravel-runner` package provides a structured, priority-driven, taggable task execution system for Laravel applications. It supports one-time execution (`once`), recurring scheduled execution (`always`), dependency graphs, lifecycle hooks (`before`, `handle`, `after`), output capture, and persistent database audit logs.

---

## When to Use This Skill

Activate this skill when:
- Creating a new task runner file in a Laravel project.
- Setting up one-off data migrations, imports, or seeders that must run only once.
- Scheduling recurring tasks with cron-like schedules without cluttering `routes/console.php`.
- Defining task dependencies where specific runners must succeed before others run.
- Grouping runners by tags (e.g., `setup`, `sync`, `maintenance`, `billing`).
- Executing or debugging runners using Artisan CLI commands (`runner:run`, `runner:list`, `runner:tree`, `runner:make`).
- Registering custom runner pools from domain modules or packages using `RunnerPool::addPath()`.

---

## Core Architecture & Conventions

### Directory Location & File Naming
- **Default Path**: `runners/` in the Laravel project root (i.e. `base_path('runners')`).
- **File Naming Convention**: `YYYY_MM_DD_HHmmss_snake_case_name.php`
  - Example: `2026_01_15_120000_seed_initial_permissions.php`
  - Example: `2026_02_01_000000_sync_inventory_catalog.php`
- **Return Type**: Each runner file **MUST** return an anonymous class extending `Obelaw\Runner\Runner`.

### Database Tables
The package uses two database tables (provided by package migrations):
1. `runners`: Tracks execution status of runners (`name`, `tag`, `description`, `priority`, `type`, `executed_at`).
2. `runner_logs`: Records every run attempt with status (`success`, `failed`, `blocked`), output payload, error messages, execution time, and timestamps.

---

## Runner Anatomy & Properties

Every runner extends `Obelaw\Runner\Runner` and can configure the following properties:

```php
<?php

use Obelaw\Runner\Runner;

return new class extends Runner
{
    /**
     * Tag used for grouping and filtering (--tag=setup).
     */
    public ?string $tag = 'setup';

    /**
     * Execution priority. Lower numbers run first (default: 0).
     */
    public int $priority = 10;

    /**
     * Human-readable description displayed in runner:list and logs.
     */
    public ?string $description = 'Sync product pricing from external ERP';

    /**
     * Execution type:
     * - Runner::TYPE_ONCE ('once'): Runs only one time, recorded in database.
     * - Runner::TYPE_ALWAYS ('always'): Runs every time runner:run is invoked (or when due by schedule).
     */
    protected string $type = Runner::TYPE_ONCE;

    /**
     * Cron expression for scheduling (e.g., '0 * * * *' for hourly).
     * Schedulable trait helper methods can also set this.
     */
    protected ?string $schedule = null;

    /**
     * Array of runner filenames that MUST complete successfully before this runner runs.
     * Suffix '.php' is optional.
     */
    protected array $dependsOn = [
        '2026_01_15_120000_seed_initial_permissions.php',
    ];

    /**
     * Storage for output data captured during handle().
     */
    private mixed $outputData = null;

    /**
     * Optional pre-execution hook.
     */
    public function before(): void
    {
        // Preparation, resource locking, or precondition checks
    }

    /**
     * Main runner logic. Required.
     */
    public function handle(): void
    {
        // Core business logic
        $this->outputData = ['processed' => 150];
    }

    /**
     * Optional post-execution hook.
     */
    public function after(): void
    {
        // Cleanup, cache busting, or notification dispatch
    }

    /**
     * Return output data to be saved into runner_logs.
     */
    public function output(): mixed
    {
        return $this->outputData;
    }
};
```

---

## Scheduling Runners (`Schedulable` Trait)

Runners use the `Obelaw\Runner\Traits\Schedulable` trait. You can set the schedule either via the `$schedule` property or fluently in the runner's constructor.

### Available Schedule Helpers

| Method | Cron Expression | Description |
|---|---|---|
| `$this->everyMinute()` | `* * * * *` | Every minute |
| `$this->everyMinutes(15)` | `*/15 * * * *` | Every 15 minutes |
| `$this->hourly()` | `0 * * * *` | Once per hour at minute 0 |
| `$this->everyHours(6)` | `0 */6 * * *` | Every 6 hours |
| `$this->daily()` | `0 0 * * *` | Daily at midnight |
| `$this->dailyAt('04:30')` | `30 4 * * *` | Daily at 04:30 AM |
| `$this->weekly()` | `0 0 * * 0` | Weekly on Sunday at midnight |
| `$this->weeklyOn(1)` | `0 0 * * 1` | Weekly on Monday (0=Sun, 6=Sat) |
| `$this->monthly()` | `0 0 1 * *` | 1st of every month at midnight |
| `$this->monthlyOn(15)` | `0 0 15 * *` | 15th of every month at midnight |
| `$this->cron('0 9 * * 1-5')` | Custom | Custom cron syntax |

### Example: Scheduled Runner
```php
<?php

use Obelaw\Runner\Runner;

return new class extends Runner
{
    public ?string $tag = 'maintenance';
    public ?string $description = 'Purge expired user sessions daily at 2 AM';
    protected string $type = Runner::TYPE_ALWAYS;

    public function __construct()
    {
        $this->dailyAt('02:00');
    }

    public function handle(): void
    {
        $deleted = DB::table('sessions')->where('last_activity', '<', now()->subDays(30)->timestamp)->delete();
        $this->outputData = ['purged_sessions' => $deleted];
    }

    private mixed $outputData = null;

    public function output(): mixed
    {
        return $this->outputData;
    }
};
```

---

## Dependency Management & Ordering

### Defining Dependencies
Specify prerequisite runners in the `$dependsOn` array property:

```php
protected array $dependsOn = [
    '2026_01_10_000000_create_tenants.php',
    '2026_01_10_010000_seed_plans.php',
];
```

### Dependency Rules
1. **Topological Sorting**: `RunnerService` sorts all discovered runners topologically. Dependencies always run before dependent runners.
2. **Priority Tie-Breaking**: Within the same dependency tier, runners execute in ascending `$priority` order (lower numbers first).
3. **Failure Isolation**: If any prerequisite runner fails or is not found, dependent runners are marked as `blocked` with status `'blocked'` in `runner_logs` and skipped.
4. **Once-Runners as Dependencies**: If a dependency is of type `Runner::TYPE_ONCE` and has already been executed in a previous session, `RunnerService` detects its prior execution in the `runners` table and considers the dependency satisfied.
5. **Cycle Detection**: Circular dependencies (e.g. A depends on B, B depends on A) throw an `Exception` during dependency resolution.

---

## Artisan CLI Reference

### 1. Execute Runners (`runner:run`)
```bash
# Execute all pending runners in all configured pools
php artisan runner:run

# Execute only runners with a specific tag
php artisan runner:run --tag=setup

# Execute only scheduled runners that are due
php artisan runner:run --scheduled

# Execute scheduled runners matching a specific tag
php artisan runner:run --scheduled --tag=maintenance

# Execute a single runner by exact filename
php artisan runner:run 2026_01_15_120000_seed_initial_permissions.php

# Force re-execution of all runners, ignoring TYPE_ONCE restrictions
php artisan runner:run --force
```

### 2. List Runners (`runner:list`)
Displays a formatted table of all discovered runners, their type, tag, priority, schedule, and execution status.
```bash
# List all runners
php artisan runner:list

# Filter by tag
php artisan runner:list --tag=import

# Filter by execution type
php artisan runner:list --type=once
php artisan runner:list --type=always

# Filter by status
php artisan runner:list --status=executed
php artisan runner:list --status=pending
```

### 3. Visualize Dependency Tree (`runner:tree`)
Shows a hierarchical tree representation of all runners and their dependencies based on `$dependsOn`.
```bash
# Show complete dependency tree
php artisan runner:tree

# Filter tree by tag or type
php artisan runner:tree --tag=setup
php artisan runner:tree --type=once
```

### 4. Create a New Runner (`runner:make`)
Interactive wizard that creates a new runner file in your configured runner path.
```bash
php artisan runner:make
```
Supports pre-configured templates:
- `migration`: Database schema modification (`once`, priority 0)
- `seeder`: Seed initial data (`once`, priority 10)
- `import`: Import data from external source (`always`, priority 0)
- `cleanup`: Clean up expired data (`always`, priority 100)
- `processing`: Transform and process data (`always`, priority 50)
- `maintenance`: System maintenance task (`always`, priority 0, scheduled daily)

---

## Registering Custom Runner Pools

By default, the package looks for runners in `base_path('runners')`. To load runners from additional directories (such as modular domain paths or third-party packages), use `RunnerPool::addPath()`:

```php
namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Obelaw\Runner\RunnerPool;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Add additional runner directory
        RunnerPool::addPath(base_path('modules/Billing/runners'));
        RunnerPool::addPath(base_path('modules/Inventory/runners'));
    }
}
```

---

## Real-World Implementation Recipes

### Recipe 1: Initial Database Seeder (One-Time Execution)
Use for setup scripts, default role creation, or system initialization:

```php
<?php

use App\Models\Role;
use Illuminate\Support\Facades\DB;
use Obelaw\Runner\Runner;

return new class extends Runner
{
    public ?string $tag = 'install';
    public int $priority = 5;
    public ?string $description = 'Seed default system roles and administrator permissions';
    protected string $type = Runner::TYPE_ONCE;

    private mixed $outputData = null;

    public function handle(): void
    {
        DB::transaction(function () {
            $admin = Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Administrator']);
            $user = Role::firstOrCreate(['slug' => 'user'], ['name' => 'Regular User']);

            $this->outputData = [
                'roles_created' => [$admin->slug, $user->slug],
            ];
        });
    }

    public function output(): mixed
    {
        return $this->outputData;
    }
};
```

### Recipe 2: Recurring External API Sync with Lifecycle Hooks
Use for fetching third-party catalogs, currency exchange rates, or webhooks sync:

```php
<?php

use App\Services\StripeService;
use Illuminate\Support\Facades\Log;
use Obelaw\Runner\Runner;

return new class extends Runner
{
    public ?string $tag = 'billing';
    public int $priority = 20;
    public ?string $description = 'Sync Stripe exchange rates and plan tiers';
    protected string $type = Runner::TYPE_ALWAYS;

    private mixed $outputData = null;

    public function __construct()
    {
        // Run every 6 hours
        $this->everyHours(6);
    }

    public function before(): void
    {
        Log::info('Stripe sync runner initiated.');
    }

    public function handle(): void
    {
        $stripe = app(StripeService::class);
        $count = $stripe->syncExchangeRates();

        $this->outputData = [
            'status' => 'success',
            'rates_updated' => $count,
            'timestamp' => now()->toIso8601String(),
        ];
    }

    public function after(): void
    {
        Log::info('Stripe sync runner completed.', (array) $this->outputData);
    }

    public function output(): mixed
    {
        return $this->outputData;
    }
};
```

### Recipe 3: Multi-Step Dependent Data Pipeline
Use when step 2 requires step 1 to have succeeded:

**Step 1: Download/Import Archive**
```php
// runners/2026_01_01_100000_import_legacy_customers.php
<?php

use Obelaw\Runner\Runner;

return new class extends Runner
{
    public ?string $tag = 'migration';
    public int $priority = 1;
    public ?string $description = 'Import legacy customer records from CSV';
    protected string $type = Runner::TYPE_ONCE;

    public function handle(): void
    {
        // Import logic...
    }
};
```

**Step 2: Generate Wallets (Depends on Step 1)**
```php
// runners/2026_01_01_100100_initialize_customer_wallets.php
<?php

use Obelaw\Runner\Runner;

return new class extends Runner
{
    public ?string $tag = 'migration';
    public int $priority = 2;
    public ?string $description = 'Create ledger accounts for imported legacy customers';
    protected string $type = Runner::TYPE_ONCE;
    protected array $dependsOn = [
        '2026_01_01_100000_import_legacy_customers.php',
    ];

    public function handle(): void
    {
        // Wallet initialization logic...
    }
};
```

---

## Best Practices & Guidelines

1. **Keep Runners Idempotent**: Especially for `TYPE_ALWAYS` runners, always make sure tasks can run multiple times without duplicating state or records (e.g. use `updateOrCreate`, `firstOrCreate`, or database transactions).
2. **Always Store Output in `$outputData`**: Set `$this->outputData = [...]` inside `handle()` and return it from `output()`. This ensures rich diagnostic data is recorded in `runner_logs`.
3. **Use Explicit Tags**: Assign meaningful tags (e.g. `setup`, `billing`, `inventory`, `audit`) so teams can execute specific functional slices using `--tag=...`.
4. **Schedule in Console Kernel/Cron**: In production, schedule the runner command in Laravel's scheduler (`routes/console.php`):
   ```php
   use Illuminate\Support\Facades\Schedule;

   Schedule::command('runner:run --scheduled')->everyMinute();
   ```
5. **Inspect Execution Logs**: Use the `Obelaw\Runner\Models\RunnerLog` model or query database table `runner_logs` to diagnose errors or monitor runner runtimes.
