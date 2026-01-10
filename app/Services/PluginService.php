<?php

namespace App\Services;

use App\Models\Plugin;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

class PluginService
{
    protected string $pluginsPath;

    public function __construct()
    {
        $this->pluginsPath = base_path('plugins');

        // Ensure plugins directory exists
        if (!File::exists($this->pluginsPath)) {
            File::makeDirectory($this->pluginsPath, 0755, true);
        }
    }

    /**
     * Get all installed plugins with their metadata
     */
    public function getAllPlugins(): array
    {
        $plugins = [];
        $directories = File::directories($this->pluginsPath);

        foreach ($directories as $directory) {
            $pluginSlug = basename($directory);
            $manifestPath = $directory . '/plugin.json';

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
     * Get list of activated plugin slugs
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
     * Activate a plugin
     */
    public function activatePlugin(string $slug): bool
    {
        $plugin = Plugin::where('slug', $slug)->first();

        if (!$plugin) {
            // Create new plugin record if it doesn't exist
            $plugin = Plugin::create([
                'slug' => $slug,
                'is_active' => false,
            ]);
        }

        if (!$plugin->is_active) {
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
     * Deactivate a plugin
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
     * Delete a plugin
     */
    public function deletePlugin(string $slug): bool
    {
        $pluginPath = $this->pluginsPath . '/' . $slug;

        if (!File::exists($pluginPath)) {
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
     * Upload and extract a plugin ZIP file
     */
    public function uploadPlugin($file): array
    {
        $zip = new ZipArchive();
        $tempPath = storage_path('app/temp/' . $file->getClientOriginalName());

        // Save uploaded file temporarily
        $file->storeAs('temp', $file->getClientOriginalName());

        if ($zip->open($tempPath) !== true) {
            throw new \Exception('Failed to open ZIP file');
        }

        // Get the root folder name from ZIP
        $rootFolder = '';
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $name = $stat['name'];

            if (strpos($name, '/') !== false) {
                $rootFolder = substr($name, 0, strpos($name, '/'));
                break;
            }
        }

        if (empty($rootFolder)) {
            $zip->close();
            throw new \Exception('Invalid plugin structure');
        }

        // Extract to plugins directory
        $extractPath = $this->pluginsPath . '/' . $rootFolder;

        if (File::exists($extractPath)) {
            $zip->close();
            throw new \Exception('Plugin already exists');
        }

        $zip->extractTo($this->pluginsPath);
        $zip->close();

        // Clean up temp file
        Storage::delete('temp/' . $file->getClientOriginalName());

        // Validate plugin.json exists
        $manifestPath = $extractPath . '/plugin.json';
        if (!File::exists($manifestPath)) {
            File::deleteDirectory($extractPath);
            throw new \Exception('Invalid plugin: missing plugin.json');
        }

        return [
            'slug' => $rootFolder,
            'path' => $extractPath,
        ];
    }

    /**
     * Load all active plugins
     */
    public function loadActivePlugins(): void
    {
        $activatedPlugins = $this->getActivatedPlugins();

        foreach ($activatedPlugins as $slug) {
            $this->loadPlugin($slug);
        }
    }

    /**
     * Load a specific plugin
     */
    protected function loadPlugin(string $slug): void
    {
        $pluginPath = $this->pluginsPath . '/' . $slug;
        $manifestPath = $pluginPath . '/plugin.json';

        if (!File::exists($manifestPath)) {
            return;
        }

        $manifest = json_decode(File::get($manifestPath), true);
        $mainFile = $manifest['main_file'] ?? 'Plugin.php';
        $pluginFile = $pluginPath . '/' . $mainFile;

        if (File::exists($pluginFile)) {
            // Load the plugin file - it will have access to all helper functions
            require_once $pluginFile;

            // Run the plugin's init action if it exists
            do_action('plugin_loaded', $slug, $manifest);
        }
    }

}
