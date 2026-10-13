<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Auth\Organization;
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
        abort_unless($organization->logo_path && Storage::disk('local')->exists($organization->logo_path), 404);

        // Serve privately through the app, including on Docker installations
        // without a public storage symlink. Never reuse another tenant's logo.
        return Storage::disk('local')->response($organization->logo_path, null, [
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
        $path = $request->file('logo')->store("organizations/{$organization->id}/logos", 'local');
        abort_unless($path, 500, 'Unable to save the organization logo.');

        try {
            $organization->forceFill(['logo_path' => $path])->save();
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }

        if ($oldPath) {
            Storage::disk('local')->delete($oldPath);
        }

        return back()->with('success', 'Organization logo updated successfully.');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $organization = $request->user()->organization;
        $oldPath = $organization->logo_path;
        $organization->forceFill(['logo_path' => null])->save();

        if ($oldPath) {
            Storage::disk('local')->delete($oldPath);
        }

        return back()->with('success', 'Organization logo removed successfully.');
    }
}
