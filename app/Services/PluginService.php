<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\PluginHookFailed;
use App\Models\Plugin;
use App\Services\Plugins\PluginAssetPublisher;
use App\Services\Plugins\PluginRequirements;
use App\Support\ReleaseSignatureVerifier;
use App\Support\SafeZipExtractor;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

/**
 * Service for managing plugins.
 *
 * Handles plugin discovery, activation, deactivation, deletion,
 * and loading of active plugins at runtime.
 */
final class PluginService
{
    /**
     * @var string Path to the plugins directory
     */
    protected string $pluginsPath;

    /**
     * Publishes and removes plugins' runtime UI bundles under public/plugins.
     */
    protected PluginAssetPublisher $assets;

    /**
     * Initialize the service and ensure plugins directory exists.
     */
    public function __construct()
    {
        $this->pluginsPath = base_path('plugins');
        $this->assets = new PluginAssetPublisher($this->pluginsPath, public_path('plugins'));

        // Ensure plugins directory exists
        if (! File::exists($this->pluginsPath)) {
            File::makeDirectory($this->pluginsPath, config('limits.permissions.directory'), true);
        }
    }

    /**
     * Get all installed plugins with their metadata.
     *
     * @return array<int, array{slug: string, name: string, description: string, version: string, author: string, author_url: string, requires: string, main_file: string, is_active: bool, activated_at: string|null, path: string}> List of plugin data arrays
     */
    public function getAllPlugins(): array
    {
        $plugins = [];
        $directories = File::directories($this->pluginsPath);

        foreach ($directories as $directory) {
            $pluginSlug = basename($directory);
            $manifestPath = $directory.'/plugin.json';

            if (File::exists($manifestPath)) {
                $manifest = json_decode(File::get($manifestPath), true);

                if (! is_array($manifest)) {
                    Log::warning('Plugin manifest is not valid JSON; skipping', ['slug' => $pluginSlug]);

                    continue;
                }

                // Get activation status from database
                $dbPlugin = Plugin::where('slug', $pluginSlug)->first();

                $plugins[] = [
                    'slug' => $pluginSlug,
                    'name' => $manifest['name'] ?? $pluginSlug,
                    'description' => $manifest['description'] ?? '',
                    'version' => $manifest['version'] ?? '1.0.0',
                    'author' => $manifest['author'] ?? 'Unknown',
                    'author_url' => $manifest['author_url'] ?? '',
                    'requires' => $manifest['requires'] ?? '1.0.0',
                    'requires_php' => $manifest['requires_php'] ?? null,
                    'has_runtime_ui' => is_array($manifest['ui'] ?? null),
                    'main_file' => $manifest['main_file'] ?? 'Plugin.php',
                    'is_active' => $dbPlugin ? $dbPlugin->is_active : false,
                    'activated_at' => $dbPlugin ? $dbPlugin->activated_at : null,
                    'path' => $directory,
                ];
            }
        }

        return $plugins;
    }

    /**
     * Get list of activated plugin slugs.
     *
     * @return array<int, string> List of active plugin slugs
     */
    public function getActivatedPlugins(): array
    {
        try {
            return Plugin::active()->pluck('slug')->toArray();
        } catch (\Exception $e) {
            Log::debug('Could not retrieve activated plugins - table may not exist yet', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Activate a plugin.
     *
     * In order: checks the plugin is installed and its `requires` /
     * `requires_php` are met, validates its runtime UI manifest, loads its
     * main file, runs hooks/activate.php, publishes its dist/ assets and fires
     * the `plugin_activated` actions. Only when all of that succeeds is the
     * plugin marked active; any failure leaves it inactive (with published
     * assets removed) and is rethrown with a readable message.
     *
     * @param  string  $slug  The plugin slug to activate
     * @return bool True on successful activation
     *
     * @throws \RuntimeException When the plugin is missing, unsupported or fails to activate.
     */
    public function activatePlugin(string $slug): bool
    {
        $this->assertSafeSlug($slug);

        $manifest = $this->readManifest($slug);
        if ($manifest === null) {
            throw new \RuntimeException("Plugin \"{$slug}\" is not installed (no valid plugin.json).");
        }

        $plugin = Plugin::firstOrCreate(['slug' => $slug], ['is_active' => false]);

        if ($plugin->is_active) {
            return true;
        }

        $name = is_string($manifest['name'] ?? null) ? $manifest['name'] : $slug;

        PluginRequirements::assertMet($manifest, $name);
        $ui = $this->assets->uiFor($slug, $manifest);

        try {
            $this->loadPlugin($slug, strict: true);
            $this->runLifecycleFile($slug, 'activate');

            if ($ui !== null) {
                $this->assets->publish($slug, $this->versionOf($manifest));
            }

            do_action('plugin_activated', $slug);
            do_action("plugin_activated_{$slug}");
        } catch (\Throwable $e) {
            $this->assets->remove($slug);

            Log::error('Plugin activation failed; plugin left inactive', ['slug' => $slug, 'error' => $e->getMessage()]);

            throw new \RuntimeException("{$name} could not be activated: {$e->getMessage()}", 0, $e);
        }

        $plugin->update([
            'is_active' => true,
            'activated_at' => now(),
            'deactivated_at' => null,
        ]);

        return true;
    }

    /**
     * Deactivate a plugin.
     *
     * Fires the `plugin_deactivated` actions and runs hooks/deactivate.php,
     * then marks the plugin inactive and removes its published assets. The
     * plugin is deactivated even when its own code fails; that failure is
     * reported afterwards as a PluginHookFailed.
     *
     * @param  string  $slug  The plugin slug to deactivate
     * @return bool True on successful deactivation
     *
     * @throws PluginHookFailed When the plugin was deactivated but its deactivate code failed.
     */
    public function deactivatePlugin(string $slug): bool
    {
        $this->assertSafeSlug($slug);

        $plugin = Plugin::where('slug', $slug)->first();

        if (! $plugin || ! $plugin->is_active) {
            return true;
        }

        $failure = null;

        try {
            do_action('plugin_deactivated', $slug);
            do_action("plugin_deactivated_{$slug}");
            $this->runLifecycleFile($slug, 'deactivate');
        } catch (\Throwable $e) {
            $failure = $e;
            Log::error('Plugin deactivate hook failed; deactivating anyway', ['slug' => $slug, 'error' => $e->getMessage()]);
        }

        $plugin->update([
            'is_active' => false,
            'deactivated_at' => now(),
        ]);

        $this->assets->remove($slug);

        if ($failure !== null) {
            throw new PluginHookFailed(
                "The plugin was deactivated, but its deactivate hook failed: {$failure->getMessage()}",
                0,
                $failure
            );
        }

        return true;
    }

    /**
     * Delete a plugin.
     *
     * Fires the `plugin_uninstalling` actions, deactivates the plugin (running
     * hooks/deactivate.php if it was active), runs hooks/uninstall.php, then
     * removes published assets, the database record and the plugin files.
     * Files are removed even when the plugin's own cleanup code fails; that
     * failure is reported afterwards as a PluginHookFailed.
     *
     * @param  string  $slug  The plugin slug to delete
     * @return bool True if plugin existed and was deleted, false if not found
     *
     * @throws PluginHookFailed When the plugin was deleted but its cleanup code failed.
     */
    public function deletePlugin(string $slug): bool
    {
        $this->assertSafeSlug($slug);

        $pluginPath = $this->pluginsPath.'/'.$slug;

        if (! File::exists($pluginPath)) {
            return false;
        }

        // Verify the resolved path stays inside the plugins directory before
        // touching the filesystem — defence-in-depth behind the slug guard.
        $pluginsRoot = realpath($this->pluginsPath);
        $resolved = realpath($pluginPath);
        if (! $pluginsRoot || ! $resolved
            || ! str_starts_with($resolved.DIRECTORY_SEPARATOR, $pluginsRoot.DIRECTORY_SEPARATOR)) {
            throw new \RuntimeException('Plugin path escapes the plugins directory.');
        }

        $plugin = Plugin::where('slug', $slug)->first();

        // Load plugin so its uninstall hooks can run
        if ($plugin && $plugin->is_active) {
            $this->loadPlugin($slug);
        }

        $failures = [];

        try {
            do_action('plugin_uninstalling', $slug);
            do_action("plugin_uninstalling_{$slug}");
        } catch (\Throwable $e) {
            $failures[] = $e;
        }

        try {
            $this->deactivatePlugin($slug);
        } catch (PluginHookFailed $e) {
            $failures[] = $e->getPrevious() ?? $e;
        }

        try {
            $this->runLifecycleFile($slug, 'uninstall');
        } catch (\Throwable $e) {
            $failures[] = $e;
        }

        $this->assets->remove($slug);

        if ($plugin) {
            $plugin->delete();
        }

        File::deleteDirectory($pluginPath);

        if ($failures !== []) {
            $messages = implode('; ', array_map(fn (\Throwable $e) => $e->getMessage(), $failures));
            Log::error('Plugin cleanup failed during deletion', ['slug' => $slug, 'error' => $messages]);

            throw new PluginHookFailed(
                "The plugin was deleted, but its cleanup code failed, so some of its data may remain: {$messages}",
                0,
                $failures[0]
            );
        }

        return true;
    }

    /**
     * Runtime UI bundles of the active plugins, for the browser to import().
     *
     * Re-publishes a bundle whose public copy has gone missing (an in-place
     * update can replace public/), and skips any plugin whose bundle is
     * invalid rather than failing the page.
     *
     * @return array<int, array{slug: string, entry: string, styles: array<int, string>}>
     */
    public function runtimeAssets(): array
    {
        $assets = [];

        foreach ($this->getActivatedPlugins() as $slug) {
            if (! $this->isSafeSlug($slug)) {
                continue;
            }

            $manifest = $this->readManifest($slug);
            if ($manifest === null) {
                continue;
            }

            try {
                $ui = $this->assets->uiFor($slug, $manifest);
                if ($ui === null) {
                    continue;
                }

                if (! $this->assets->isPublished($slug, $ui['entry'], $this->versionOf($manifest))) {
                    $this->assets->publish($slug, $this->versionOf($manifest));
                }
            } catch (\Throwable $e) {
                Log::warning('Skipping plugin runtime UI', ['slug' => $slug, 'error' => $e->getMessage()]);

                continue;
            }

            $version = rawurlencode($this->versionOf($manifest));
            $url = fn (string $path) => url("plugins/{$slug}/{$path}").'?v='.$version;

            $assets[] = [
                'slug' => $slug,
                'entry' => $url($ui['entry']),
                'styles' => array_map($url, $ui['styles']),
            ];
        }

        return $assets;
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function versionOf(array $manifest): string
    {
        return is_string($manifest['version'] ?? null) ? $manifest['version'] : '0';
    }

    /**
     * Read and decode a plugin's manifest, or null when it is missing or not
     * a JSON object.
     *
     * @return array<string, mixed>|null
     */
    protected function readManifest(string $slug): ?array
    {
        $manifestPath = $this->pluginsPath.'/'.$slug.'/plugin.json';

        if (! File::exists($manifestPath)) {
            return null;
        }

        $manifest = json_decode(File::get($manifestPath), true);

        if (! is_array($manifest)) {
            Log::warning('Plugin manifest is not valid JSON; skipping', ['slug' => $slug]);

            return null;
        }

        return $manifest;
    }

    /**
     * Run hooks/{activate,deactivate,uninstall}.php if the plugin ships it.
     *
     * The file is required inside a static closure so it cannot reach this
     * service through $this, and it must resolve inside the plugin directory.
     * Anything it throws propagates to the caller.
     */
    protected function runLifecycleFile(string $slug, string $hook): void
    {
        $pluginPath = realpath($this->pluginsPath.'/'.$slug);
        if ($pluginPath === false) {
            return;
        }

        $file = $pluginPath.DIRECTORY_SEPARATOR.'hooks'.DIRECTORY_SEPARATOR.$hook.'.php';
        if (! is_file($file)) {
            return;
        }

        $resolved = realpath($file);
        if ($resolved === false || ! str_starts_with($resolved, $pluginPath.DIRECTORY_SEPARATOR)) {
            throw new \RuntimeException("hooks/{$hook}.php resolves outside the plugin directory.");
        }

        (static function (string $__lifecycleFile): void {
            require $__lifecycleFile;
        })($resolved);
    }

    /**
     * Whether plugin uploads are currently enabled on this instance.
     *
     * Uploading a plugin grants arbitrary PHP execution inside the running
     * application process (the ZIP is extracted to /plugins and the
     * manifest's main_file is require_once'd at activation), so admin
     * compromise becomes server RCE. The feature is gated behind an
     * explicit env flag (INVENTOROS_ALLOW_PLUGIN_UPLOADS) and is off by
     * default.
     */
    public function uploadsEnabled(): bool
    {
        return (bool) config('plugins.upload_enabled', false);
    }

    /**
     * Enforce plugin signature verification when configured.
     *
     * Off by default. When plugins.signature.required is on this fails closed:
     * a missing public key, missing signature, or mismatch all reject the
     * upload (and delete the staged temp file) before extraction. The detached
     * Ed25519 signature covers the exact bytes of the uploaded ZIP.
     *
     * @param  string  $zipPath  Path to the staged upload on disk.
     * @param  string|null  $signature  Base64 detached signature, or null.
     *
     * @throws \RuntimeException When verification is required and fails.
     */
    protected function verifyPluginSignature(string $zipPath, ?string $signature): void
    {
        if (! (bool) config('plugins.signature.required', false)) {
            return;
        }

        $publicKey = (string) config('plugins.signature.public_key', '');

        if ($publicKey === '') {
            @unlink($zipPath);
            throw new \RuntimeException(
                'Plugin signature verification is required but no public key is configured. '
                .'Set INVENTOROS_PLUGIN_PUBLIC_KEY, or set INVENTOROS_PLUGIN_SIGNATURE_REQUIRED=false.'
            );
        }

        if ($signature === null || trim($signature) === '') {
            @unlink($zipPath);
            throw new \RuntimeException(
                'A plugin signature is required on this instance. Provide the detached '
                .'signature for this plugin ZIP in the signature field.'
            );
        }

        try {
            ReleaseSignatureVerifier::verify($zipPath, $signature, $publicKey);
        } catch (\Throwable $e) {
            @unlink($zipPath);
            throw new \RuntimeException('Plugin failed signature verification: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Upload and extract a plugin ZIP file.
     *
     * @param  mixed  $file  The uploaded file instance
     * @param  string|null  $signature  Optional base64 detached Ed25519 signature
     *                                  over the ZIP bytes. Required when
     *                                  plugins.signature.required is enabled.
     * @return array{slug: string, path: string} The extracted plugin slug and path
     *
     * @throws \Exception If uploads disabled, signature required/invalid, ZIP
     *                    cannot be opened, contains path-traversal entries,
     *                    exceeds limits, structure is invalid, plugin exists,
     *                    or plugin.json missing
     */
    public function uploadPlugin($file, ?string $signature = null): array
    {
        if (! $this->uploadsEnabled()) {
            throw new \RuntimeException(
                'Plugin uploads are disabled on this instance. Set '
                .'INVENTOROS_ALLOW_PLUGIN_UPLOADS=true to enable. Note: '
                .'enabling allows admin users to run arbitrary code on this server.'
            );
        }

        $tempName = 'upload-'.bin2hex(random_bytes(8)).'.zip';
        $file->storeAs('temp', $tempName);
        $tempPath = storage_path('app/private/temp/'.$tempName);
        if (! file_exists($tempPath)) {
            // Older Laravel storage layouts use app/temp/ without the
            // private/ prefix; fall back so the resolution works on both.
            $tempPath = storage_path('app/temp/'.$tempName);
        }

        // Verify the archive signature before opening it — a rejected upload
        // must not leave the temp file behind or touch the plugins directory.
        $this->verifyPluginSignature($tempPath, $signature);

        $zip = new ZipArchive;
        if ($zip->open($tempPath) !== true) {
            @unlink($tempPath);
            throw new \RuntimeException('Failed to open ZIP file');
        }

        try {
            // Validate every entry BEFORE writing anything to disk.
            $rootFolder = $this->validateZipArchive($zip);

            $extractPath = $this->pluginsPath.'/'.$rootFolder;
            if (File::exists($extractPath)) {
                throw new \RuntimeException('Plugin already exists');
            }

            $zip->extractTo($this->pluginsPath);
        } finally {
            $zip->close();
            @unlink($tempPath);
        }

        // Verify the extracted root is still inside our plugins directory.
        $pluginsRoot = realpath($this->pluginsPath);
        $extractedRoot = realpath($extractPath);
        if (! $pluginsRoot || ! $extractedRoot || ! str_starts_with($extractedRoot.DIRECTORY_SEPARATOR, $pluginsRoot.DIRECTORY_SEPARATOR)) {
            if ($extractedRoot && is_dir($extractedRoot)) {
                File::deleteDirectory($extractedRoot);
            }
            throw new \RuntimeException('Plugin extracted outside the plugins directory');
        }

        $manifestPath = $extractedRoot.'/plugin.json';
        if (! File::exists($manifestPath)) {
            File::deleteDirectory($extractedRoot);
            throw new \RuntimeException('Invalid plugin: missing plugin.json');
        }

        // Refuse a plugin this installation cannot run before it is kept.
        $manifest = json_decode(File::get($manifestPath), true);
        try {
            if (! is_array($manifest)) {
                throw new \RuntimeException('Invalid plugin: plugin.json is not a JSON object');
            }

            PluginRequirements::assertMet($manifest, is_string($manifest['name'] ?? null) ? $manifest['name'] : $rootFolder);
        } catch (\RuntimeException $e) {
            File::deleteDirectory($extractedRoot);
            throw $e;
        }

        return [
            'slug' => $rootFolder,
            'path' => $extractedRoot,
        ];
    }

    /**
     * Validate the archive (delegates to SafeZipExtractor) and additionally
     * enforce that the plugin lives under a single top-level directory.
     * Returns that root folder name.
     *
     * @throws \RuntimeException When the archive is invalid or unsafe.
     */
    protected function validateZipArchive(ZipArchive $zip): string
    {
        // Generic zip-slip / zip-bomb checks: path traversal, entry-count
        // cap, uncompressed-size cap. Shared with the in-app updater.
        SafeZipExtractor::validate($zip, $this->pluginsPath, [
            'max_entries' => (int) config('plugins.max_entry_count', 2000),
            'max_bytes' => (int) config('plugins.max_extracted_bytes', 50 * 1024 * 1024),
        ]);

        // Plugin-specific: archive must have exactly one top-level directory
        // because the loader treats that directory name as the plugin slug.
        $rootFolders = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false) {
                continue;
            }
            $rootSegment = strstr($stat['name'], '/', true);
            if ($rootSegment === false) {
                throw new \RuntimeException("Plugin ZIP must have a single root folder; found loose file: {$stat['name']}");
            }
            $rootFolders[$rootSegment] = true;
        }

        if (count($rootFolders) === 0) {
            throw new \RuntimeException('Invalid plugin structure: archive is empty');
        }
        if (count($rootFolders) > 1) {
            throw new \RuntimeException('Invalid plugin structure: ZIP must contain exactly one top-level directory');
        }

        return array_key_first($rootFolders);
    }

    /**
     * Load all active plugins.
     */
    public function loadActivePlugins(): void
    {
        $activatedPlugins = $this->getActivatedPlugins();

        foreach ($activatedPlugins as $slug) {
            $this->loadPlugin($slug);
        }
    }

    /**
     * Load a specific plugin.
     *
     * Requires the main plugin file and fires plugin_loaded action.
     * Skips (with a warning) rather than throwing for unsafe slugs because
     * this method runs at application boot for all DB-sourced slugs; a throw
     * here would take down every request.
     *
     * @param  string  $slug  The plugin slug to load
     * @param  bool  $strict  Throw instead of logging when the plugin cannot load (used on activation)
     */
    protected function loadPlugin(string $slug, bool $strict = false): void
    {
        if (! $this->isSafeSlug($slug)) {
            Log::warning('Unsafe plugin slug skipped at load time', ['slug' => $slug]);

            return;
        }

        $pluginPath = $this->pluginsPath.'/'.$slug;
        $manifest = $this->readManifest($slug);

        if ($manifest === null) {
            if ($strict) {
                throw new \RuntimeException('plugin.json is missing or invalid.');
            }

            return;
        }

        $mainFile = basename(is_string($manifest['main_file'] ?? null) ? $manifest['main_file'] : 'Plugin.php');
        $pluginFile = realpath($pluginPath.'/'.$mainFile);

        if (! $pluginFile || ! str_starts_with($pluginFile, realpath($pluginPath))) {
            Log::warning('Plugin main_file path traversal attempt blocked', ['slug' => $slug, 'main_file' => $manifest['main_file'] ?? null]);

            if ($strict) {
                throw new \RuntimeException("The main file \"{$mainFile}\" was not found in the plugin directory.");
            }

            return;
        }

        // Load the plugin file - it will have access to all helper functions.
        // At boot (non-strict) failures are isolated: a parse error or throw
        // while loading one plugin must not take down every request. On
        // activation (strict) the failure propagates so the plugin stays off.
        try {
            require_once $pluginFile;

            // Run the plugin's init action if it exists
            do_action('plugin_loaded', $slug, $manifest);
        } catch (\Throwable $e) {
            if ($strict) {
                throw $e;
            }

            Log::error('Failed to load plugin; skipping', ['slug' => $slug, 'error' => $e->getMessage()]);
        }
    }

    /**
     * A plugin slug is the basename of a directory under /plugins. Reject
     * anything that could escape that directory when concatenated into a
     * filesystem path (traversal, separators, absolute paths).
     */
    private function isSafeSlug(string $slug): bool
    {
        return $slug !== ''
            && preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $slug) === 1
            && ! str_contains($slug, '..');
    }

    private function assertSafeSlug(string $slug): void
    {
        if (! $this->isSafeSlug($slug)) {
            throw new \RuntimeException('Invalid plugin slug.');
        }
    }
}
