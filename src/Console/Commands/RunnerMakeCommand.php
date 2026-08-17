<?php

namespace Obelaw\Runner\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Obelaw\Runner\RunnerPool;

use function Laravel\Prompts\text;
use function Laravel\Prompts\select;
use function Laravel\Prompts\confirm;

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
        // Prompt for name
        $name = text(
            label: 'What is the name of the runner?',
            placeholder: 'e.g., CreateCategories',
            required: true,
            validate: fn(string $value) => match (true) {
                strlen($value) < 3 => 'The name must be at least 3 characters.',
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
            $dependenciesInput = text(
                label: 'Enter dependency runner names (comma-separated):',
                placeholder: 'e.g., 2024_11_01_120000_create_categories, 2024_11_01_130000_create_products',
                required: false,
                hint: 'Filenames with or without the .php extension'
            );

            if ($dependenciesInput) {
                $dependencies = array_values(array_filter(array_map('trim', explode(',', $dependenciesInput))));
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
            $this->components->twoColumnDetail('Dependencies', implode(', ', $dependencies));
        }

        return Command::SUCCESS;
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
     * Execute the runner logic.
     *
     * @return void
     */
    public function handle(): void
    {
        // TODO: Implement your runner logic here
        \$this->info('Runner "{$name}" executed successfully!');
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
     * Helper method to output info messages.
     *
     * @param string \$message
     * @return void
     */
    private function info(string \$message): void
    {
        echo "[INFO] " . \$message . PHP_EOL;
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