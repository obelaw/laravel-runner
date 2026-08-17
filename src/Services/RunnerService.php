<?php

namespace Obelaw\Runner\Services;

use Exception;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Obelaw\Runner\Models\RunnerModel;
use Obelaw\Runner\Models\RunnerLog;
use Obelaw\Runner\Runner;
use Throwable;

class RunnerService
{
    private array $runnerPools;
    private array $executedFiles = [];
    private array $skippedFiles = [];
    private array $errors = [];
    private bool $trackExecutions = true;
    private bool $force = false;
    private bool $scheduledOnly = false;

    /**
     * Cache of loaded runner instances keyed by basename($file), so each
     * runner file is required exactly once per RunnerService lifecycle.
     *
     * @var array
     */
    private array $loadedRunners = [];

    /**
     * Names of runners that failed during the current run.
     *
     * @var array
     */
    private array $failedNames = [];

    /**
     * Names of runners blocked this run due to unsatisfied dependencies.
     *
     * @var array
     */
    private array $unsatisfiedNames = [];

    /**
     * Names of runners skipped this run because they were already executed
     * (TYPE_ONCE). Used to satisfy dependents of those runners.
     *
     * @var array
     */
    private array $skippedAsExecutedNames = [];

    public function __construct(array $runnerPools)
    {
        $this->validateRunnerPools($runnerPools);
        $this->runnerPools = $runnerPools;
    }

    /**
     * Enable or disable execution tracking.
     *
     * @param bool $track
     * @return $this
     */
    public function trackExecutions(bool $track = true): self
    {
        $this->trackExecutions = $track;
        return $this;
    }

    /**
     * Force execution of all runners.
     *
     * @param bool $force
     * @return $this
     */
    public function force(bool $force = true): self
    {
        $this->force = $force;
        return $this;
    }

    /**
     * Run only scheduled runners.
     *
     * @param bool $scheduledOnly
     * @return $this
     */
    public function scheduledOnly(bool $scheduledOnly = true): self
    {
        $this->scheduledOnly = $scheduledOnly;
        return $this;
    }

    /**
     * Run all runners from the configured pools.
     *
     * @param string|null $tag Filter runners by tag
     * @return array Summary of execution results
     */
    public function run(?string $tag = null): array
    {
        $this->reset();
        
        $runnersFiles = $this->collectRunnerFiles();
        
        if (empty($runnersFiles)) {
            Log::info('No runner files found in the specified paths.');
            return $this->getExecutionSummary();
        }

        // Filter scheduled runners if flag is set
        if ($this->scheduledOnly) {
            $runnersFiles = $this->filterScheduledRunners($runnersFiles);
            
            if (empty($runnersFiles)) {
                Log::info('No scheduled runner files found.');
                return $this->getExecutionSummary();
            }
        }

        // Sort runners by dependency order (priority, then filename, breaks ties)
        $runnersFiles = $this->sortByDependencies($runnersFiles);

        Log::info('Starting runner execution', [
            'total_files' => count($runnersFiles),
            'tag_filter' => $tag,
            'force' => $this->force,
            'scheduled_only' => $this->scheduledOnly
        ]);

        foreach ($runnersFiles as $file) {
            $this->executeRunner($file, $tag);
        }

        $summary = $this->getExecutionSummary();
        Log::info('Runner execution completed', $summary);

        return $summary;
    }

    /**
     * Run a specific runner by name.
     *
     * @param string $runnerName The filename of the runner (e.g., '2024_11_01_120000_create_categories.php')
     * @return array Summary of execution results
     * @throws Exception If runner file not found
     */
    public function runByName(string $runnerName): array
    {
        $this->reset();

        // Find the runner file across all pools
        $runnerFile = $this->findRunnerByName($runnerName);

        if (!$runnerFile) {
            throw new Exception("Runner file not found: {$runnerName}");
        }

        // If scheduledOnly is set, check if runner has a schedule
        if ($this->scheduledOnly) {
            $runner = $this->loadRunner($runnerFile);
            if (!$this->hasSchedule($runner)) {
                Log::info("Runner '{$runnerName}' does not have a schedule defined. Skipping.");
                return $this->getExecutionSummary();
            }
        }

        Log::info("Running specific runner: {$runnerName}");

        // Execute the runner
        $this->executeRunner($runnerFile);

        $summary = $this->getExecutionSummary();
        Log::info('Runner execution completed', $summary);

        return $summary;
    }

    /**
     * Filter runner files to only include those with schedules.
     *
     * @param array $runnersFiles
     * @return array
     */
    private function filterScheduledRunners(array $runnersFiles): array
    {
        $scheduled = [];

        foreach ($runnersFiles as $file) {
            try {
                $runner = $this->loadRunner($file);
                
                if ($this->hasSchedule($runner)) {
                    $scheduled[] = $file;
                    Log::debug("Found scheduled runner: " . basename($file));
                }
            } catch (Throwable $e) {
                Log::warning("Failed to load runner for schedule check: " . basename($file), [
                    'error' => $e->getMessage()
                ]);
                continue;
            }
        }

        Log::info("Filtered scheduled runners", [
            'total' => count($runnersFiles),
            'scheduled' => count($scheduled)
        ]);

        return $scheduled;
    }

    /**
     * Check if a runner has a schedule defined.
     *
     * @param mixed $runner
     * @return bool
     */
    private function hasSchedule($runner): bool
    {
        if (!is_object($runner)) {
            return false;
        }

        if ($runner instanceof Runner) {
            return $runner->getSchedule() !== null;
        }

        if (method_exists($runner, 'getSchedule')) {
            try {
                return $runner->getSchedule() !== null;
            } catch (Throwable $e) {
                return false;
            }
        }

        return false;
    }

    /**
     * Find a runner file by name across all pools.
     *
     * @param string $runnerName
     * @return string|null Full path to the runner file, or null if not found
     */
    private function findRunnerByName(string $runnerName): ?string
    {
        // Normalize the runner name (remove .php if present, add it back)
        $runnerName = str_replace('.php', '', $runnerName) . '.php';

        foreach ($this->runnerPools as $path) {
            if (!is_dir($path)) {
                continue;
            }

            $filePath = $path . DIRECTORY_SEPARATOR . $runnerName;
            
            if (file_exists($filePath)) {
                return $filePath;
            }
        }

        return null;
    }

    /**
     * Check if a runner exists by name.
     *
     * @param string $runnerName
     * @return bool
     */
    public function runnerExists(string $runnerName): bool
    {
        return $this->findRunnerByName($runnerName) !== null;
    }

    /**
     * Validate runner pools configuration.
     *
     * @param array $runnerPools
     * @throws InvalidArgumentException
     */
    private function validateRunnerPools(array $runnerPools): void
    {
        if (empty($runnerPools)) {
            throw new InvalidArgumentException('Runner pools cannot be empty.');
        }

        foreach ($runnerPools as $path) {
            if (!is_string($path)) {
                throw new InvalidArgumentException('All runner pool paths must be strings.');
            }

            if (!is_dir($path)) {
                Log::warning("Runner pool path does not exist: {$path}");
            }
        }
    }

    /**
     * Collect all PHP files from runner pools.
     *
     * @return array
     */
    private function collectRunnerFiles(): array
    {
        $runnersFiles = [];

        foreach ($this->runnerPools as $path) {
            try {
                if (!is_dir($path)) {
                    Log::warning("Skipping non-existent path: {$path}");
                    continue;
                }

                $files = glob($path . '/*.php');
                if ($files === false) {
                    Log::warning("Failed to read files from path: {$path}");
                    continue;
                }
                
                $runnersFiles = array_merge($runnersFiles, $files);
                Log::debug("Found " . count($files) . " runner files in: {$path}");
                
            } catch (Exception $e) {
                Log::error("Error reading runner pool path: {$path}", [
                    'error' => $e->getMessage()
                ]);
            }
        }

        return array_unique($runnersFiles);
    }

    /**
     * Sort runners by filename across all pools.
     *
     * @deprecated Use sortByDependencies() instead, which also honors priority.
     * @param array $runnersFiles
     * @return array
     */
    private function sortRunnersByName(array $runnersFiles): array
    {
        usort($runnersFiles, function ($a, $b) {
            return strcmp(basename($a), basename($b));
        });

        Log::debug('Sorted runners by filename', [
            'first' => !empty($runnersFiles) ? basename($runnersFiles[0]) : null,
            'last' => !empty($runnersFiles) ? basename(end($runnersFiles)) : null,
        ]);

        return $runnersFiles;
    }

    /**
     * Sort runners based on their declared dependencies using Kahn's
     * topological sort algorithm. Within each set of runners that become
     * ready at the same time, ties are broken by priority (ascending) then
     * filename (ascending).
     *
     * @param array $runnersFiles
     * @return array
     * @throws Exception If a self-dependency, missing dependency, or cycle is found.
     */
    private function sortByDependencies(array $runnersFiles): array
    {
        $fileByName = [];
        $priorityByName = [];
        $dependenciesByName = [];

        foreach ($runnersFiles as $file) {
            $name = basename($file);
            $fileByName[$name] = $file;

            try {
                $runner = $this->loadRunner($file);
            } catch (Throwable $e) {
                Log::warning("Failed to load runner while building dependency graph: {$name}", [
                    'error' => $e->getMessage()
                ]);
                unset($fileByName[$name]);
                continue;
            }

            $priorityByName[$name] = $this->getRunnerPriority($runner);
            $dependenciesByName[$name] = $this->normalizeDependencies($runner);
        }

        $nodes = array_keys($fileByName);
        $inDegree = array_fill_keys($nodes, 0);
        $dependents = array_fill_keys($nodes, []);

        foreach ($dependenciesByName as $name => $deps) {
            foreach ($deps as $dep) {
                if ($dep === $name) {
                    throw new Exception("Runner '{$name}' cannot depend on itself.");
                }

                if (!isset($fileByName[$dep])) {
                    if ($this->trackExecutions && RunnerModel::hasBeenExecuted($dep)) {
                        Log::debug("Dependency '{$dep}' for runner '{$name}' was already executed historically. Skipping edge.");
                        continue;
                    }

                    throw new Exception("Runner '{$name}' depends on '{$dep}', which was not found among the loaded runners and has not been previously executed.");
                }

                $inDegree[$name]++;
                $dependents[$dep][] = $name;
            }
        }

        $ready = array_values(array_filter($nodes, fn($name) => $inDegree[$name] === 0));
        $sorted = [];

        while (!empty($ready)) {
            usort($ready, function ($a, $b) use ($priorityByName) {
                $priorityCompare = $priorityByName[$a] <=> $priorityByName[$b];
                return $priorityCompare !== 0 ? $priorityCompare : strcmp($a, $b);
            });

            $current = array_shift($ready);
            $sorted[] = $current;

            foreach ($dependents[$current] as $dependent) {
                $inDegree[$dependent]--;
                if ($inDegree[$dependent] === 0) {
                    $ready[] = $dependent;
                }
            }
        }

        if (count($sorted) !== count($nodes)) {
            $remaining = array_values(array_filter($nodes, fn($name) => $inDegree[$name] > 0));
            throw new Exception('Circular dependency detected among runners: ' . implode(', ', $remaining));
        }

        Log::debug('Sorted runners by dependencies', ['order' => $sorted]);

        return array_map(fn($name) => $fileByName[$name], $sorted);
    }

    /**
     * Execute a single runner file.
     *
     * @param string $file
     * @param string|null $tag
     */
    private function executeRunner(string $file, ?string $tag = null): void
    {
        $runnerName = basename($file);
        $runnerLog = null;
        $obLevel = ob_get_level();

        try {
            if (!file_exists($file) || !is_readable($file)) {
                throw new Exception("File is not readable: {$file}");
            }

            $runner = $this->loadRunner($file);

            // Check dependencies before any other consideration, so a blocked
            // runner never partially executes.
            if (!$this->dependenciesSatisfied($runner, $runnerName)) {
                return;
            }

            // Check if runner should be skipped based on type
            if (!$this->force && $this->trackExecutions && $this->shouldSkipBasedOnType($runnerName, $runner)) {
                return;
            }

            // Check if runner should run (skip schedule check if force mode is enabled)
            if ($runner instanceof Runner && !$this->force && !$runner->shouldRun()) {
                Log::debug("Skipping runner due to shouldRun() condition", [
                    'file' => $runnerName,
                    'schedule' => $runner->getSchedule()
                ]);
                $this->skippedFiles[] = $file;
                return;
            }

            // Log if force mode is bypassing schedule
            if ($this->force && $runner instanceof Runner && $runner->getSchedule()) {
                Log::debug("Force mode: Bypassing schedule check for runner: {$runnerName}");
            }

            // Check tag filter if specified
            if ($tag !== null && !$this->matchesTag($runner, $tag)) {
                Log::debug("Skipping runner due to tag filter", [
                    'file' => $runnerName,
                    'required_tag' => $tag,
                    'runner_tag' => $runner->tag ?? 'none'
                ]);
                $this->skippedFiles[] = $file;
                return;
            }

            // Start logging execution
            if ($this->trackExecutions) {
                $runnerLog = RunnerLog::logStart($runnerName, [
                    'tag' => $runner instanceof Runner ? $runner->getTag() : ($runner->tag ?? null),
                    'type' => $runner instanceof Runner ? $runner->getType() : Runner::TYPE_ONCE,
                ]);
            }

            Log::info("Executing runner: {$runnerName}", [
                'tag' => $runner->tag ?? 'none',
                'type' => $runner instanceof Runner ? $runner->getType() : 'unknown',
                'forced' => $this->force
            ]);

            // Capture output
            ob_start();

            // Execute before hook if available
            if ($runner instanceof Runner || method_exists($runner, 'before')) {
                Log::debug("Executing before hook: {$runnerName}");
                $runner->before();
            }

            // Execute main handle method
            $runner->handle();

            // Execute after hook if available
            if ($runner instanceof Runner || method_exists($runner, 'after')) {
                Log::debug("Executing after hook: {$runnerName}");
                $runner->after();
            }

            // Get captured output
            $output = ob_get_clean();

            // Mark log as completed
            if ($runnerLog) {
                $runnerLog->markCompleted($output);
            }

            // Track execution
            if ($this->trackExecutions) {
                $this->trackExecution($runnerName, $runner);
            }

            $this->executedFiles[] = $file;

            Log::info("Successfully executed runner: {$runnerName}");

        } catch (Throwable $e) {
            // Unwind any output buffers started during this runner's execution,
            // but never touch buffers that existed before we started.
            while (ob_get_level() > $obLevel) {
                ob_end_clean();
            }

            // Mark log as failed
            if ($runnerLog) {
                $runnerLog->markFailed($e->getMessage() . ' at line ' . $e->getLine());
            }

            // Track failure so dependents are cascade-blocked.
            $this->failedNames[] = $runnerName;

            $error = [
                'file' => $file,
                'error' => $e->getMessage(),
                'line' => $e->getLine()
            ];

            $this->errors[] = $error;

            Log::error("Failed to execute runner: {$runnerName}", $error);
        }
    }

    /**
     * Determine if runner should be skipped based on type.
     *
     * @param string $runnerName
     * @param object $runner
     * @return bool
     */
    private function shouldSkipBasedOnType(string $runnerName, object $runner): bool
    {
        if (!RunnerModel::hasBeenExecuted($runnerName)) {
            return false;
        }

        // If runner is TYPE_ONCE, skip if already executed
        if ($runner instanceof Runner && $runner->isTypeOnce()) {
            Log::debug("Skipping TYPE_ONCE runner that was already executed: {$runnerName}");
            $this->skippedFiles[] = $runnerName;
            $this->skippedAsExecutedNames[] = $runnerName;
            return true;
        }

        // TYPE_ALWAYS runners are never skipped (except with force flag logic)
        if ($runner instanceof Runner && $runner->isTypeAlways()) {
            Log::debug("Re-executing TYPE_ALWAYS runner: {$runnerName}");
            return false;
        }

        // For non-Runner objects, duck-type via the public getType() method only.
        if (method_exists($runner, 'getType')) {
            try {
                $type = $runner->getType();
            } catch (Throwable $e) {
                Log::warning("Failed to determine runner type: {$runnerName}", ['error' => $e->getMessage()]);
                $type = Runner::TYPE_ONCE;
            }

            if ($type === Runner::TYPE_ONCE) {
                Log::debug("Skipping TYPE_ONCE runner that was already executed: {$runnerName}");
                $this->skippedFiles[] = $runnerName;
                $this->skippedAsExecutedNames[] = $runnerName;
                return true;
            }
        } else {
            // Default behavior: skip if already executed and no type information available
            Log::debug("Skipping already executed runner (default TYPE_ONCE): {$runnerName}");
            $this->skippedFiles[] = $runnerName;
            $this->skippedAsExecutedNames[] = $runnerName;
            return true;
        }

        return false;
    }

    /**
     * Determine whether all of a runner's dependencies have been satisfied
     * for this run. A dependency blocks execution if it failed or was
     * itself cascade-blocked during this run. A dependency is satisfied if
     * it executed this run, was skipped this run because it was already
     * executed, or was historically executed according to execution tracking.
     *
     * @param object $runner
     * @param string $name
     * @return bool
     */
    private function dependenciesSatisfied(object $runner, string $name): bool
    {
        $executedNames = array_map('basename', $this->executedFiles);

        foreach ($this->normalizeDependencies($runner) as $dep) {
            $isBlocked = in_array($dep, $this->failedNames, true) || in_array($dep, $this->unsatisfiedNames, true);

            $isSatisfied = !$isBlocked && (
                in_array($dep, $executedNames, true)
                || in_array($dep, $this->skippedAsExecutedNames, true)
                || ($this->trackExecutions && RunnerModel::hasBeenExecuted($dep))
            );

            if (!$isSatisfied) {
                $this->unsatisfiedNames[] = $name;
                $this->skippedFiles[] = $name;

                Log::warning("Skipping runner due to unsatisfied dependency", [
                    'runner' => $name,
                    'dependency' => $dep,
                    'reason' => $isBlocked ? 'dependency failed or was blocked' : 'dependency has not run',
                ]);

                return false;
            }
        }

        return true;
    }

    /**
     * Normalize a runner's declared dependencies into filenames with a
     * trailing .php extension, accepted with or without the suffix.
     *
     * @param object $runner
     * @return array
     */
    private function normalizeDependencies(object $runner): array
    {
        $dependencies = [];

        if (method_exists($runner, 'dependencies')) {
            try {
                $dependencies = (array) $runner->dependencies();
            } catch (Throwable $e) {
                $dependencies = [];
            }
        } elseif (isset($runner->dependsOn) && is_array($runner->dependsOn)) {
            $dependencies = $runner->dependsOn;
        }

        return array_values(array_unique(array_map(
            fn($dep) => str_replace('.php', '', (string) $dep) . '.php',
            $dependencies
        )));
    }

    /**
     * Resolve a runner's priority for dependency tiebreaking.
     *
     * @param object $runner
     * @return int
     */
    private function getRunnerPriority(object $runner): int
    {
        if (method_exists($runner, 'getPriority')) {
            try {
                return (int) $runner->getPriority();
            } catch (Throwable $e) {
                return 0;
            }
        }

        if (isset($runner->priority) && is_numeric($runner->priority)) {
            return (int) $runner->priority;
        }

        return 0;
    }

    /**
     * Track runner execution in database.
     *
     * @param string $name
     * @param object $runner
     */
    private function trackExecution(string $name, object $runner): void
    {
        try {
            $data = [];

            if ($runner instanceof Runner) {
                $data = [
                    'tag' => $runner->getTag(),
                    'description' => $runner->getDescription(),
                    'priority' => $runner->getPriority(),
                    'type' => $runner->getType(),
                ];
            } elseif (property_exists($runner, 'tag')) {
                $data['tag'] = $runner->tag;
                if (property_exists($runner, 'type')) {
                    $data['type'] = $runner->type;
                }
            }

            RunnerModel::markAsExecuted($name, $data);
            
            Log::debug("Tracked execution for runner: {$name}", ['type' => $data['type'] ?? 'once']);
        } catch (Throwable $e) {
            Log::warning("Failed to track runner execution: {$name}", [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Load a runner instance from file, caching the instance by
     * basename($file) so each runner file is required exactly once and the
     * same instance is reused across schedule checks, dependency
     * resolution, and execution.
     *
     * @param string $file
     * @return object
     * @throws Exception
     */
    private function loadRunner(string $file): object
    {
        $key = basename($file);

        if (isset($this->loadedRunners[$key])) {
            return $this->loadedRunners[$key];
        }

        try {
            $runner = require $file;
        } catch (Throwable $e) {
            throw new Exception("Failed to load runner file: {$key}. Error: " . $e->getMessage());
        }

        if (!$this->isValidRunner($runner)) {
            throw new Exception("Invalid runner object in file: {$key}");
        }

        if (method_exists($runner, 'setRunnerName')) {
            $runner->setRunnerName($key);
        }

        $this->loadedRunners[$key] = $runner;

        return $runner;
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
     * Check if runner matches the specified tag.
     *
     * @param object $runner
     * @param string $tag
     * @return bool
     */
    private function matchesTag(object $runner, string $tag): bool
    {
        if (!property_exists($runner, 'tag')) {
            return false;
        }

        return $runner->tag === $tag;
    }

    /**
     * Reset execution state.
     */
    private function reset(): void
    {
        $this->executedFiles = [];
        $this->skippedFiles = [];
        $this->errors = [];
        $this->loadedRunners = [];
        $this->failedNames = [];
        $this->unsatisfiedNames = [];
        $this->skippedAsExecutedNames = [];
    }

    /**
     * Get execution summary.
     *
     * @return array
     */
    private function getExecutionSummary(): array
    {
        return [
            'executed_count' => count($this->executedFiles),
            'skipped_count' => count($this->skippedFiles),
            'error_count' => count($this->errors),
            'blocked_count' => count($this->unsatisfiedNames),
            'executed_files' => array_map('basename', $this->executedFiles),
            'skipped_files' => array_map('basename', $this->skippedFiles),
            'blocked_files' => array_map('basename', $this->unsatisfiedNames),
            'errors' => $this->errors,
            'success' => empty($this->errors)
        ];
    }

    /**
     * Get list of executed files.
     *
     * @return array
     */
    public function getExecutedFiles(): array
    {
        return $this->executedFiles;
    }

    /**
     * Get list of skipped files.
     *
     * @return array
     */
    public function getSkippedFiles(): array
    {
        return $this->skippedFiles;
    }

    /**
     * Get list of errors that occurred during execution.
     *
     * @return array
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Check if execution was successful (no errors).
     *
     * @return bool
     */
    public function wasSuccessful(): bool
    {
        return empty($this->errors);
    }

    /**
     * Get runner pools configuration.
     *
     * @return array
     */
    public function getRunnerPools(): array
    {
        return $this->runnerPools;
    }
}
