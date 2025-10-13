<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

class PluginService
{
    protected string $pluginsPath;
    protected string $activatedPluginsFile;

    public function __construct()
    {
        $this->pluginsPath = base_path('plugins');
        $this->activatedPluginsFile = storage_path('framework/activated_plugins.json');

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
        $activatedPlugins = $this->getActivatedPlugins();

        $directories = File::directories($this->pluginsPath);

        foreach ($directories as $directory) {
            $pluginSlug = basename($directory);
            $manifestPath = $directory . '/plugin.json';

            if (File::exists($manifestPath)) {
                $manifest = json_decode(File::get($manifestPath), true);

                $plugins[] = [
                    'slug' => $pluginSlug,
                    'name' => $manifest['name'] ?? $pluginSlug,
                    'description' => $manifest['description'] ?? '',
                    'version' => $manifest['version'] ?? '1.0.0',
                    'author' => $manifest['author'] ?? 'Unknown',
                    'author_url' => $manifest['author_url'] ?? '',
                    'requires' => $manifest['requires'] ?? '1.0.0',
                    'main_file' => $manifest['main_file'] ?? 'Plugin.php',
                    'is_active' => in_array($pluginSlug, $activatedPlugins),
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
        if (!File::exists($this->activatedPluginsFile)) {
            return [];
        }

        return json_decode(File::get($this->activatedPluginsFile), true) ?? [];
    }

    /**
     * Activate a plugin
     */
    public function activatePlugin(string $slug): bool
    {
        $activatedPlugins = $this->getActivatedPlugins();

        if (!in_array($slug, $activatedPlugins)) {
            $activatedPlugins[] = $slug;
            $this->saveActivatedPlugins($activatedPlugins);

            // Run activation hook if exists
            $this->runPluginHook($slug, 'activate');
        }

        return true;
    }

    /**
     * Deactivate a plugin
     */
    public function deactivatePlugin(string $slug): bool
    {
        $activatedPlugins = $this->getActivatedPlugins();

        $activatedPlugins = array_filter($activatedPlugins, fn($plugin) => $plugin !== $slug);
        $this->saveActivatedPlugins(array_values($activatedPlugins));

        // Run deactivation hook if exists
        $this->runPluginHook($slug, 'deactivate');

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

        // Deactivate first if active
        $this->deactivatePlugin($slug);

        // Run uninstall hook if exists
        $this->runPluginHook($slug, 'uninstall');

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

    /**
     * Run a plugin hook (activate, deactivate, uninstall)
     */
    protected function runPluginHook(string $slug, string $hook): void
    {
        $pluginPath = $this->pluginsPath . '/' . $slug;
        $hookFile = $pluginPath . '/hooks/' . $hook . '.php';

        if (File::exists($hookFile)) {
            require_once $hookFile;
        }
    }

    /**
     * Save activated plugins list
     */
    protected function saveActivatedPlugins(array $plugins): void
    {
        $directory = dirname($this->activatedPluginsFile);

        if (!File::exists($directory)) {
            File::makeDirectory($directory, 0755, true);
        }

        File::put($this->activatedPluginsFile, json_encode($plugins, JSON_PRETTY_PRINT));
    }
}
