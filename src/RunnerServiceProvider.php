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

            $this->publishes([
                __DIR__ . '/../resources/boost/skills' => base_path('.ai/skills'),
                __DIR__ . '/../resources/boost/guidelines' => base_path('.ai/guidelines/obelaw-runner'),
            ], 'runner-boost');

            $this->publishes([
                __DIR__ . '/../resources/boost/skills' => base_path('.ai/skills'),
            ], 'runner-boost-skills');

            $this->publishes([
                __DIR__ . '/../resources/boost/guidelines' => base_path('.ai/guidelines/obelaw-runner'),
            ], 'runner-boost-guidelines');

            $this->commands([
                RunnerCommand::class,
                RunnerListCommand::class,
                RunnerMakeCommand::class,
                RunnerTreeCommand::class,
            ]);
        }
    }
}
