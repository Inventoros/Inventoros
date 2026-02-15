<?php

declare(strict_types=1);

namespace App\Providers;

use App\Listeners\WebhookEventSubscriber;
use App\Models\Inventory\Product;
use App\Models\Order\Order;
use App\Observers\OrderObserver;
use App\Observers\ProductObserver;
use App\Services\PluginService;
use App\Services\PluginUIService;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

/**
 * Main application service provider.
 *
 * Handles registration of application services and bootstrapping
 * of core functionality including observers and plugins.
 */
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

        // Register observers
        Product::observe(ProductObserver::class);
        Order::observe(OrderObserver::class);

        // Load active plugins
        if (file_exists(base_path('plugins'))) {
            $pluginService = app(PluginService::class);
            $pluginService->loadActivePlugins();
        }

        // Register webhook event subscriber
        WebhookEventSubscriber::subscribe();
    }
}
