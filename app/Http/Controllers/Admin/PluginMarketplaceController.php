<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Marketplace\MarketplaceClient;
use App\Services\Marketplace\MarketplaceException;
use App\Services\Marketplace\MarketplaceInstaller;
use App\Services\PluginService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Plugins > Marketplace: browse the inventoros.com catalog, connect an
 * account, and install or update plugins in one click.
 */
class PluginMarketplaceController extends Controller
{
    public function __construct(
        private readonly PluginService $plugins,
        private readonly MarketplaceClient $client,
        private readonly MarketplaceInstaller $installer,
    ) {}

    public function index(Request $request): Response
    {
        $organization = $request->user()->organization;
        $token = $organization?->marketplace_token;

        $catalog = [];
        $error = null;

        try {
            $catalog = $this->client->catalog($token);
        } catch (MarketplaceException $e) {
            $error = $e->getMessage();
        }

        $origin = null;
        try {
            $origin = $this->client->origin();
        } catch (MarketplaceException) {
            // Already reported through $error.
        }

        $plugins = array_map(function (array $entry) {
            $installed = $this->installer->installedVersion($entry['slug']);
            $version = is_string($entry['version'] ?? null) ? $entry['version'] : '';

            return [
                'slug' => $entry['slug'],
                'name' => (string) ($entry['name'] ?? $entry['slug']),
                'summary' => (string) ($entry['summary'] ?? ''),
                'version' => $version,
                'requires' => $entry['requires'] ?? null,
                'author' => $entry['author'] ?? null,
                'icon' => $this->safeUrl($entry['icon'] ?? null),
                'homepage' => $this->safeUrl($entry['homepage'] ?? null),
                'pricing_type' => (string) ($entry['pricing_type'] ?? 'free'),
                'price' => $entry['price'] ?? null,
                'billing_interval' => $entry['billing_interval'] ?? null,
                'is_free' => (bool) ($entry['is_free'] ?? false),
                'has_access' => (bool) ($entry['has_access'] ?? false),
                'installed_version' => $installed,
                'update_available' => $installed !== null && MarketplaceInstaller::isNewer($version, $installed),
            ];
        }, $catalog);

        return Inertia::render('Plugins/Index', [
            'plugins' => $this->plugins->getAllPlugins(),
            'uploadsEnabled' => $this->plugins->uploadsEnabled(),
            'activeTab' => 'marketplace',
            'marketplace' => [
                'url' => $origin,
                'configured' => MarketplaceInstaller::publicKeyConfigured(),
                'connected' => $token !== null && $token !== '',
                'account' => $organization?->marketplace_account,
                'plugins' => $plugins,
                'error' => $error,
            ],
        ]);
    }

    public function connect(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'max:255'],
        ]);

        $token = trim($validated['token']);

        try {
            $account = $this->client->me($token);
        } catch (MarketplaceException $e) {
            return back()->with('error', $e->getMessage());
        }

        $request->user()->organization->forceFill([
            'marketplace_token' => $token,
            'marketplace_account' => [
                'name' => $account['name'],
                'email' => $account['email'],
                'connected_at' => now()->toIso8601String(),
            ],
        ])->save();

        return redirect()->route('plugins.marketplace')
            ->with('success', 'Connected to your inventoros.com account.');
    }

    public function disconnect(Request $request): RedirectResponse
    {
        $request->user()->organization->forceFill([
            'marketplace_token' => null,
            'marketplace_account' => null,
        ])->save();

        return redirect()->route('plugins.marketplace')
            ->with('success', 'Disconnected from inventoros.com.');
    }

    public function install(Request $request, string $slug): RedirectResponse
    {
        try {
            $result = $this->installer->install($request->user()->organization, $slug, $request->boolean('activate'));
        } catch (MarketplaceException $e) {
            return back()->with('error', $e->getMessage());
        }

        if ($result['activation_error'] !== null) {
            return back()->with('warning', "{$result['name']} was installed but could not be activated: {$result['activation_error']}");
        }

        return back()->with('success', $result['activated']
            ? "{$result['name']} {$result['version']} was installed and activated."
            : "{$result['name']} {$result['version']} was installed. Activate it on the Installed tab.");
    }

    public function update(Request $request, string $slug): RedirectResponse
    {
        try {
            $result = $this->installer->update($request->user()->organization, $slug);
        } catch (MarketplaceException $e) {
            return back()->with('error', $e->getMessage());
        }

        if ($result['warning'] !== null) {
            return back()->with('warning', "{$result['name']} was updated to {$result['to']}. {$result['warning']}");
        }

        return back()->with('success', "{$result['name']} was updated from {$result['from']} to {$result['to']}.");
    }

    /**
     * Only pass http(s) links from the marketplace through to the page.
     */
    private function safeUrl(mixed $url): ?string
    {
        return is_string($url) && preg_match('#^https?://#i', $url) === 1 ? $url : null;
    }
}
