<?php

namespace Obelaw\Runner\Console\Commands;

use Illuminate\Console\Command;
use Obelaw\Runner\RunnerPool;
use Obelaw\Runner\Services\RunnerService;

class RunnerCommand extends Command
{
    /**
     * Signature with rich filtering & execution controls.
     */
    protected $signature = 'runner:run 
                            {name? : Specific runner name to execute}
                            {--tag= : Filter runners by tag}
                            {--force : Force re-execution of all runners (including TYPE_ONCE)}
                            {--scheduled : Run only runners with defined schedules}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Execute runners from configured pools';

    public function handle(): void
    {
        $name = $this->argument('name');
        $tag = $this->option('tag');
        $force = $this->option('force');
        $scheduled = $this->option('scheduled');
        
        $runnerService = new RunnerService(RunnerPool::getPaths());
        
        if ($force) {
            $runnerService->force(true);
            $this->warn("Force mode: Re-executing all runners including TYPE_ONCE");
        }

        if ($scheduled) {
            $runnerService->scheduledOnly(true);
        }

        // Run specific runner by name
        if ($name) {
            $message = $scheduled ? "Running specific scheduled runner: {$name}" : "Running specific runner: {$name}";
            $this->info($message);
            
            try {
                $summary = $runnerService->runByName($name);
                $this->displaySummary($summary, $tag, $scheduled);
            } catch (\Exception $e) {
                $this->error("Error: {$e->getMessage()}");
                return;
            }
            
            return;
        }

        // Run all runners or by tag
        if ($scheduled && $tag) {
            $this->info("Running scheduled runners with tag: {$tag}");
        } elseif ($scheduled) {
            $this->info("Running all scheduled runners...");
        } elseif ($tag) {
            $this->info("Running runners with tag: {$tag}");
        } else {
            $this->info("Running all runners...");
        }

        try {
            $summary = $runnerService->run($tag);
        } catch (\Exception $e) {
            $this->error("Error: {$e->getMessage()}");
            return;
        }

        $this->displaySummary($summary, $tag, $scheduled);
    }

    /**
     * Display execution summary.
     *
     * @param array $summary
     * @param string|null $tag
     * @param bool $scheduled
     */
    private function displaySummary(array $summary, ?string $tag = null, bool $scheduled = false): void
    {
        $this->newLine();
        
        if ($summary['executed_count'] === 0 && $summary['skipped_count'] === 0) {
            if ($scheduled && $tag) {
                $this->warn("No scheduled runners found with tag: {$tag}");
            } elseif ($scheduled) {
                $this->warn("No scheduled runners found");
            } elseif ($tag) {
                $this->warn("No runners found with tag: {$tag}");
            } else {
                $this->warn("No runners were executed");
            }
            return;
        }
        
        if ($summary['success']) {
            $this->info("✓ Successfully executed {$summary['executed_count']} runner(s)");
        } else {
            $this->error("✗ Executed {$summary['executed_count']} runner(s) with {$summary['error_count']} error(s)");
        }

        if ($summary['skipped_count'] > 0) {
            $reason = $scheduled ? "(not scheduled or TYPE_ONCE already executed)" : "(TYPE_ONCE already executed)";
            $this->warn("⊘ Skipped {$summary['skipped_count']} runner(s) {$reason}");
        }

        if (($summary['blocked_count'] ?? 0) > 0) {
            $this->warn("⊘ Blocked {$summary['blocked_count']} runner(s) due to unsatisfied dependencies");
        }

        if (!empty($summary['executed_files'])) {
            $this->newLine();
            $this->line('Executed runners:');
            foreach ($summary['executed_files'] as $file) {
                $this->line("  ✓ {$file}");
            }
        }

        if (!empty($summary['skipped_files'])) {
            $this->newLine();
            $this->line('Skipped runners:');
            foreach ($summary['skipped_files'] as $file) {
                $this->line("  ⊘ {$file}");
            }
        }

        if (!empty($summary['blocked_files'])) {
            $this->newLine();
            $this->line('Blocked runners (unsatisfied dependencies):');
            foreach ($summary['blocked_files'] as $file) {
                $this->line("  ⊘ {$file}");
            }
        }

        if (!empty($summary['errors'])) {
            $this->newLine();
            $this->error('Errors encountered:');
            foreach ($summary['errors'] as $error) {
                $this->error("  ✗ " . basename($error['file']) . ": {$error['error']}");
            }
        }
    }
}
