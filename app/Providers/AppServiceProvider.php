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
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
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

        // API rate limiting
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

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

        // Scramble API documentation - Bearer token security
        Scramble::afterOpenApiGenerated(function (OpenApi $openApi) {
            $openApi->secure(
                SecurityScheme::http('bearer', 'JWT')
            );
        });

        // Gate for viewing API docs in production
        Gate::define('viewApiDocs', function ($user = null) {
            return true;
        });
    }
}
