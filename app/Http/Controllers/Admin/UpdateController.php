<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\UpdateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class UpdateController extends Controller
{
    protected UpdateService $updateService;

    public function __construct(UpdateService $updateService)
    {
        $this->updateService = $updateService;
    }

    /**
     * Display the update management page
     */
    public function index(Request $request): Response
    {
        $user = $request->user();

        // Ensure only admins can access updates
        if (!$user->is_admin) {
            abort(403, 'Only administrators can manage updates.');
        }

        $currentVersion = $this->updateService->getCurrentVersion();
        $latestRelease = $this->updateService->getLatestRelease();
        $updateAvailable = $this->updateService->isUpdateAvailable();
        $backups = $this->updateService->listBackups();

        return Inertia::render('Admin/Update/Index', [
            'currentVersion' => $currentVersion,
            'latestRelease' => $latestRelease,
            'updateAvailable' => $updateAvailable,
            'backups' => $backups,
            'githubRepo' => config('app.github_repo'),
        ]);
    }

    /**
     * Check for available updates
     */
    public function check(): JsonResponse
    {
        $currentVersion = $this->updateService->getCurrentVersion();
        $latestRelease = $this->updateService->getLatestRelease();
        $updateAvailable = $this->updateService->isUpdateAvailable();

        return response()->json([
            'success' => true,
            'currentVersion' => $currentVersion,
            'latestRelease' => $latestRelease,
            'updateAvailable' => $updateAvailable,
        ]);
    }

    /**
     * Perform the update
     */
    public function update(Request $request): JsonResponse
    {
        $user = $request->user();

        // Ensure only admins can perform updates
        if (!$user->is_admin) {
            abort(403, 'Only administrators can perform updates.');
        }

        set_time_limit(config('limits.timeouts.update_operation'));

        $result = $this->updateService->update();

        return response()->json($result);
    }

    /**
     * Create a backup
     */
    public function backup(Request $request): JsonResponse
    {
        $user = $request->user();

        // Ensure only admins can create backups
        if (!$user->is_admin) {
            abort(403, 'Only administrators can create backups.');
        }

        try {
            $backupPath = $this->updateService->createBackup();

            return response()->json([
                'success' => true,
                'message' => 'Backup created successfully',
                'backupPath' => $backupPath,
            ]);
        } catch (\Exception $e) {
            Log::error('Backup creation failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Backup failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * List available backups
     */
    public function listBackups(): JsonResponse
    {
        $backups = $this->updateService->listBackups();

        return response()->json([
            'success' => true,
            'backups' => $backups,
        ]);
    }

    /**
     * Restore from a backup
     */
    public function restore(Request $request): JsonResponse
    {
        $user = $request->user();

        // Ensure only admins can restore backups
        if (!$user->is_admin) {
            abort(403, 'Only administrators can restore backups.');
        }

        $validated = $request->validate([
            'backup_file' => 'required|string',
        ]);

        $backupPath = storage_path('app/backups/' . basename($validated['backup_file']));

        if (!file_exists($backupPath)) {
            return response()->json([
                'success' => false,
                'message' => 'Backup file not found',
            ], 404);
        }

        set_time_limit(config('limits.timeouts.update_operation'));

        $result = $this->updateService->restoreFromBackup($backupPath);

        return response()->json($result);
    }

    /**
     * Delete a backup
     */
    public function deleteBackup(Request $request): JsonResponse
    {
        $user = $request->user();

        // Ensure only admins can delete backups
        if (!$user->is_admin) {
            abort(403, 'Only administrators can delete backups.');
        }

        $validated = $request->validate([
            'backup_file' => 'required|string',
        ]);

        $backupPath = storage_path('app/backups/' . basename($validated['backup_file']));

        if (!file_exists($backupPath)) {
            return response()->json([
                'success' => false,
                'message' => 'Backup file not found',
            ], 404);
        }

        if (unlink($backupPath)) {
            // Also delete associated database backup if exists
            $dbBackupPath = str_replace('backup_', 'database_', $backupPath);
            $dbBackupPath = str_replace('.zip', '.sql', $dbBackupPath);
            if (file_exists($dbBackupPath)) {
                unlink($dbBackupPath);
            }

            return response()->json([
                'success' => true,
                'message' => 'Backup deleted successfully',
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Failed to delete backup',
        ], 500);
    }
}
