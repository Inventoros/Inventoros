<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
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
    public function create(): Response
    {
        $permissions = Permission::grouped();

        return Inertia::render('Admin/Roles/Create', [
            'permissions' => $permissions,
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

        $role->load(['users']);

        // Get permission details
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

        return Inertia::render('Admin/Roles/Show', [
            'role' => $role,
            'rolePermissions' => $rolePermissions,
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

        return Inertia::render('Admin/Roles/Edit', [
            'role' => $role,
            'permissions' => $permissions,
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
