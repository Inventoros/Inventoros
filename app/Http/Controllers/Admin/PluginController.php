<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\PluginService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PluginController extends Controller
{
    protected PluginService $pluginService;

    public function __construct(PluginService $pluginService)
    {
        $this->pluginService = $pluginService;
    }

    /**
     * Display a listing of plugins.
     */
    public function index(): Response
    {
        $plugins = $this->pluginService->getAllPlugins();

        return Inertia::render('Plugins/Index', [
            'plugins' => $plugins,
        ]);
    }

    /**
     * Upload a new plugin.
     */
    public function upload(Request $request)
    {
        $request->validate([
            'plugin' => 'required|file|mimes:zip|max:51200', // 50MB max
        ]);

        try {
            $result = $this->pluginService->uploadPlugin($request->file('plugin'));

            return redirect()->route('plugins.index')
                ->with('success', 'Plugin uploaded successfully. You can now activate it.');
        } catch (\Exception $e) {
            return redirect()->back()
                ->with('error', 'Failed to upload plugin: ' . $e->getMessage());
        }
    }

    /**
     * Activate a plugin.
     */
    public function activate(string $slug)
    {
        try {
            $this->pluginService->activatePlugin($slug);

            return redirect()->back()
                ->with('success', 'Plugin activated successfully.');
        } catch (\Exception $e) {
            return redirect()->back()
                ->with('error', 'Failed to activate plugin: ' . $e->getMessage());
        }
    }

    /**
     * Deactivate a plugin.
     */
    public function deactivate(string $slug)
    {
        try {
            $this->pluginService->deactivatePlugin($slug);

            return redirect()->back()
                ->with('success', 'Plugin deactivated successfully.');
        } catch (\Exception $e) {
            return redirect()->back()
                ->with('error', 'Failed to deactivate plugin: ' . $e->getMessage());
        }
    }

    /**
     * Delete a plugin.
     */
    public function destroy(string $slug)
    {
        try {
            $this->pluginService->deletePlugin($slug);

            return redirect()->back()
                ->with('success', 'Plugin deleted successfully.');
        } catch (\Exception $e) {
            return redirect()->back()
                ->with('error', 'Failed to delete plugin: ' . $e->getMessage());
        }
    }
}
