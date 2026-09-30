<?php

declare(strict_types=1);

namespace App\Services\Marketplace;

use App\Models\Auth\Organization;
use App\Models\Plugin;
use App\Services\PluginService;
use App\Support\AppVersion;
use App\Support\ReleaseSignatureVerifier;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * One-click install and update from the marketplace.
 *
 * The package is downloaded server side and its detached Ed25519 signature
 * is verified against the marketplace public key shipped in config. The
 * signature covers the plugin's slug, version and sha256 together (see
 * PackageSignature), so a genuinely signed package cannot be passed off as
 * another plugin or another version. The sha256 is also checked against the
 * catalog, the plugin.json inside the package must carry the signed version,
 * and an update must be newer than what is installed by that SIGNED version,
 * never by the unsigned catalog alone. Only then is the package handed to
 * PluginService, which applies exactly the same archive safety and `requires`
 * checks as a manual upload. Because the package is signed, this works even
 * when manual ZIP uploads are disabled.
 */
final class MarketplaceInstaller
{
    public function __construct(
        private readonly MarketplaceClient $client,
        private readonly PluginService $plugins,
    ) {}

    /**
     * @return array{name: string, version: string, activated: bool, activation_error: string|null}
     *
     * @throws MarketplaceException
     */
    public function install(Organization $organization, string $slug, bool $activate = false): array
    {
        $this->assertPublicKey();

        $token = $organization->marketplace_token;
        $entry = $this->entryFor($slug, $token);
        $name = $this->nameOf($entry);

        if (File::isDirectory(base_path('plugins/'.$slug))) {
            throw new MarketplaceException("{$name} is already installed. Use Update to install a newer version.");
        }

        $this->assertAccess($entry, $token);

        ['path' => $zip, 'version' => $version] = $this->downloadVerified($entry, $token);

        try {
            $this->plugins->installFromZip($zip, $slug, $version);
            Plugin::updateOrCreate(['slug' => $slug], ['source' => Plugin::SOURCE_MARKETPLACE]);
        } catch (\RuntimeException $e) {
            throw new MarketplaceException("{$name} could not be installed: {$e->getMessage()}", 0, $e);
        } finally {
            @unlink($zip);
        }

        $activationError = null;
        if ($activate) {
            try {
                $this->plugins->activatePlugin($slug);
            } catch (\Throwable $e) {
                $activationError = $e->getMessage();
            }
        }

        return [
            'name' => $name,
            'version' => $version,
            'activated' => $activate && $activationError === null,
            'activation_error' => $activationError,
        ];
    }

    /**
     * @return array{name: string, from: string, to: string, warning: string|null}
     *
     * @throws MarketplaceException
     */
    public function update(Organization $organization, string $slug): array
    {
        $this->assertPublicKey();

        $token = $organization->marketplace_token;
        $entry = $this->entryFor($slug, $token);
        $name = $this->nameOf($entry);

        $installed = $this->installedVersion($slug);
        if ($installed === null) {
            throw new MarketplaceException("{$name} is not installed. Use Install instead.");
        }

        // Never overwrite a plugin the marketplace did not install (an upload
        // or a hand-copied plugin that happens to share this slug).
        if (! self::installedFromMarketplace($slug)) {
            throw new MarketplaceException("A plugin named \"{$slug}\" is installed on this server, but it was not installed from the marketplace, so the marketplace will not replace it. Delete it first to install the marketplace version.");
        }

        if (! self::isNewer((string) $entry['version'], $installed)) {
            throw new MarketplaceException("{$name} is already up to date ({$installed}).");
        }

        $this->assertAccess($entry, $token);

        ['path' => $zip, 'version' => $version] = $this->downloadVerified($entry, $token);

        try {
            // Judge "newer" on the signed version, not the catalog's claim.
            if (! self::isNewer($version, $installed)) {
                throw new MarketplaceException("The marketplace package is version {$version}, which is not newer than the installed {$installed}; it was not installed.");
            }

            $result = $this->plugins->replaceFromZip($zip, $slug, $version);
        } catch (\RuntimeException $e) {
            throw new MarketplaceException("{$name} could not be updated: {$e->getMessage()}", 0, $e);
        } finally {
            @unlink($zip);
        }

        return [
            'name' => $name,
            'from' => $installed,
            'to' => $version,
            'warning' => $result['warning'],
        ];
    }

    /**
     * The version in an installed plugin's plugin.json, or null when it is not installed.
     */
    public function installedVersion(string $slug): ?string
    {
        if (! MarketplaceClient::isValidSlug($slug)) {
            return null;
        }

        $manifest = base_path('plugins/'.$slug.'/plugin.json');
        if (! is_file($manifest)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($manifest), true);

        return is_array($data) && is_string($data['version'] ?? null) ? $data['version'] : '0';
    }

    public static function installedFromMarketplace(string $slug): bool
    {
        return Plugin::where('slug', $slug)->value('source') === Plugin::SOURCE_MARKETPLACE;
    }

    public static function isNewer(string $available, string $installed): bool
    {
        $a = AppVersion::normalise($available);
        $b = AppVersion::normalise($installed);

        return $a !== null && ($b === null || version_compare($a, $b, '>'));
    }

    public static function publicKeyConfigured(): bool
    {
        return trim((string) config('marketplace.public_key', '')) !== '';
    }

    private function assertPublicKey(): void
    {
        if (! self::publicKeyConfigured()) {
            throw new MarketplaceException(
                'Marketplace installs are disabled because no marketplace signing key is configured. Set INVENTOROS_MARKETPLACE_PUBLIC_KEY (see docs/PLUGIN_DEVELOPMENT.md).'
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function entryFor(string $slug, ?string $token): array
    {
        $entry = $this->client->plugin($slug, $token);

        if ($entry === null) {
            throw new MarketplaceException('This plugin is not available from the marketplace.');
        }

        if (! is_string($entry['version'] ?? null) || ! is_string($entry['checksum'] ?? null)) {
            throw new MarketplaceException('The marketplace listing for this plugin is incomplete; it cannot be installed.');
        }

        return $entry;
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function assertAccess(array $entry, ?string $token): void
    {
        if (($entry['has_access'] ?? false) === true) {
            return;
        }

        $name = $this->nameOf($entry);

        if ($token === null || $token === '') {
            throw new MarketplaceException(
                "{$name} is a paid plugin. Connect your inventoros.com account on the Marketplace tab to install it, or buy it at "
                .$this->client->pageUrl((string) $entry['slug']).'.'
            );
        }

        throw new MarketplaceException(
            "Your connected inventoros.com account does not own {$name}. Get it at ".$this->client->pageUrl((string) $entry['slug']).'.'
        );
    }

    /**
     * Download the package and prove it is the one the marketplace signed and
     * listed: the signature must cover this slug, the served version and the
     * downloaded bytes' sha256. Returns the verified temp file and its signed
     * version; the caller deletes the file.
     *
     * @param  array<string, mixed>  $entry
     * @return array{path: string, version: string}
     */
    private function downloadVerified(array $entry, ?string $token): array
    {
        $slug = (string) $entry['slug'];
        $download = $this->client->download($slug, $token);
        $path = $download['path'];

        try {
            if ($download['signature'] === null) {
                throw new MarketplaceException('The marketplace package is not signed, so it was not installed.');
            }

            $version = $download['version'];
            if ($version === null || ! PackageSignature::isValidVersion($version)) {
                throw new MarketplaceException('The marketplace did not say which version this package is, so it was not installed.');
            }

            if ($version !== (string) $entry['version']) {
                throw new MarketplaceException("The marketplace served version {$version} but lists {$entry['version']}, so it was not installed. Try again in a minute.");
            }

            $actual = (string) hash_file('sha256', $path);

            try {
                ReleaseSignatureVerifier::verifyMessage(
                    PackageSignature::message($slug, $version, $actual),
                    $download['signature'],
                    (string) config('marketplace.public_key'),
                );
            } catch (\RuntimeException $e) {
                Log::warning('Marketplace package failed signature verification', ['slug' => $slug, 'version' => $version, 'error' => $e->getMessage()]);

                throw new MarketplaceException('The plugin package failed signature verification, so it was not installed. It may be corrupt or tampered with.', 0, $e);
            }

            $expected = [strtolower((string) $entry['checksum'])];
            if ($download['checksum'] !== null) {
                $expected[] = strtolower($download['checksum']);
            }

            foreach ($expected as $checksum) {
                if (! hash_equals($checksum, $actual)) {
                    throw new MarketplaceException('The plugin package checksum does not match the marketplace listing, so it was not installed.');
                }
            }
        } catch (\Throwable $e) {
            @unlink($path);

            throw $e;
        }

        return ['path' => $path, 'version' => $version];
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function nameOf(array $entry): string
    {
        return is_string($entry['name'] ?? null) && $entry['name'] !== '' ? $entry['name'] : (string) $entry['slug'];
    }
}
