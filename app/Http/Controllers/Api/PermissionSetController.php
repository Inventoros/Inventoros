<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PermissionSet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PermissionSetController extends Controller
{
    /**
     * Display a listing of permission sets.
     */
    public function index(Request $request): JsonResponse
    {
        $organizationId = $request->user()->organization_id;

        $sets = PermissionSet::forOrganization($organizationId)
            ->active()
            ->when($request->input('category'), function ($query, $category) {
                $query->inCategory($category);
            })
            ->when($request->input('templates_only'), function ($query) {
                $query->templates();
            })
            ->orderBy('position')
            ->get();

        return response()->json([
            'data' => $sets->map(function ($set) {
                return [
                    'id' => $set->id,
                    'name' => $set->name,
                    'slug' => $set->slug,
                    'description' => $set->description,
                    'category' => $set->category,
                    'icon' => $set->icon,
                    'permissions' => $set->permissions,
                    'permission_count' => $set->permission_count,
                    'is_template' => $set->is_template,
                    'is_active' => $set->is_active,
                ];
            }),
        ]);
    }

    /**
     * Store a newly created permission set.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'category' => ['nullable', 'string', 'max:50'],
            'icon' => ['nullable', 'string', 'max:50'],
            'permissions' => ['required', 'array', 'min:1'],
            'permissions.*' => ['string'],
        ]);

        $validated['organization_id'] = $request->user()->organization_id;
        $validated['is_template'] = false;

        $set = PermissionSet::create($validated);

        return response()->json([
            'message' => 'Permission set created successfully',
            'data' => [
                'id' => $set->id,
                'name' => $set->name,
                'slug' => $set->slug,
                'description' => $set->description,
                'category' => $set->category,
                'permissions' => $set->permissions,
                'permission_count' => $set->permission_count,
            ],
        ], 201);
    }

    /**
     * Display the specified permission set.
     */
    public function show(Request $request, PermissionSet $permissionSet): JsonResponse
    {
        // Ensure user can view this set
        if (!$permissionSet->is_template &&
            $permissionSet->organization_id !== $request->user()->organization_id) {
            return response()->json(['message' => 'Permission set not found'], 404);
        }

        return response()->json([
            'data' => [
                'id' => $permissionSet->id,
                'name' => $permissionSet->name,
                'slug' => $permissionSet->slug,
                'description' => $permissionSet->description,
                'category' => $permissionSet->category,
                'icon' => $permissionSet->icon,
                'permissions' => $permissionSet->permissions,
                'permission_count' => $permissionSet->permission_count,
                'is_template' => $permissionSet->is_template,
                'is_active' => $permissionSet->is_active,
                'roles_count' => $permissionSet->roles()->count(),
            ],
        ]);
    }

    /**
     * Update the specified permission set.
     */
    public function update(Request $request, PermissionSet $permissionSet): JsonResponse
    {
        // Cannot edit templates or sets from other organizations
        if ($permissionSet->is_template ||
            $permissionSet->organization_id !== $request->user()->organization_id) {
            return response()->json(['message' => 'Cannot modify this permission set'], 403);
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'category' => ['nullable', 'string', 'max:50'],
            'icon' => ['nullable', 'string', 'max:50'],
            'permissions' => ['sometimes', 'array', 'min:1'],
            'permissions.*' => ['string'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $permissionSet->update($validated);

        return response()->json([
            'message' => 'Permission set updated successfully',
            'data' => [
                'id' => $permissionSet->id,
                'name' => $permissionSet->name,
                'slug' => $permissionSet->slug,
                'description' => $permissionSet->description,
                'permissions' => $permissionSet->permissions,
                'permission_count' => $permissionSet->permission_count,
            ],
        ]);
    }

    /**
     * Remove the specified permission set.
     */
    public function destroy(Request $request, PermissionSet $permissionSet): JsonResponse
    {
        // Cannot delete templates or sets from other organizations
        if ($permissionSet->is_template ||
            $permissionSet->organization_id !== $request->user()->organization_id) {
            return response()->json(['message' => 'Cannot delete this permission set'], 403);
        }

        // Check if any roles are using this set
        if ($permissionSet->roles()->count() > 0) {
            return response()->json([
                'message' => 'Cannot delete permission set: it is currently assigned to roles',
            ], 422);
        }

        $permissionSet->delete();

        return response()->json([
            'message' => 'Permission set deleted successfully',
        ]);
    }

    /**
     * Get available categories.
     */
    public function categories(): JsonResponse
    {
        return response()->json([
            'data' => [
                ['value' => 'inventory', 'label' => 'Inventory'],
                ['value' => 'orders', 'label' => 'Orders'],
                ['value' => 'purchasing', 'label' => 'Purchasing'],
                ['value' => 'admin', 'label' => 'Administration'],
                ['value' => 'reports', 'label' => 'Reports'],
            ],
        ]);
    }
}
