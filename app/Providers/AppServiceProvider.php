<?php

declare(strict_types=1);

namespace App\Providers;

use App\Listeners\DocumentEmailDelivered;
use App\Listeners\SecurityEventSubscriber;
use App\Listeners\WebhookEventSubscriber;
use App\Models\Customer;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductLocationStock;
use App\Models\Inventory\ProductVariant;
use App\Models\Inventory\StockAudit;
use App\Models\Inventory\StockTransfer;
use App\Models\Inventory\WorkOrder;
use App\Models\Order\Order;
use App\Models\Order\ReturnOrder;
use App\Models\Purchasing\PurchaseOrder;
use App\Models\Role;
use App\Models\User;
use App\Observers\CustomerObserver;
use App\Observers\OrderObserver;
use App\Observers\ProductLocationStockObserver;
use App\Observers\ProductObserver;
use App\Observers\ProductVariantObserver;
use App\Observers\PurchaseOrderObserver;
use App\Observers\ReturnOrderObserver;
use App\Observers\RoleSecurityObserver;
use App\Observers\StockAuditObserver;
use App\Observers\StockTransferObserver;
use App\Observers\UserSecurityObserver;
use App\Observers\WorkOrderObserver;
use App\Services\PluginService;
use App\Services\PluginUIService;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Mail\Markdown;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
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
        // PostgreSQL connections compile LIKE as ILIKE so searches are
        // case-insensitive there too, as they already are on MySQL/SQLite.
        \Illuminate\Database\Connection::resolverFor('pgsql', fn ($connection, $database, $prefix, $config) => new \App\Database\PostgresConnection($connection, $database, $prefix, $config));

        // Register PluginUIService as singleton
        $this->app->singleton(PluginUIService::class, function ($app) {
            return new PluginUIService();
        });

        // Permissions registered by active plugins (register_permission()).
        $this->app->singleton(\App\Services\Plugins\PluginPermissionRegistry::class);
        $this->app->singleton(\App\Services\Hooks\DomainHooks::class);
        // Scoped: its per-request document cache must not outlive a queued job.
        $this->app->scoped(\App\Services\Marketplace\PluginLicenceService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        // Markdown mail (the account invitation) interpolates names that users
        // and admins choose. Escape them so a name written as a Markdown link
        // or HTML stays text instead of becoming a link in the email.
        Markdown::withSecuredEncoding();

        // API rate limiting
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        // Register observers
        Product::observe(ProductObserver::class);
        ProductVariant::observe(ProductVariantObserver::class);
        ProductLocationStock::observe(ProductLocationStockObserver::class);
        Order::observe(OrderObserver::class);
        PurchaseOrder::observe(PurchaseOrderObserver::class);
        Customer::observe(CustomerObserver::class);
        ReturnOrder::observe(ReturnOrderObserver::class);
        StockTransfer::observe(StockTransferObserver::class);
        WorkOrder::observe(WorkOrderObserver::class);
        StockAudit::observe(StockAuditObserver::class);

        // Load active plugins
        if (file_exists(base_path('plugins'))) {
            $pluginService = app(PluginService::class);
            $pluginService->loadActivePlugins();
        }

        // Operators who enable plugin uploads without requiring signatures are
        // one admin-account compromise away from RCE; surface that posture.
        if (config('plugins.upload_enabled') && ! config('plugins.signature.required')) {
            Log::warning(
                'Plugin uploads are enabled without signature verification. '
                .'Set INVENTOROS_PLUGIN_SIGNATURE_REQUIRED=true and configure '
                .'INVENTOROS_PLUGIN_PUBLIC_KEY to require signed plugins.'
            );
        }

        // Register webhook event subscriber
        WebhookEventSubscriber::subscribe();

        // Security audit trail: sign-ins, 2FA, API tokens, account and role changes
        Event::subscribe(SecurityEventSubscriber::class);
        User::observe(UserSecurityObserver::class);
        Role::observe(RoleSecurityObserver::class);

        // A purchase order, invoice or shipment email is "sent" when it is
        // delivered, not when it is queued.
        Event::listen(MessageSent::class, [DocumentEmailDelivered::class, 'record']);
        Event::listen(\Illuminate\Database\Events\TransactionRolledBack::class, [\App\Services\Hooks\DomainHooks::class, 'handleRollback']);

        // Scramble API documentation - Bearer token security
        Scramble::afterOpenApiGenerated(function (OpenApi $openApi) {
            $openApi->secure(
                SecurityScheme::http('bearer', 'JWT')
            );
        });

        // The interactive API docs describe every endpoint and permission, so
        // outside local development they require a signed-in user unless the
        // operator opts in with API_DOCS_PUBLIC=true.
        Gate::define('viewApiDocs', function (?User $user = null): bool {
            if (app()->environment('local') || config('scramble.public_docs')) {
                return true;
            }

            return $user !== null;
        });
    }
}
