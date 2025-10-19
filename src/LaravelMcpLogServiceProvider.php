<?php

declare(strict_types=1);

namespace LaplaceDemonAI\LaravelMcpLog;

use Illuminate\Support\Arr;
use Illuminate\Support\ServiceProvider;
use LaplaceDemonAI\LaravelMcpLog\Servers\LogReaderServer;
use Laravel\Mcp\Facades\Mcp;
use MoeMizrak\LaravelLogReader\LaravelLogReaderServiceProvider;

final class LaravelMcpLogServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->registerMcpServers();

        $this->registerPublishing();
    }

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->configure();

        $this->configureLaravelLogReader();
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

    /**
     * Register MCP servers based on configuration.
     */
    protected function registerMcpServers(): void
    {
        $servers = config('laravel-mcp-log.servers', []);
        $localEnabled = Arr::get($servers, 'local', false);
        $webEnabled = Arr::get($servers, 'web', false);

        if ($localEnabled) {
            Mcp::local('mcp/log-reader', LogReaderServer::class);
        }

        if ($webEnabled) {
            Mcp::web('mcp/log-reader', LogReaderServer::class);
        }
    }

    /**
     * Configure the laravel-log-reader package based on laravel-mcp-log config.
     */
    protected function configureLaravelLogReader(): void
    {
        // Map laravel-mcp-log config to laravel-log-reader config if not already set
        if (! config('laravel-log-reader')) {
            config(['laravel-log-reader.driver' => config('laravel-mcp-log.log_reader.driver')]);
            config(['laravel-log-reader.file.path' => config('laravel-mcp-log.log_reader.file.path')]);
            config(['laravel-log-reader.file.chunk_size' => config('laravel-mcp-log.log_reader.file.chunk_size')]);
            config(['laravel-log-reader.db.table' => config('laravel-mcp-log.log_reader.db.table')]);
            config(['laravel-log-reader.db.connection' => config('laravel-mcp-log.log_reader.db.connection')]);
            config(['laravel-log-reader.db.chunk_size' => config('laravel-mcp-log.log_reader.db.chunk_size')]);
            config([
                'laravel-log-reader.db.columns' => config('laravel-mcp-log.log_reader.db.columns'),
            ]);
            config([
                'laravel-log-reader.db.searchable_columns' => config('laravel-mcp-log.log_reader.db.searchable_columns'),
            ]);
        }

        // Register LaravelLogReaderServiceProvider
        $this->app->register(LaravelLogReaderServiceProvider::class);
    }
}
