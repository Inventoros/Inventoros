<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    /**
     * Display a listing of users.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();

        $users = User::with(['roles'])
            ->forOrganization($user->organization_id)
            ->when($request->input('search'), function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->when($request->input('role'), function ($query, $role) {
                $query->where('role', $role);
            })
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        // Get both organization-specific roles and system roles for filtering
        $roles = Role::where(function ($query) use ($user) {
            $query->where('organization_id', $user->organization_id)
                  ->orWhereNull('organization_id');
        })->orderByRaw('is_system DESC, name ASC')->get();

        return Inertia::render('Admin/Users/Index', [
            'users' => $users,
            'roles' => $roles,
            'filters' => $request->only(['search', 'role']),
        ]);
    }

    /**
     * Show the form for creating a new user.
     */
    public function create(Request $request): Response
    {
        $user = $request->user();

        // Get only organization-specific custom roles (exclude system roles)
        $roles = Role::where('organization_id', $user->organization_id)
            ->where('is_system', false)
            ->orderBy('name', 'ASC')
            ->get();

        return Inertia::render('Admin/Users/Create', [
            'roles' => $roles,
        ]);
    }

    /**
     * Store a newly created user.
     */
    public function store(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email',
            'password' => ['required', 'confirmed', Password::defaults()],
            'role' => 'required|in:admin,manager,member',
            'role_ids' => 'nullable|array',
            'role_ids.*' => 'exists:roles,id',
        ]);

        $newUser = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'organization_id' => $user->organization_id,
            'role' => $validated['role'],
        ]);

        // Assign additional custom roles if provided
        if (!empty($validated['role_ids'])) {
            $newUser->roles()->sync($validated['role_ids']);
        }

        return redirect()->route('users.index')
            ->with('success', 'User created successfully.');
    }

    /**
     * Display the specified user.
     */
    public function show(Request $request, User $user): Response
    {
        $currentUser = $request->user();

        // Ensure the user belongs to the same organization
        if ($user->organization_id !== $currentUser->organization_id) {
            abort(403, 'You can only view users in your organization.');
        }

        $user->load(['roles', 'organization']);

        return Inertia::render('Admin/Users/Show', [
            'user' => $user,
        ]);
    }

    /**
     * Show the form for editing the specified user.
     */
    public function edit(Request $request, User $user): Response
    {
        $currentUser = $request->user();

        // Ensure the user belongs to the same organization
        if ($user->organization_id !== $currentUser->organization_id) {
            abort(403, 'You can only edit users in your organization.');
        }

        $user->load('roles');

        // Get only organization-specific custom roles (exclude system roles)
        $roles = Role::where('organization_id', $currentUser->organization_id)
            ->where('is_system', false)
            ->orderBy('name', 'ASC')
            ->get();

        return Inertia::render('Admin/Users/Edit', [
            'user' => $user,
            'roles' => $roles,
        ]);
    }

    /**
     * Update the specified user.
     */
    public function update(Request $request, User $user)
    {
        $currentUser = $request->user();

        // Ensure the user belongs to the same organization
        if ($user->organization_id !== $currentUser->organization_id) {
            abort(403, 'You can only update users in your organization.');
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email,' . $user->id,
            'password' => ['nullable', 'confirmed', Password::defaults()],
            'role' => 'required|in:admin,manager,member',
            'role_ids' => 'nullable|array',
            'role_ids.*' => 'exists:roles,id',
        ]);

        // Don't allow removing admin from the last admin
        if ($validated['role'] !== 'admin' && $user->role === 'admin') {
            $adminCount = User::where('organization_id', $currentUser->organization_id)
                ->where('role', 'admin')
                ->count();

            if ($adminCount <= 1) {
                return redirect()->back()
                    ->withErrors(['role' => 'Cannot remove admin role from the last administrator.']);
            }
        }

        $updateData = [
            'name' => $validated['name'],
            'email' => $validated['email'],
            'role' => $validated['role'],
        ];

        if (!empty($validated['password'])) {
            $updateData['password'] = Hash::make($validated['password']);
        }

        $user->update($updateData);

        // Sync roles if provided
        if (isset($validated['role_ids'])) {
            $user->roles()->sync($validated['role_ids']);
        }

        return redirect()->route('users.index')
            ->with('success', 'User updated successfully.');
    }

    /**
     * Remove the specified user.
     */
    public function destroy(Request $request, User $user)
    {
        $currentUser = $request->user();

        // Ensure the user belongs to the same organization
        if ($user->organization_id !== $currentUser->organization_id) {
            abort(403, 'You can only delete users in your organization.');
        }

        // Don't allow deleting yourself
        if ($user->id === $currentUser->id) {
            return redirect()->back()
                ->withErrors(['user' => 'You cannot delete your own account.']);
        }

        // Don't allow deleting the last admin
        if ($user->role === 'admin') {
            $adminCount = User::where('organization_id', $currentUser->organization_id)
                ->where('role', 'admin')
                ->count();

            if ($adminCount <= 1) {
                return redirect()->back()
                    ->withErrors(['user' => 'Cannot delete the last administrator.']);
            }
        }

        $user->delete();

        return redirect()->route('users.index')
            ->with('success', 'User deleted successfully.');
    }
}
