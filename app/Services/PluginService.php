<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Plugin;
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
     * Initialize the service and ensure plugins directory exists.
     */
    public function __construct()
    {
        $this->pluginsPath = base_path('plugins');

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
     * Fires plugin activation hooks and loads the plugin.
     *
     * @param  string  $slug  The plugin slug to activate
     * @return bool True on successful activation
     */
    public function activatePlugin(string $slug): bool
    {
        $plugin = Plugin::where('slug', $slug)->first();

        if (! $plugin) {
            // Create new plugin record if it doesn't exist
            $plugin = Plugin::create([
                'slug' => $slug,
                'is_active' => false,
            ]);
        }

        if (! $plugin->is_active) {
            $plugin->update([
                'is_active' => true,
                'activated_at' => now(),
                'deactivated_at' => null,
            ]);

            // Load the plugin first so its hooks are registered
            $this->loadPlugin($slug);

            // Fire activation action hook
            do_action('plugin_activated', $slug);
            do_action("plugin_activated_{$slug}");
        }

        return true;
    }

    /**
     * Deactivate a plugin.
     *
     * Fires plugin deactivation hooks before deactivating.
     *
     * @param  string  $slug  The plugin slug to deactivate
     * @return bool True on successful deactivation
     */
    public function deactivatePlugin(string $slug): bool
    {
        $plugin = Plugin::where('slug', $slug)->first();

        if ($plugin && $plugin->is_active) {
            // Fire deactivation action hook BEFORE deactivating
            do_action('plugin_deactivated', $slug);
            do_action("plugin_deactivated_{$slug}");

            $plugin->update([
                'is_active' => false,
                'deactivated_at' => now(),
            ]);
        }

        return true;
    }

    /**
     * Delete a plugin.
     *
     * Runs uninstall hooks, deactivates, removes database record, and deletes files.
     *
     * @param  string  $slug  The plugin slug to delete
     * @return bool True if plugin existed and was deleted, false if not found
     */
    public function deletePlugin(string $slug): bool
    {
        $pluginPath = $this->pluginsPath.'/'.$slug;

        if (! File::exists($pluginPath)) {
            return false;
        }

        $plugin = Plugin::where('slug', $slug)->first();

        // Load plugin so its uninstall hooks can run
        if ($plugin && $plugin->is_active) {
            $this->loadPlugin($slug);
        }

        // Fire uninstall action hook
        do_action('plugin_uninstalling', $slug);
        do_action("plugin_uninstalling_{$slug}");

        // Deactivate first if active
        $this->deactivatePlugin($slug);

        // Delete database record
        if ($plugin) {
            $plugin->delete();
        }

        // Delete the plugin directory
        File::deleteDirectory($pluginPath);

        return true;
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
     *
     * @param  string  $slug  The plugin slug to load
     */
    protected function loadPlugin(string $slug): void
    {
        $pluginPath = $this->pluginsPath.'/'.$slug;
        $manifestPath = $pluginPath.'/plugin.json';

        if (! File::exists($manifestPath)) {
            return;
        }

        $manifest = json_decode(File::get($manifestPath), true);
        $mainFile = basename($manifest['main_file'] ?? 'Plugin.php');
        $pluginFile = realpath($pluginPath.'/'.$mainFile);

        if (! $pluginFile || ! str_starts_with($pluginFile, realpath($pluginPath))) {
            Log::warning('Plugin main_file path traversal attempt blocked', ['slug' => $slug, 'main_file' => $manifest['main_file'] ?? null]);

            return;
        }

        if (File::exists($pluginFile)) {
            // Load the plugin file - it will have access to all helper functions
            require_once $pluginFile;

            // Run the plugin's init action if it exists
            do_action('plugin_loaded', $slug, $manifest);
        }
    }
}
