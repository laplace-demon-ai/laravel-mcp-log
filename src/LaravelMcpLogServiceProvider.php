<?php

declare(strict_types=1);

namespace LaplaceDemonAI\LaravelMcpLog;

use Illuminate\Support\ServiceProvider;

final class LaravelMcpLogServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->registerPublishing();
    }

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->configure();
    }

    /**
     * Get the services provided by the provider.
     */
    public function provides(): array
    {
        return ['laravel-mcp-log'];
    }

    /**
     * Setup the configuration.
     */
    protected function configure(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/laravel-mcp-log.php', 'laravel-mcp-log'
        );
    }

    /**
     * Register the package's publishable resources.
     */
    protected function registerPublishing(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/laravel-mcp-log.php' => config_path('laravel-mcp-log.php'),
            ], 'laravel-mcp-log');
        }
    }
}
