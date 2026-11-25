## Obelaw Runner

Obelaw Runner (`obelaw/laravel-runner`) is a task management and execution system for Laravel that enables creating, organizing, prioritizing, scheduling, and running modular tasks with dependency resolution, tagging, and database execution tracking.

### Core Conventions & Architecture

- **Runners Directory**: Place all runner files in `runners/` at the application root (i.e. `base_path('runners')`). Additional directories can be registered via `RunnerPool::addPath($path)`.
- **File Naming**: Timestamped snake_case format: `YYYY_MM_DD_HHmmss_name.php` (e.g. `2026_01_15_120000_seed_roles.php`).
- **Class Structure**: Each runner file must return an anonymous class extending `Obelaw\Runner\Runner`.
- **Execution Types**:
  - `Runner::TYPE_ONCE` (`'once'`): Executes only once. Tracked in the `runners` table and skipped on future runs unless `--force` is specified.
  - `Runner::TYPE_ALWAYS` (`'always'`): Executes on every invocation (or according to its schedule).
- **Dependencies**: Use `$dependsOn = ['YYYY_MM_DD_HHmmss_file.php']` to declare prerequisites. Dependent runners will only execute after prerequisites complete successfully.
- **Priority**: Lower integer priorities execute first (default: `0`).

### Key Artisan Commands

- `php artisan runner:make` - Interactively generate a new runner file using built-in templates.
- `php artisan runner:run` - Execute pending runners.
  - `php artisan runner:run --tag=billing` - Execute runners filtered by tag.
  - `php artisan runner:run --scheduled` - Run only scheduled runners that are currently due.
  - `php artisan runner:run {name}` - Run a specific runner file.
  - `php artisan runner:run --force` - Force re-execution of all runners including `TYPE_ONCE`.
- `php artisan runner:list` - View all discovered runners and their execution status.
  - Options: `--tag=...`, `--type=once|always`, `--status=executed|pending`.
- `php artisan runner:tree` - Visualize the dependency hierarchy tree across runners.

### Example Usage

@verbatim
<code-snippet name="Standard Task Runner Template" lang="php">
<?php

use Obelaw\Runner\Runner;

return new class extends Runner
{
    /**
     * Tag for filtering and grouping.
     */
    public ?string $tag = 'setup';

    /**
     * Priority: lower numbers execute first.
     */
    public int $priority = 10;

    /**
     * Description of what this runner does.
     */
    public ?string $description = 'Seed default roles and permissions';

    /**
     * Execution type: Runner::TYPE_ONCE or Runner::TYPE_ALWAYS.
     */
    protected string $type = Runner::TYPE_ONCE;

    /**
     * Dependencies that must complete before this runner runs.
     */
    protected array $dependsOn = [];

    /**
     * Storage for output logged to runner_logs table.
     */
    private mixed $outputData = null;

    /**
     * Optional pre-execution hook.
     */
    public function before(): void
    {
        // Pre-run setup
    }

    /**
     * Main task logic.
     */
    public function handle(): void
    {
        // Business logic here...
        $this->outputData = ['status' => 'completed', 'count' => 10];
    }

    /**
     * Optional post-execution hook.
     */
    public function after(): void
    {
        // Post-run cleanup or notifications
    }

    /**
     * Return output data saved into database logs.
     */
    public function output(): mixed
    {
        return $this->outputData;
    }
};
</code-snippet>
@endverbatim

@verbatim
<code-snippet name="Scheduled Recurring Runner" lang="php">
<?php

use Obelaw\Runner\Runner;

return new class extends Runner
{
    public ?string $tag = 'maintenance';
    public ?string $description = 'Purge expired user sessions daily at 2 AM';
    protected string $type = Runner::TYPE_ALWAYS;

    public function __construct()
    {
        // Schedulable helpers: dailyAt(), hourly(), everyMinutes(), weekly(), monthly(), etc.
        $this->dailyAt('02:00');
    }

    public function handle(): void
    {
        // Maintenance logic...
    }
};
</code-snippet>
@endverbatim
