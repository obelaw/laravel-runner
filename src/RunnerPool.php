<?php

declare(strict_types=1);

namespace Obelaw\Runner;

class RunnerPool
{
    /**
     * Registered directory paths containing runner files.
     *
     * @var list<string>
     */
    protected static array $paths = [];

    /**
     * Add a path to the runner pools (deduplicated).
     */
    public static function addPath(string $path): void
    {
        if (! in_array($path, self::$paths, true)) {
            self::$paths[] = $path;
        }
    }

    /**
     * Get all registered runner pool paths.
     *
     * @return list<string>
     */
    public static function getPaths(): array
    {
        return self::$paths;
    }

    /**
     * Clear all registered runner pool paths (useful in tests and isolated runtimes).
     */
    public static function flush(): void
    {
        self::$paths = [];
    }
}
