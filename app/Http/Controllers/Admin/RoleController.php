<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\PermissionSet;
use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class RoleController extends Controller
{
    /**
     * Display a listing of roles.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();

        // Get organization-specific roles and system roles
        $roles = Role::with(['users'])
            ->where(function ($query) use ($user) {
                $query->where('organization_id', $user->organization_id)
                      ->orWhereNull('organization_id'); // Include system roles
            })
            ->when($request->input('search'), function ($query, $search) {
                $query->where('name', 'like', "%{$search}%");
            })
            ->orderByRaw('is_system DESC, name ASC') // System roles first, then alphabetical
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Admin/Roles/Index', [
            'roles' => $roles,
            'filters' => $request->only(['search']),
        ]);
    }

    /**
     * Show the form for creating a new role.
     */
    public function create(Request $request): Response
    {
        $permissions = Permission::grouped();
        $organizationId = $request->user()->organization_id;

        // Get available permission sets (templates + organization-specific)
        $permissionSets = PermissionSet::forOrganization($organizationId)
            ->active()
            ->orderBy('position')
            ->get()
            ->map(fn($set) => [
                'id' => $set->id,
                'name' => $set->name,
                'description' => $set->description,
                'category' => $set->category,
                'icon' => $set->icon,
                'permissions' => $set->permissions,
                'permission_count' => $set->permission_count,
                'is_template' => $set->is_template,
            ]);

        return Inertia::render('Admin/Roles/Create', [
            'permissions' => $permissions,
            'permissionSets' => $permissionSets,
        ]);
    }

    /**
     * Store a newly created role.
     */
    public function store(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'permissions' => 'nullable|array',
            'permissions.*' => 'string',
            'permission_set_ids' => 'nullable|array',
            'permission_set_ids.*' => 'integer|exists:permission_sets,id',
        ]);

        // Generate slug from name
        $slug = Str::slug($validated['name']);

        // Ensure slug is unique for this organization
        $originalSlug = $slug;
        $counter = 1;
        while (Role::where('slug', $slug)->where('organization_id', $user->organization_id)->exists()) {
            $slug = $originalSlug . '-' . $counter;
            $counter++;
        }

        $role = Role::create([
            'name' => $validated['name'],
            'slug' => $slug,
            'description' => $validated['description'] ?? null,
            'permissions' => $validated['permissions'] ?? [],
            'organization_id' => $user->organization_id,
            'is_system' => false,
        ]);

        // Attach permission sets if provided
        if (!empty($validated['permission_set_ids'])) {
            $role->permissionSets()->sync($validated['permission_set_ids']);
        }

        return redirect()->route('roles.index')
            ->with('success', 'Role created successfully.');
    }

    /**
     * Display the specified role.
     */
    public function show(Request $request, Role $role): Response
    {
        $currentUser = $request->user();

        // Allow viewing system roles or roles in the same organization
        if ($role->organization_id && $role->organization_id !== $currentUser->organization_id) {
            abort(403, 'You can only view roles in your organization.');
        }

        $role->load(['users', 'permissionSets']);

        // Get permission details (direct permissions)
        $rolePermissions = [];
        foreach ($role->permissions ?? [] as $permissionValue) {
            try {
                $permission = Permission::from($permissionValue);
                $rolePermissions[] = [
                    'value' => $permission->value,
                    'label' => $permission->label(),
                    'description' => $permission->description(),
                    'category' => $permission->category(),
                ];
            } catch (\ValueError $e) {
                // Skip invalid permissions
                continue;
            }
        }

        // Get all effective permissions (including from sets)
        $allPermissions = $role->getAllPermissions();

        return Inertia::render('Admin/Roles/Show', [
            'role' => $role,
            'rolePermissions' => $rolePermissions,
            'allPermissions' => $allPermissions,
            'permissionSets' => $role->permissionSets->map(fn($set) => [
                'id' => $set->id,
                'name' => $set->name,
                'description' => $set->description,
                'category' => $set->category,
                'permission_count' => $set->permission_count,
            ]),
        ]);
    }

    /**
     * Show the form for editing the specified role.
     */
    public function edit(Request $request, Role $role): Response
    {
        $currentUser = $request->user();

        // Allow editing system roles or organization roles
        if ($role->organization_id && $role->organization_id !== $currentUser->organization_id) {
            abort(403, 'You can only edit roles in your organization.');
        }

        // Don't allow editing Administrator role (system-administrator slug)
        if ($role->slug === 'system-administrator') {
            return redirect()->route('roles.index')
                ->withErrors(['role' => 'The Administrator role cannot be edited.']);
        }

        $permissions = Permission::grouped();
        $organizationId = $request->user()->organization_id;

        // Load permission sets for the role
        $role->load('permissionSets');

        // Get available permission sets
        $permissionSets = PermissionSet::forOrganization($organizationId)
            ->active()
            ->orderBy('position')
            ->get()
            ->map(fn($set) => [
                'id' => $set->id,
                'name' => $set->name,
                'description' => $set->description,
                'category' => $set->category,
                'icon' => $set->icon,
                'permissions' => $set->permissions,
                'permission_count' => $set->permission_count,
                'is_template' => $set->is_template,
            ]);

        return Inertia::render('Admin/Roles/Edit', [
            'role' => $role,
            'permissions' => $permissions,
            'permissionSets' => $permissionSets,
            'selectedSetIds' => $role->permissionSets->pluck('id')->toArray(),
        ]);
    }

    /**
     * Update the specified role.
     */
    public function update(Request $request, Role $role)
    {
        $currentUser = $request->user();

        // Allow updating system roles or organization roles
        if ($role->organization_id && $role->organization_id !== $currentUser->organization_id) {
            abort(403, 'You can only update roles in your organization.');
        }

        // Don't allow editing Administrator role
        if ($role->slug === 'system-administrator') {
            return redirect()->back()
                ->withErrors(['role' => 'The Administrator role cannot be edited.']);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'permissions' => 'nullable|array',
            'permissions.*' => 'string',
            'permission_set_ids' => 'nullable|array',
            'permission_set_ids.*' => 'integer|exists:permission_sets,id',
        ]);

        // Update slug if name changed
        if ($validated['name'] !== $role->name) {
            $slug = Str::slug($validated['name']);

            // Ensure slug is unique for this organization (excluding current role)
            $originalSlug = $slug;
            $counter = 1;
            while (Role::where('slug', $slug)
                ->where('organization_id', $currentUser->organization_id)
                ->where('id', '!=', $role->id)
                ->exists()) {
                $slug = $originalSlug . '-' . $counter;
                $counter++;
            }

            $validated['slug'] = $slug;
        }

        $role->update([
            'name' => $validated['name'],
            'slug' => $validated['slug'] ?? $role->slug,
            'description' => $validated['description'] ?? null,
            'permissions' => $validated['permissions'] ?? [],
        ]);

        // Sync permission sets
        $role->permissionSets()->sync($validated['permission_set_ids'] ?? []);

        return redirect()->route('roles.index')
            ->with('success', 'Role updated successfully.');
    }

    /**
     * Remove the specified role.
     */
    public function destroy(Request $request, Role $role)
    {
        $currentUser = $request->user();

        // Ensure the role belongs to the same organization
        if ($role->organization_id !== $currentUser->organization_id) {
            abort(403, 'You can only delete roles in your organization.');
        }

        // Don't allow deleting system roles
        if ($role->is_system) {
            return redirect()->back()
                ->withErrors(['role' => 'System roles cannot be deleted.']);
        }

        // Check if role is assigned to any users
        if ($role->users()->count() > 0) {
            return redirect()->back()
                ->withErrors(['role' => 'Cannot delete a role that is assigned to users. Please reassign users first.']);
        }

        $role->delete();

        return redirect()->route('roles.index')
            ->with('success', 'Role deleted successfully.');
    }
}
