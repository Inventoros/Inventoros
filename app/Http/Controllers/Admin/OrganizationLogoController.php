<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Auth\Organization;
use App\Services\FileStorageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class OrganizationLogoController extends Controller
{
    public function show(Request $request, Organization $organization): StreamedResponse
    {
        abort_unless((int) $request->user()->organization_id === (int) $organization->id, 404);
        $disk = Storage::disk($organization->logo_disk ?? 'local');
        abort_unless($organization->logo_path && $disk->exists($organization->logo_path), 404);

        // Serve privately through the app, including on Docker installations
        // without a public storage symlink. Never reuse another tenant's logo.
        return $disk->response($organization->logo_path, null, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'logo' => ['required', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048', 'dimensions:max_width=2048,max_height=2048'],
        ]);

        $organization = $request->user()->organization;
        $oldPath = $organization->logo_path;
        $oldDisk = $organization->logo_disk ?? 'local';
        $disk = app(FileStorageService::class)->uploadsDisk();
        $path = $request->file('logo')->store("organizations/{$organization->id}/logos", ['disk' => $disk, 'visibility' => 'private']);
        abort_unless($path, 500, 'Unable to save the organization logo.');

        try {
            $organization->forceFill(['logo_path' => $path, 'logo_disk' => $disk])->save();
        } catch (Throwable $exception) {
            Storage::disk($disk)->delete($path);
            throw $exception;
        }

        if ($oldPath) {
            Storage::disk($oldDisk)->delete($oldPath);
        }

        return back()->with('success', 'Organization logo updated successfully.');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $organization = $request->user()->organization;
        $oldPath = $organization->logo_path;
        $oldDisk = $organization->logo_disk ?? 'local';
        $organization->forceFill(['logo_path' => null, 'logo_disk' => null])->save();

        if ($oldPath) {
            Storage::disk($oldDisk)->delete($oldPath);
        }

        return back()->with('success', 'Organization logo removed successfully.');
    }
}
