<?php

namespace Obelaw\Runner;

use Illuminate\Support\ServiceProvider;
use Obelaw\Runner\Console\Commands\RunnerCommand;
use Obelaw\Runner\Console\Commands\RunnerListCommand;
use Obelaw\Runner\Console\Commands\RunnerMakeCommand;
use Obelaw\Runner\Console\Commands\RunnerTreeCommand;
use Obelaw\Runner\RunnerPool;

class RunnerServiceProvider extends ServiceProvider
{

    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {
        //
    }

    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot()
    {
        // Register the default runners' path
        RunnerPool::addPath(base_path('runners'));

        if ($this->app->runningInConsole()) {
            $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

            $this->commands([
                RunnerCommand::class,
                RunnerListCommand::class,
                RunnerMakeCommand::class,
                RunnerTreeCommand::class,
            ]);
        }
    }
}
