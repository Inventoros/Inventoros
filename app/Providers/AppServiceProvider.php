<?php

namespace App\Providers;

use App\Services\PluginService;
use App\Services\PluginUIService;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Register PluginUIService as singleton
        $this->app->singleton(PluginUIService::class, function ($app) {
            return new PluginUIService();
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        // Load active plugins
        if (file_exists(base_path('plugins'))) {
            $pluginService = app(PluginService::class);
            $pluginService->loadActivePlugins();
        }
    }
}
