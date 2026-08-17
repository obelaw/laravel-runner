<?php

namespace Obelaw\Runner\Console\Commands;

use Illuminate\Console\Command;
use Obelaw\Runner\Models\RunnerModel;
use Obelaw\Runner\Runner;
use Obelaw\Runner\RunnerPool;

class RunnerListCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'runner:list 
                            {--tag= : Filter by tag}
                            {--type= : Filter by type (once/always)}
                            {--status= : Filter by status (executed/pending)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'List all available runners in a table';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $paths = RunnerPool::getPaths();

        
        if (empty($paths)) {
            $this->error('No runner paths configured.');
            return Command::FAILURE;
        }

        $runners = $this->collectRunners($paths);

        if (empty($runners)) {
            $this->warn('No runners found in the configured paths.');
            $this->newLine();
            $this->line('Configured paths:');
            foreach ($paths as $path) {
                $this->line("  • {$path}");
            }
            return Command::SUCCESS;
        }

        // Apply filters
        $runners = $this->applyFilters($runners);

        if (empty($runners)) {
            $this->warn('No runners match the specified filters.');
            return Command::SUCCESS;
        }

        // Display runners table
        $this->displayRunnersTable($runners);

        return Command::SUCCESS;
    }

    /**
     * Collect all runners from the specified paths.
     *
     * @param array $paths
     * @return array
     */
    private function collectRunners(array $paths): array
    {
        $runners = [];


        foreach ($paths as $path) {
            if (!is_dir($path)) {
                continue;
            }

            $files = glob($path . DIRECTORY_SEPARATOR . '*.php');
            if ($files === false) {
                continue;
            }

            foreach ($files as $file) {
                try {

                    $runner = $this->loadRunner($file);
                    
                    if (!$this->isValidRunner($runner)) {
                        continue;
                    }

                    $runners[] = [
                        'file' => basename($file),
                        'name' => $this->extractNameFromFile(basename($file)),
                        'runner' => $runner,
                        'path' => $path,
                        'executed' => RunnerModel::hasBeenExecuted(basename($file)),
                    ];
                } catch (\Throwable $e) {
                    // Skip invalid runners
                    continue;
                }
            }
        }

        // Sort by filename
        usort($runners, function ($a, $b) {
            return strcmp($a['file'], $b['file']);
        });

        return $runners;
    }

    /**
     * Apply filters to the runners list.
     *
     * @param array $runners
     * @return array
     */
    private function applyFilters(array $runners): array
    {
        $tag = $this->option('tag');
        $type = $this->option('type');
        $status = $this->option('status');

        if ($tag) {
            $runners = array_filter($runners, function ($item) use ($tag) {
                return $item['runner']->tag === $tag;
            });
        }

        if ($type) {
            $runners = array_filter($runners, function ($item) use ($type) {
                $runnerType = $item['runner'] instanceof Runner 
                    ? $item['runner']->getType() 
                    : (property_exists($item['runner'], 'type') ? $item['runner']->type : Runner::TYPE_ONCE);
                return strtolower($runnerType) === strtolower($type);
            });
        }

        if ($status) {
            $runners = array_filter($runners, function ($item) use ($status) {
                if ($status === 'executed') {
                    return $item['executed'];
                } elseif ($status === 'pending') {
                    return !$item['executed'];
                }
                return true;
            });
        }

        return array_values($runners);
    }

    /**
     * Display runners in a table format.
     *
     * @param array $runners
     */
    private function displayRunnersTable(array $runners): void
    {
        $headers = ['File', 'Name', 'Pool', 'Tag', 'Type', 'Priority', 'Status', 'Schedule', 'Depends On', 'Description'];

        $rows = [];

        foreach ($runners as $item) {
            $runner = $item['runner'];
            
            $tag = $runner->tag ?? '-';
            $type = $runner instanceof Runner 
                ? $runner->getType() 
                : (property_exists($runner, 'type') ? $runner->type : Runner::TYPE_ONCE);
            $priority = property_exists($runner, 'priority') ? $runner->priority : 0;
            $description = $runner->description ?? '-';
            $status = $item['executed'] ? '✓ Executed' : '○ Pending';

            // Get schedule
            $schedule = '-';
            if (method_exists($runner, 'getSchedule')) {
                $schedule = $runner->getSchedule() ?? '-';
            } elseif (property_exists($runner, 'schedule')) {
                $schedule = $runner->schedule ?? '-';
            }

            // Get dependencies
            $dependsOn = '-';
            if (method_exists($runner, 'dependencies')) {
                $deps = $runner->dependencies();
                $dependsOn = !empty($deps) ? implode(', ', $deps) : '-';
            } elseif (isset($runner->dependsOn) && is_array($runner->dependsOn) && !empty($runner->dependsOn)) {
                $dependsOn = implode(', ', $runner->dependsOn);
            }

            // Truncate dependencies if too long
            if (strlen($dependsOn) > 25) {
                $dependsOn = substr($dependsOn, 0, 22) . '...';
            }

            // Truncate filename if too long
            $filename = $item['file'];

            // Simplify pool path - show relative to base_path
            $pool = str_replace(base_path() . DIRECTORY_SEPARATOR, '', $item['path']);
            if (strlen($pool) > 25) {
                $pool = '...' . substr($pool, -22);
            }

            // Truncate description if too long
            if (strlen($description) > 25) {
                $description = substr($description, 0, 22) . '...';
            }

            $rows[] = [
                $filename,
                $item['name'],
                $pool,
                $tag,
                $type,
                $priority,
                $status,
                $schedule,
                $dependsOn,
                $description,
            ];
        }

        $this->newLine();
        $this->table($headers, $rows);
        $this->newLine();
        $this->info('Total runners: ' . count($runners));
    }

    /**
     * Load runner instance from file.
     *
     * @param string $file
     * @return mixed
     */
    private function loadRunner(string $file)
    {
        return require $file;
    }

    /**
     * Check if the loaded object is a valid runner.
     *
     * @param mixed $runner
     * @return bool
     */
    private function isValidRunner($runner): bool
    {
        return is_object($runner) 
            && ($runner instanceof Runner || method_exists($runner, 'handle'));
    }

    /**
     * Extract a readable name from the filename.
     *
     * @param string $filename
     * @return string
     */
    private function extractNameFromFile(string $filename): string
    {
        // Remove .php extension
        $name = str_replace('.php', '', $filename);
        
        // Remove timestamp prefix (e.g., 2024_11_01_120000_)
        $name = preg_replace('/^\d{4}_\d{2}_\d{2}_\d{6}_/', '', $name);
        
        // Convert snake_case to Title Case
        $name = str_replace('_', ' ', $name);
        $name = ucwords($name);
        
        return $name;
    }
}