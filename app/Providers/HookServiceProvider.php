<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\HookManager;
use Illuminate\Support\ServiceProvider;

/**
 * Service provider for the hook/action system.
 *
 * Registers the HookManager as a singleton for managing
 * WordPress-style actions and filters throughout the application.
 */
class HookServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->singleton('hooks', function ($app) {
            return new HookManager();
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
    }
}
