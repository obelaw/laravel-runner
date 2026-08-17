<?php

namespace Obelaw\Runner\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Obelaw\Runner\Runner;
use Obelaw\Runner\RunnerPool;

use function Laravel\Prompts\text;
use function Laravel\Prompts\select;
use function Laravel\Prompts\confirm;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\note;

class RunnerMakeCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'runner:make';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a new runner file';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        // Show available templates
        $useTemplate = confirm(
            label: 'Would you like to use a template?',
            default: false,
            hint: 'Templates provide pre-configured settings for common tasks'
        );

        if ($useTemplate) {
            return $this->handleWithTemplate();
        }

        // Prompt for name
        $name = text(
            label: 'What is the name of the runner?',
            placeholder: 'e.g., CreateCategories, ImportUsers, CleanupLogs',
            required: true,
            validate: fn(string $value) => match (true) {
                strlen($value) < 3 => 'The name must be at least 3 characters.',
                !preg_match('/^[A-Z][a-zA-Z0-9]*$/', $value) => 'Name must start with uppercase letter and contain only letters/numbers.',
                default => null
            }
        );

        // Prompt for tag
        $hasTag = confirm(
            label: 'Do you want to add a tag?',
            default: false
        );

        $tag = null;
        if ($hasTag) {
            $tag = text(
                label: 'What is the tag?',
                placeholder: 'e.g., install, setup, migration',
                required: false
            );
        }

        // Prompt for description
        $hasDescription = confirm(
            label: 'Do you want to add a description?',
            default: false
        );

        $description = null;
        if ($hasDescription) {
            $description = text(
                label: 'Enter a description:',
                placeholder: 'Brief description of what this runner does',
                required: false
            );
        }

        // Prompt for type
        $type = select(
            label: 'What type of runner?',
            options: [
                'once' => 'Once (runs only one time)',
                'always' => 'Always (runs every time)',
            ],
            default: 'once'
        );

        // Prompt for priority
        $hasPriority = confirm(
            label: 'Do you want to set a custom priority?',
            default: false,
            hint: 'Lower numbers run first (default is 0)'
        );

        $priority = 0;
        if ($hasPriority) {
            $priority = (int) text(
                label: 'Enter priority (lower runs first):',
                default: '0',
                validate: fn(string $value) => is_numeric($value) ? null : 'Priority must be a number.'
            );
        }

        // Prompt for schedule
        $hasSchedule = confirm(
            label: 'Do you want to add a schedule (cron expression)?',
            default: false
        );

        $schedule = null;
        if ($hasSchedule) {
            $schedule = text(
                label: 'Enter cron expression:',
                placeholder: 'e.g., * * * * * (every minute), 0 0 * * * (daily at midnight)',
                required: false,
                hint: 'Use Schedulable trait methods like hourly(), daily(), etc. in the runner constructor'
            );
        }

        // Prompt for dependencies
        $hasDependencies = confirm(
            label: 'Does this runner depend on other runners?',
            default: false
        );

        $dependencies = [];
        if ($hasDependencies) {
            $existingRunners = $this->getExistingRunners();
            
            if (empty($existingRunners)) {
                $this->components->warn('No existing runners found. You can add dependencies later.');
            } else {
                $dependencies = multiselect(
                    label: 'Select runners that must complete before this one:',
                    options: $existingRunners,
                    hint: 'Use space to select, enter to confirm'
                );
            }
        }

        // Get the path where runner will be created
        $path = $this->selectRunnerPath();

        if (!$path) {
            $this->error('No runner path configured. Creating default runners directory.');
            $path = base_path('runners');
        }

        // Ensure the directory exists
        if (!is_dir($path)) {
            mkdir($path, 0755, true);
            $this->info("Created directory: {$path}");
        }

        // Generate filename
        $filename = $this->generateFilename($name);
        $filepath = $path . DIRECTORY_SEPARATOR . $filename;

        // Check if file already exists
        if (file_exists($filepath)) {
            $this->error("Runner file already exists: {$filepath}");
            return Command::FAILURE;
        }

        // Generate the runner content
        $content = $this->generateRunnerContent($name, $tag, $description, $priority, $type, $schedule, $dependencies);

        // Write the file
        file_put_contents($filepath, $content);

        $this->newLine();
        $this->components->info('Runner created successfully!');
        $this->newLine();

        $this->components->twoColumnDetail('File', str_replace(base_path() . DIRECTORY_SEPARATOR, '', $filepath));
        $this->components->twoColumnDetail('Name', $name);
        if ($tag) {
            $this->components->twoColumnDetail('Tag', $tag);
        }
        $this->components->twoColumnDetail('Type', $type);
        $this->components->twoColumnDetail('Priority', (string) $priority);
        if ($schedule) {
            $this->components->twoColumnDetail('Schedule', $schedule);
        }
        if (!empty($dependencies)) {
            $this->components->twoColumnDetail('Dependencies', count($dependencies) . ' runner(s)');
        }

        $this->newLine();
        note(
            "Next steps:\n" .
            "  • Implement your logic in the handle() method\n" .
            "  • Use \$this->outputData to store results\n" .
            "  • Test with: php artisan runner:run" . ($tag ? " --tag={$tag}" : "") . "\n" .
            "  • View tree: php artisan runner:tree"
        );

        return Command::SUCCESS;
    }

    /**
     * Handle runner creation with a template.
     *
     * @return int
     */
    protected function handleWithTemplate(): int
    {
        $templates = $this->getTemplates();
        
        $templateKey = select(
            label: 'Choose a template:',
            options: array_map(fn($t) => $t['label'], $templates)
        );

        $template = $templates[$templateKey];

        // Prompt for name
        $name = text(
            label: 'What is the name of the runner?',
            placeholder: $template['placeholder'] ?? 'e.g., MyRunner',
            required: true,
            validate: fn(string $value) => match (true) {
                strlen($value) < 3 => 'The name must be at least 3 characters.',
                !preg_match('/^[A-Z][a-zA-Z0-9]*$/', $value) => 'Name must start with uppercase letter and contain only letters/numbers.',
                default => null
            }
        );

        // Apply template defaults
        $tag = $template['tag'];
        $description = $template['description'] ?? null;
        $type = $template['type'];
        $priority = $template['priority'];
        $schedule = $template['schedule'] ?? null;

        // Allow customization
        $customize = confirm(
            label: 'Would you like to customize the template settings?',
            default: false
        );

        if ($customize) {
            $tag = text(
                label: 'Tag:',
                default: $tag,
                required: false
            );

            $priority = (int) text(
                label: 'Priority (lower runs first):',
                default: (string) $priority,
                validate: fn(string $value) => is_numeric($value) ? null : 'Priority must be a number.'
            );

            $type = select(
                label: 'Type:',
                options: [
                    'once' => 'Once (runs only one time)',
                    'always' => 'Always (runs every time)',
                ],
                default: $type
            );
        }

        // Get dependencies
        $dependencies = [];
        $hasDependencies = confirm(
            label: 'Does this runner depend on other runners?',
            default: false
        );

        if ($hasDependencies) {
            $existingRunners = $this->getExistingRunners();
            if (!empty($existingRunners)) {
                $dependencies = multiselect(
                    label: 'Select dependencies:',
                    options: $existingRunners,
                    hint: 'Use space to select, enter to confirm'
                );
            }
        }

        // Get path and create runner
        $path = $this->selectRunnerPath();
        if (!$path) {
            $path = base_path('runners');
        }

        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }

        $filename = $this->generateFilename($name);
        $filepath = $path . DIRECTORY_SEPARATOR . $filename;

        if (file_exists($filepath)) {
            $this->error("Runner file already exists: {$filepath}");
            return Command::FAILURE;
        }

        $content = $this->generateRunnerContent($name, $tag, $description, $priority, $type, $schedule, $dependencies);
        file_put_contents($filepath, $content);

        $this->newLine();
        $this->components->info('Runner created from template successfully!');
        $this->newLine();

        $this->components->twoColumnDetail('Template', $template['label']);
        $this->components->twoColumnDetail('File', str_replace(base_path() . DIRECTORY_SEPARATOR, '', $filepath));
        $this->components->twoColumnDetail('Name', $name);
        $this->components->twoColumnDetail('Tag', $tag);
        $this->components->twoColumnDetail('Type', $type);
        $this->components->twoColumnDetail('Priority', (string) $priority);

        if (!empty($dependencies)) {
            $this->components->twoColumnDetail('Dependencies', count($dependencies) . ' runner(s)');
        }

        $this->newLine();
        note(
            "Template applied: {$template['label']}\n" .
            "  • Implement your logic in the handle() method\n" .
            "  • Use \$this->outputData to store results\n" .
            "  • Test with: php artisan runner:run --tag={$tag}"
        );

        return Command::SUCCESS;
    }

    /**
     * Get available runner templates.
     *
     * @return array
     */
    protected function getTemplates(): array
    {
        return [
            'migration' => [
                'label' => 'Database Migration Runner',
                'placeholder' => 'e.g., CreateCategoriesTable, AddIndexToUsers',
                'tag' => 'migration',
                'description' => 'Database schema modification',
                'type' => 'once',
                'priority' => 0,
            ],
            'seeder' => [
                'label' => 'Data Seeder Runner',
                'placeholder' => 'e.g., SeedCategories, ImportInitialData',
                'tag' => 'seeder',
                'description' => 'Seed initial data',
                'type' => 'once',
                'priority' => 10,
            ],
            'import' => [
                'label' => 'Data Import Runner',
                'placeholder' => 'e.g., ImportUsers, SyncProducts',
                'tag' => 'import',
                'description' => 'Import data from external source',
                'type' => 'always',
                'priority' => 0,
            ],
            'cleanup' => [
                'label' => 'Cleanup Runner',
                'placeholder' => 'e.g., CleanupOldLogs, PurgeExpiredData',
                'tag' => 'cleanup',
                'description' => 'Clean up old or expired data',
                'type' => 'always',
                'priority' => 100,
            ],
            'processing' => [
                'label' => 'Data Processing Runner',
                'placeholder' => 'e.g., GenerateReports, CalculateStatistics',
                'tag' => 'processing',
                'description' => 'Process and transform data',
                'type' => 'always',
                'priority' => 50,
            ],
            'maintenance' => [
                'label' => 'Maintenance Runner',
                'placeholder' => 'e.g., OptimizeDatabase, ClearCache',
                'tag' => 'maintenance',
                'description' => 'System maintenance task',
                'type' => 'always',
                'priority' => 0,
                'schedule' => '0 0 * * *', // Daily at midnight
            ],
        ];
    }

    /**
     * Get list of existing runners.
     *
     * @return array
     */
    protected function getExistingRunners(): array
    {
        $runners = [];
        $paths = RunnerPool::getPaths();

        foreach ($paths as $path) {
            if (!is_dir($path)) {
                continue;
            }

            $files = glob($path . DIRECTORY_SEPARATOR . '*.php');
            if (!$files) {
                continue;
            }

            foreach ($files as $file) {
                $basename = basename($file);
                $runnerInstance = $this->loadRunnerForPreview($file);
                
                $label = $basename;
                if ($runnerInstance) {
                    $name = $this->extractRunnerName($basename);
                    $tag = $runnerInstance->tag ? "[{$runnerInstance->tag}]" : '';
                    $desc = $runnerInstance->description ? " - {$runnerInstance->description}" : '';
                    $label = "{$name} {$tag}{$desc}";
                }
                
                $runners[$basename] = $label;
            }
        }

        return $runners;
    }

    /**
     * Load a runner instance for preview without executing it.
     *
     * @param string $file
     * @return Runner|null
     */
    protected function loadRunnerForPreview(string $file): ?Runner
    {
        try {
            $runner = require $file;
            return ($runner instanceof Runner) ? $runner : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Extract a readable name from the runner filename.
     *
     * @param string $filename
     * @return string
     */
    protected function extractRunnerName(string $filename): string
    {
        // Remove .php extension
        $name = str_replace('.php', '', $filename);
        // Remove timestamp (pattern: YYYY_MM_DD_HHMMSS_)
        $name = preg_replace('/^\d{4}_\d{2}_\d{2}_\d{6}_/', '', $name);
        // Convert to title case
        return Str::title(str_replace('_', ' ', $name));
    }

    /**
     * Select the runner path.
     *
     * @return string|null
     */
    protected function selectRunnerPath(): ?string
    {
        $paths = RunnerPool::getPaths();

        if (empty($paths)) {
            return base_path('runners');
        }

        // If only one path, use it
        if (count($paths) === 1) {
            return $paths[0];
        }

        // If multiple paths, ask user to choose
        $choices = [];
        foreach ($paths as $path) {
            $relativePath = str_replace(base_path() . DIRECTORY_SEPARATOR, '', $path);
            $choices[$path] = $relativePath;
        }

        return select(
            label: 'Multiple runner paths found. Choose one:',
            options: $choices,
            default: array_key_first($choices)
        );
    }

    /**
     * Generate the filename for the runner.
     *
     * @param string $name
     * @return string
     */
    protected function generateFilename(string $name): string
    {
        $timestamp = date('Y_m_d_His');
        $slug = Str::snake($name);

        return "{$timestamp}_{$slug}.php";
    }

    /**
     * Generate the runner file content.
     *
     * @param string $name
     * @param string|null $tag
     * @param string|null $description
     * @param int $priority
     * @param string $type
     * @param string|null $schedule
     * @param array $dependencies
     * @return string
     */
    protected function generateRunnerContent(
        string $name,
        ?string $tag,
        ?string $description,
        int $priority,
        string $type,
        ?string $schedule,
        array $dependencies = []
    ): string {
        $tagLine = $tag ? "    public ?string \$tag = '{$tag}';" : "    public ?string \$tag = null;";
        $descLine = $description ? "    public ?string \$description = '{$description}';" : "    public ?string \$description = null;";
        $typeLine = "    protected string \$type = Runner::TYPE_" . strtoupper($type) . ";";
        $scheduleLine = $schedule ? "    protected ?string \$schedule = '{$schedule}';" : "    protected ?string \$schedule = null;";
        $dependsOnLine = $this->buildDependsOnLine($dependencies);

        return <<<PHP
<?php

use Obelaw\Runner\Runner;

return new class extends Runner
{
    /**
     * The tag of the runner for filtering execution.
     */
{$tagLine}

    /**
     * The priority of the runner (lower numbers run first).
     */
    public int \$priority = {$priority};

    /**
     * The description of what this runner does.
     */
{$descLine}

    /**
     * The type of runner execution: 'once' or 'always'.
     */
{$typeLine}

    /**
     * The cron expression for scheduling.
     * @var string|null
     */
{$scheduleLine}

    /**
     * Runner filenames that must execute successfully before this runner runs.
     */
{$dependsOnLine}

    /**
     * Stores the output result of this runner.
     *
     * @var mixed
     */
    private \$outputData = null;

    /**
     * Execute the runner logic.
     *
     * @return void
     */
    public function handle(): void
    {
        // TODO: Implement your runner logic here
        
        // Example: Store result data that can be retrieved later
        // \$this->outputData = ['status' => 'success', 'count' => 100];
    }

    /**
     * Hook that runs before the main handle method.
     *
     * @return void
     */
    public function before(): void
    {
        // Optional: Add pre-execution logic
    }

    /**
     * Hook that runs after the main handle method.
     *
     * @return void
     */
    public function after(): void
    {
        // Optional: Add post-execution logic
    }

    /**
     * Get the output data from this runner.
     * This can be used to retrieve results after execution.
     *
     * @return mixed
     */
    public function output(): mixed
    {
        return \$this->outputData;
    }
};

PHP;
    }

    /**
     * Build the $dependsOn property declaration for the runner stub.
     *
     * @param array $dependencies
     * @return string
     */
    protected function buildDependsOnLine(array $dependencies): string
    {
        if (empty($dependencies)) {
            return "    protected array \$dependsOn = [];";
        }

        $normalized = array_map(
            fn($dep) => "'" . addslashes(str_replace('.php', '', $dep) . '.php') . "'",
            $dependencies
        );

        return "    protected array \$dependsOn = [" . implode(', ', $normalized) . "];";
    }
}