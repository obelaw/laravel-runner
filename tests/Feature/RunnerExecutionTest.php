<?php

declare(strict_types=1);

use Obelaw\Runner\Models\RunnerLog;
use Obelaw\Runner\Models\RunnerModel;
use Obelaw\Runner\RunnerPool;
use Obelaw\Runner\Services\RunnerService;

it('executes runners in topological dependency order with priority tiebreaking and encodes array output', function (): void {
    $GLOBALS['runner_trace'] = [];

    $this->writeRunner('01_second.php', <<<'PHP'
<?php
use Obelaw\Runner\Runner;

return new class extends Runner {
    public ?string $tag = 'setup';
    public int $priority = 1;
    protected array $dependsOn = ['02_first'];

    public function handle(): void
    {
        $GLOBALS['runner_trace'][] = 'second';
    }

    public function output(): mixed
    {
        return ['status' => 'ok', 'items' => 3];
    }
};
PHP);

    $this->writeRunner('02_first.php', <<<'PHP'
<?php
use Obelaw\Runner\Runner;

return new class extends Runner {
    public ?string $tag = 'setup';
    public int $priority = 10;

    public function handle(): void
    {
        $GLOBALS['runner_trace'][] = 'first';
    }
};
PHP);

    $service = new RunnerService(RunnerPool::getPaths());
    $summary = $service->run();

    expect($summary['success'])->toBeTrue();
    expect($summary['executed_count'])->toBe(2);
    expect($GLOBALS['runner_trace'])->toBe(['first', 'second']);

    $log = RunnerLog::where('runner_name', '01_second.php')->first();
    expect($log)->not->toBeNull();
    expect($log->status)->toBe('completed');
    expect(json_decode((string) $log->output, true))->toBe(['status' => 'ok', 'items' => 3]);

    // Second run skips TYPE_ONCE runners automatically
    $secondRun = $service->run();
    expect($secondRun['executed_count'])->toBe(0);
    expect($secondRun['skipped_count'])->toBe(2);
});

it('re-executes TYPE_ALWAYS runners and honors force mode on TYPE_ONCE runners', function (): void {
    $GLOBALS['always_counter'] = 0;
    $GLOBALS['once_counter'] = 0;

    $this->writeRunner('once_task.php', <<<'PHP'
<?php
use Obelaw\Runner\Runner;

return new class extends Runner {
    protected string $type = Runner::TYPE_ONCE;

    public function handle(): void
    {
        $GLOBALS['once_counter']++;
    }
};
PHP);

    $this->writeRunner('always_task.php', <<<'PHP'
<?php
use Obelaw\Runner\Runner;

return new class extends Runner {
    protected string $type = Runner::TYPE_ALWAYS;

    public function handle(): void
    {
        $GLOBALS['always_counter']++;
    }
};
PHP);

    $service = new RunnerService(RunnerPool::getPaths());
    $service->run();
    $service->run();

    expect($GLOBALS['once_counter'])->toBe(1);
    expect($GLOBALS['always_counter'])->toBe(2);

    $service->force(true)->run();
    expect($GLOBALS['once_counter'])->toBe(2);
    expect($GLOBALS['always_counter'])->toBe(3);
});

it('cascade-blocks dependents when an upstream dependency fails and returns non-zero CLI exit code', function (): void {
    $GLOBALS['downstream_ran'] = false;

    $this->writeRunner('a_failing.php', <<<'PHP'
<?php
use Obelaw\Runner\Runner;

return new class extends Runner {
    public function handle(): void
    {
        throw new RuntimeException('Upstream boom');
    }
};
PHP);

    $this->writeRunner('b_dependent.php', <<<'PHP'
<?php
use Obelaw\Runner\Runner;

return new class extends Runner {
    protected array $dependsOn = ['a_failing'];

    public function handle(): void
    {
        $GLOBALS['downstream_ran'] = true;
    }
};
PHP);

    $this->artisan('runner:run')->assertExitCode(1);

    expect($GLOBALS['downstream_ran'])->toBeFalse();
    expect(RunnerLog::where('runner_name', 'a_failing.php')->where('status', 'failed')->exists())->toBeTrue();
    expect(RunnerModel::hasBeenExecuted('b_dependent.php'))->toBeFalse();
});

it('detects circular dependencies and prevents infinite loops', function (): void {
    $this->writeRunner('cycle_a.php', <<<'PHP'
<?php
use Obelaw\Runner\Runner;

return new class extends Runner {
    protected array $dependsOn = ['cycle_b'];
    public function handle(): void {}
};
PHP);

    $this->writeRunner('cycle_b.php', <<<'PHP'
<?php
use Obelaw\Runner\Runner;

return new class extends Runner {
    protected array $dependsOn = ['cycle_a'];
    public function handle(): void {}
};
PHP);

    $service = new RunnerService(RunnerPool::getPaths());

    expect(fn () => $service->run())->toThrow(Exception::class, 'Circular dependency detected');
});

it('supports scheduled runners and renders runner:list and runner:tree commands cleanly', function (): void {
    $GLOBALS['scheduled_ran'] = false;

    $this->writeRunner('cron_job.php', <<<'PHP'
<?php
use Obelaw\Runner\Runner;

return new class extends Runner {
    public ?string $tag = 'cron';
    protected string $type = Runner::TYPE_ALWAYS;

    public function __construct()
    {
        $this->everyMinute();
    }

    public function handle(): void
    {
        $GLOBALS['scheduled_ran'] = true;
    }
};
PHP);

    $this->artisan('runner:run', ['--scheduled' => true])->assertExitCode(0);
    expect($GLOBALS['scheduled_ran'])->toBeTrue();

    $this->artisan('runner:list')->assertExitCode(0);
    $this->artisan('runner:tree')->assertExitCode(0);
});

it('deduplicates paths in RunnerPool::addPath()', function (): void {
    $before = count(RunnerPool::getPaths());
    RunnerPool::addPath($this->tempRunnerDir);

    expect(RunnerPool::getPaths())->toHaveCount($before);
});
