<?php

declare(strict_types=1);

namespace Obelaw\Runner\Tests;

use Obelaw\Runner\RunnerPool;
use Obelaw\Runner\RunnerServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected string $tempRunnerDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', ['--force' => true]);

        $this->tempRunnerDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'obelaw_runners_' . bin2hex(random_bytes(4));
        mkdir($this->tempRunnerDir, 0777, true);

        RunnerPool::flush();
        RunnerPool::addPath($this->tempRunnerDir);
    }

    protected function tearDown(): void
    {
        RunnerPool::flush();

        if (is_dir($this->tempRunnerDir)) {
            foreach (glob($this->tempRunnerDir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->tempRunnerDir);
        }

        parent::tearDown();
    }

    protected function getPackageProviders($app): array
    {
        return [
            RunnerServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('cache.default', 'array');
    }

    protected function writeRunner(string $filename, string $code): string
    {
        $path = $this->tempRunnerDir . DIRECTORY_SEPARATOR . $filename;
        file_put_contents($path, $code);

        return $path;
    }
}
