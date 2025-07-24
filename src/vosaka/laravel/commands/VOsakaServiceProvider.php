<?php

declare(strict_types=1);

namespace vosaka\laravel;

use Illuminate\Support\ServiceProvider;
use vosaka\laravel\commands\VOsakaServer;

class VOsakaServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Register any bindings or services here if needed
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Register commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                VOsakaServer::class,
            ]);
        }
    }
}
