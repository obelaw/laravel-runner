<?php

namespace Obelaw\Runner\Console\Commands;

use Illuminate\Console\Command;
use Obelaw\Runner\Runner;
use Obelaw\Runner\RunnerPool;

class RunnerTreeCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'runner:tree
                            {--tag= : Filter by tag}
                            {--type= : Filter by type (once/always)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Show all runners as a dependency tree using dependsOn';

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

        $nodes = $this->collectRunners($paths);

        if (empty($nodes)) {
            $this->warn('No runners found in the configured paths.');
            return Command::SUCCESS;
        }

        $nodes = $this->applyFilters($nodes);

        if (empty($nodes)) {
            $this->warn('No runners match the specified filters.');
            return Command::SUCCESS;
        }

        $dependents = $this->buildDependentsMap($nodes);
        $roots = $this->findRoots($nodes);
        
        // Display header
        $this->displayHeader($nodes, $roots);

        $visited = [];
        $executionOrder = 1;
        $rootCount = count($roots);

        foreach ($roots as $index => $root) {
            $isLastRoot = $index === $rootCount - 1;
            $this->renderNode($root, $nodes, $dependents, $visited, '', $isLastRoot, $executionOrder);
        }

        $unvisited = array_values(array_filter(array_keys($nodes), fn(string $name) => !isset($visited[$name])));
        sort($unvisited);

        if (!empty($unvisited)) {
            $this->newLine();
            $this->components->warn('Runners with circular dependencies or disconnected from main tree:');
            $this->newLine();

            $count = count($unvisited);
            foreach ($unvisited as $index => $name) {
                $isLast = $index === $count - 1;
                $prefix = $isLast ? '└── ' : '├── ';

                $meta = $this->formatRunnerMeta($nodes[$name]['runner']);
                $deps = $this->formatDependencyList($nodes[$name]['dependencies']);
                
                $this->line("<fg=yellow>{$prefix}{$name}</> {$meta}");
                if (!empty($deps)) {
                    $depPrefix = $isLast ? '    ' : '│   ';
                    $this->line("<fg=gray>{$depPrefix}↳ depends on: {$deps}</>");
                }
            }
        }

        $this->displaySummary($nodes, $roots, $unvisited);

        return Command::SUCCESS;
    }

    /**
     * Collect all runners from configured paths.
     *
     * @param array $paths
     * @return array<string, array{runner: object, dependencies: array<int, string>}>
     */
    private function collectRunners(array $paths): array
    {
        $nodes = [];

        foreach ($paths as $path) {
            if (!is_dir($path)) {
                continue;
            }

            $files = glob($path . DIRECTORY_SEPARATOR . '*.php');
            if ($files === false) {
                continue;
            }

            foreach ($files as $file) {
                $filename = basename($file);

                try {
                    $runner = require $file;

                    if (!is_object($runner) || !($runner instanceof Runner || method_exists($runner, 'handle'))) {
                        continue;
                    }

                    $nodes[$filename] = [
                        'runner' => $runner,
                        'dependencies' => $this->normalizeDependencies($runner),
                    ];
                } catch (\Throwable $e) {
                    continue;
                }
            }
        }

        ksort($nodes);

        return $nodes;
    }

    /**
     * Apply CLI filters.
     *
     * @param array $nodes
     * @return array
     */
    private function applyFilters(array $nodes): array
    {
        $tag = $this->option('tag');
        $type = $this->option('type');

        if ($tag) {
            $nodes = array_filter($nodes, function (array $item) use ($tag) {
                $runnerTag = property_exists($item['runner'], 'tag') ? $item['runner']->tag : null;
                return $runnerTag === $tag;
            });
        }

        if ($type) {
            $type = strtolower($type);
            $nodes = array_filter($nodes, function (array $item) use ($type) {
                $runner = $item['runner'];

                if ($runner instanceof Runner) {
                    return strtolower($runner->getType()) === $type;
                }

                if (method_exists($runner, 'getType')) {
                    return strtolower((string) $runner->getType()) === $type;
                }

                if (property_exists($runner, 'type')) {
                    return strtolower((string) $runner->type) === $type;
                }

                return $type === Runner::TYPE_ONCE;
            });
        }

        return $nodes;
    }

    /**
     * Build map of dependency => dependent files.
     *
     * @param array $nodes
     * @return array<string, array<int, string>>
     */
    private function buildDependentsMap(array $nodes): array
    {
        $dependents = [];

        foreach (array_keys($nodes) as $name) {
            $dependents[$name] = [];
        }

        foreach ($nodes as $name => $item) {
            foreach ($item['dependencies'] as $dep) {
                if (!isset($nodes[$dep])) {
                    continue;
                }

                $dependents[$dep][] = $name;
            }
        }

        foreach ($dependents as &$children) {
            sort($children);
        }

        return $dependents;
    }

    /**
     * Find root runners (runners with no local dependencies).
     *
     * @param array $nodes
     * @return array<int, string>
     */
    private function findRoots(array $nodes): array
    {
        $inDegree = array_fill_keys(array_keys($nodes), 0);

        foreach ($nodes as $name => $item) {
            foreach ($item['dependencies'] as $dep) {
                if (!isset($nodes[$dep])) {
                    continue;
                }

                $inDegree[$name]++;
            }
        }

        $roots = array_values(array_filter(array_keys($nodes), fn(string $name) => $inDegree[$name] === 0));
        sort($roots);

        return $roots;
    }

    /**
     * Render a node and all of its dependents recursively.
     *
     * @param string $name
     * @param array $nodes
     * @param array $dependents
     * @param array $visited
     * @param string $indent
     * @param bool $isLast
     * @param int &$executionOrder
     * @return void
     */
    private function renderNode(string $name, array $nodes, array $dependents, array &$visited, string $indent, bool $isLast, int &$executionOrder): void
    {
        $branch = $isLast ? '└── ' : '├── ';
        $meta = $this->formatRunnerMeta($nodes[$name]['runner']);
        
        // Show execution order number
        $order = str_pad("#{$executionOrder}", 4, ' ', STR_PAD_LEFT);
        $executionOrder++;

        $this->line("<fg=cyan>{$indent}{$branch}</><fg=bright-white>{$name}</> {$meta} <fg=gray>{$order}</>");

        if (isset($visited[$name])) {
            $childIndent = $indent . ($isLast ? '    ' : '│   ');
            $this->line("<fg=gray>{$childIndent}↻ (already shown above)</>");
            return;
        }

        $visited[$name] = true;

        $childIndent = $indent . ($isLast ? '    ' : '│   ');

        // Show dependencies of current runner
        if (!empty($nodes[$name]['dependencies'])) {
            $deps = $this->formatDependencyList($nodes[$name]['dependencies']);
            $this->line("<fg=gray>{$childIndent}↳ requires: {$deps}</>");
        }

        $missing = array_values(array_filter(
            $nodes[$name]['dependencies'],
            fn(string $dep) => !isset($nodes[$dep])
        ));
        sort($missing);

        $children = $dependents[$name] ?? [];
        $items = [];

        foreach ($missing as $dep) {
            $items[] = ['type' => 'missing', 'name' => $dep];
        }

        foreach ($children as $child) {
            $items[] = ['type' => 'runner', 'name' => $child];
        }

        $count = count($items);
        foreach ($items as $index => $item) {
            $itemIsLast = $index === $count - 1;

            if ($item['type'] === 'missing') {
                $leafBranch = $itemIsLast ? '└── ' : '├── ';
                $this->line("<fg=red>{$childIndent}{$leafBranch}[MISSING] {$item['name']}</>");
                continue;
            }

            $this->renderNode($item['name'], $nodes, $dependents, $visited, $childIndent, $itemIsLast, $executionOrder);
        }
    }

    /**
     * Normalize runner dependencies to .php filenames.
     *
     * @param object $runner
     * @return array<int, string>
     */
    private function normalizeDependencies(object $runner): array
    {
        $dependencies = [];

        if (method_exists($runner, 'dependencies')) {
            try {
                $dependencies = (array) $runner->dependencies();
            } catch (\Throwable $e) {
                $dependencies = [];
            }
        } elseif (isset($runner->dependsOn) && is_array($runner->dependsOn)) {
            $dependencies = $runner->dependsOn;
        }

        $normalized = array_map(
            fn($dep) => str_replace('.php', '', (string) $dep) . '.php',
            $dependencies
        );

        $normalized = array_values(array_unique($normalized));
        sort($normalized);

        return $normalized;
    }

    /**
     * Build a compact metadata label for each runner.
     *
     * @param object $runner
     * @return string
     */
    private function formatRunnerMeta(object $runner): string
    {
        $type = Runner::TYPE_ONCE;
        $priority = 0;
        $tag = null;

        if ($runner instanceof Runner) {
            $type = $runner->getType();
            $priority = $runner->getPriority();
            $tag = $runner->getTag();
        } else {
            if (method_exists($runner, 'getType')) {
                $type = (string) $runner->getType();
            } elseif (property_exists($runner, 'type')) {
                $type = (string) $runner->type;
            }

            if (method_exists($runner, 'getPriority')) {
                $priority = (int) $runner->getPriority();
            } elseif (property_exists($runner, 'priority') && is_numeric($runner->priority)) {
                $priority = (int) $runner->priority;
            }

            if (property_exists($runner, 'tag')) {
                $tag = $runner->tag;
            }
        }

        $typeColor = $type === Runner::TYPE_ALWAYS ? 'magenta' : 'green';
        $typeLabel = $type === Runner::TYPE_ALWAYS ? '∞' : '1';
        
        $parts = ["<fg={$typeColor}>{$typeLabel}</>"];
        
        if ($priority !== 0) {
            $parts[] = "<fg=blue>P{$priority}</>";
        }
        
        if ($tag !== null && $tag !== '') {
            $parts[] = "<fg=yellow>#{$tag}</>";
        }

        return '<fg=gray>[</>' . implode(' ', $parts) . '<fg=gray>]</>';
    }

    /**
     * Format a list of dependencies for display.
     *
     * @param array $dependencies
     * @return string
     */
    private function formatDependencyList(array $dependencies): string
    {
        if (empty($dependencies)) {
            return '';
        }

        return implode(', ', array_map(fn($dep) => "<fg=cyan>{$dep}</>", $dependencies));
    }

    /**
     * Display header with summary information.
     *
     * @param array $nodes
     * @param array $roots
     * @return void
     */
    private function displayHeader(array $nodes, array $roots): void
    {
        $this->newLine();
        $this->components->info('Runner Dependency Tree');
        $this->newLine();
        
        $this->line('<fg=gray>═══════════════════════════════════════════════════════════════════════════</>');
        $this->newLine();
        
        $this->line('<fg=bright-white>Legend:</> <fg=green>1</> = once  <fg=magenta>∞</> = always  <fg=blue>P#</> = priority  <fg=yellow>#tag</> = tag  <fg=gray>#N</> = execution order');
        $this->newLine();
        
        $this->components->twoColumnDetail('Total runners', (string) count($nodes));
        $this->components->twoColumnDetail('Root runners', (string) count($roots) . ' (no dependencies)');
        
        $this->newLine();
        $this->line('<fg=gray>───────────────────────────────────────────────────────────────────────────</>');
        $this->newLine();
    }

    /**
     * Display summary statistics.
     *
     * @param array $nodes
     * @param array $roots
     * @param array $unvisited
     * @return void
     */
    private function displaySummary(array $nodes, array $roots, array $unvisited): void
    {
        $this->newLine();
        $this->line('<fg=gray>═══════════════════════════════════════════════════════════════════════════</>');
        $this->newLine();
        
        // Count statistics
        $typeStats = ['once' => 0, 'always' => 0];
        $tagStats = [];
        $totalDeps = 0;
        $missingDeps = [];
        
        foreach ($nodes as $name => $item) {
            $runner = $item['runner'];
            $type = Runner::TYPE_ONCE;
            
            if ($runner instanceof Runner) {
                $type = $runner->getType();
                $tag = $runner->getTag();
            } else {
                if (method_exists($runner, 'getType')) {
                    $type = (string) $runner->getType();
                } elseif (property_exists($runner, 'type')) {
                    $type = (string) $runner->type;
                }
                
                $tag = property_exists($runner, 'tag') ? $runner->tag : null;
            }
            
            $typeStats[$type] = ($typeStats[$type] ?? 0) + 1;
            
            if ($tag) {
                $tagStats[$tag] = ($tagStats[$tag] ?? 0) + 1;
            }
            
            $totalDeps += count($item['dependencies']);
            
            foreach ($item['dependencies'] as $dep) {
                if (!isset($nodes[$dep])) {
                    $missingDeps[$dep] = true;
                }
            }
        }
        
        $this->components->info('Summary Statistics');
        $this->newLine();
        
        $this->components->twoColumnDetail('Total runners', (string) count($nodes));
        $this->components->twoColumnDetail('  - Type ONCE', (string) ($typeStats['once'] ?? 0));
        $this->components->twoColumnDetail('  - Type ALWAYS', (string) ($typeStats['always'] ?? 0));
        
        if (!empty($tagStats)) {
            $this->newLine();
            $this->line('<fg=bright-white>Tags:</>');
            foreach ($tagStats as $tag => $count) {
                $this->components->twoColumnDetail("  - {$tag}", (string) $count);
            }
        }
        
        $this->newLine();
        $this->components->twoColumnDetail('Root runners', (string) count($roots));
        $this->components->twoColumnDetail('Total dependencies', (string) $totalDeps);
        $this->components->twoColumnDetail('Missing dependencies', (string) count($missingDeps));
        
        if (!empty($unvisited)) {
            $this->components->twoColumnDetail('Cyclic/disconnected', (string) count($unvisited));
        }
        
        if (!empty($missingDeps)) {
            $this->newLine();
            $this->components->warn('Missing Dependencies:');
            foreach (array_keys($missingDeps) as $dep) {
                $this->line("  <fg=red>✗</> {$dep}");
            }
        }
        
        $this->newLine();
    }
}