<?php

declare(strict_types=1);

namespace VendorName\Skeleton;

use Illuminate\Support\ServiceProvider;

final class SkeletonServiceProvider extends ServiceProvider
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
        return ['skeleton'];
    }

    /**
     * Setup the configuration.
     */
    protected function configure(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/skeleton.php', 'skeleton'
        );
    }

    /**
     * Register the package's publishable resources.
     */
    protected function registerPublishing(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/skeleton.php' => config_path('skeleton.php'),
            ], 'skeleton');
        }
    }
}