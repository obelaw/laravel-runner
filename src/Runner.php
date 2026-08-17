<?php

namespace Obelaw\Runner;

use Obelaw\Runner\Traits\Schedulable;

abstract class Runner
{
    use Schedulable;
    const TYPE_ONCE = 'once';
    const TYPE_ALWAYS = 'always';

    /**
     * The tag of the runner for filtering execution.
     *
     * @var string|null
     */
    public ?string $tag = null;

    /**
     * The priority of the runner (lower numbers run first).
     *
     * @var int
     */
    public int $priority = 0;

    /**
     * The description of what this runner does.
     *
     * @var string|null
     */
    public ?string $description = null;

    /**
     * The type of runner execution: 'once' or 'always'.
     *
     * @var string
     */
    protected string $type = self::TYPE_ONCE;

    /**
     * The filename this runner instance was loaded from, injected by
     * RunnerService::loadRunner(). Used for schedule dedup and dependency
     * resolution.
     *
     * @var string|null
     */
    protected ?string $runnerName = null;

    /**
     * Runner filenames (with or without the .php suffix) that must execute
     * successfully before this runner is allowed to run.
     *
     * @var array
     */
    protected array $dependsOn = [];

    /**
     * Execute the runner logic.
     *
     * @return void
     */
    abstract public function handle(): void;

    /**
     * Determine if the runner should be executed.
     * Override this method to add conditional execution logic.
     *
     * @return bool
     */
    public function shouldRun(): bool
    {
        // Check schedule if defined
        if (method_exists($this, 'shouldRunBySchedule')) {
            return $this->shouldRunBySchedule();
        }

        return true;
    }

    /**
     * Hook that runs before the main handle method.
     *
     * @return void
     */
    public function before(): void
    {
        //
    }

    /**
     * Hook that runs after the main handle method.
     *
     * @return void
     */
    public function after(): void
    {
        //
    }

    /**
     * Get the runner tag.
     *
     * @return string|null
     */
    public function getTag(): ?string
    {
        return $this->tag;
    }

    /**
     * Set the runner tag.
     *
     * @param string|null $tag
     * @return static
     */
    public function setTag(?string $tag): static
    {
        $this->tag = $tag;
        return $this;
    }

    /**
     * Get the runner priority.
     *
     * @return int
     */
    public function getPriority(): int
    {
        return $this->priority;
    }

    /**
     * Set the runner priority.
     *
     * @param int $priority
     * @return static
     */
    public function setPriority(int $priority): static
    {
        $this->priority = $priority;
        return $this;
    }

    /**
     * Get the runner description.
     *
     * @return string|null
     */
    public function getDescription(): ?string
    {
        return $this->description;
    }

    /**
     * Get the runner type.
     *
     * @return string
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * Set the runner type.
     *
     * @param string $type
     * @return static
     */
    public function setType(string $type): static
    {
        if (!in_array($type, [self::TYPE_ONCE, self::TYPE_ALWAYS])) {
            throw new \InvalidArgumentException("Invalid runner type. Must be 'once' or 'always'.");
        }

        $this->type = $type;
        return $this;
    }

    /**
     * Check if the runner type is 'once'.
     *
     * @return bool
     */
    public function isTypeOnce(): bool
    {
        return $this->type === self::TYPE_ONCE;
    }

    /**
     * Check if the runner type is 'always'.
     *
     * @return bool
     */
    public function isTypeAlways(): bool
    {
        return $this->type === self::TYPE_ALWAYS;
    }

    /**
     * Check if multiple runs are allowed.
     * Deprecated: Use isTypeAlways() instead.
     *
     * @return bool
     */
    public function allowsMultipleRuns(): bool
    {
        return $this->isTypeAlways();
    }

    /**
     * Set the runner's filename.
     *
     * @param string $name
     * @return static
     */
    public function setRunnerName(string $name): static
    {
        $this->runnerName = $name;
        return $this;
    }

    /**
     * Get the runner's filename.
     *
     * @return string|null
     */
    public function getRunnerName(): ?string
    {
        return $this->runnerName;
    }

    /**
     * Get the list of runner filenames this runner depends on.
     *
     * @return array
     */
    public function dependencies(): array
    {
        return $this->dependsOn;
    }

    /**
     * Set the list of runner filenames this runner depends on.
     *
     * @param array $dependencies
     * @return static
     */
    public function setDependencies(array $dependencies): static
    {
        $this->dependsOn = $dependencies;
        return $this;
    }

    /**
     * Get runner information as an array.
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'class' => static::class,
            'tag' => $this->tag,
            'priority' => $this->priority,
            'description' => $this->description,
            'type' => $this->type,
            'schedule' => $this->getSchedule(),
            'depends_on' => $this->dependsOn,
        ];
    }
}
