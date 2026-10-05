<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\User\StoreUserRequest;
use App\Http\Requests\Admin\User\UpdateUserRequest;
use App\Models\Role;
use App\Models\User;
use App\Services\UserManagementService;
use App\Support\RoleAssignmentGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Controller for managing users.
 *
 * Handles CRUD operations for users within an organization
 * including role assignments.
 */
class UserController extends Controller
{
    /**
     * Display a listing of users.
     *
     * @param  Request  $request  The incoming HTTP request
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
     *
     * @param  Request  $request  The incoming HTTP request
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
     *
     * @param  Request  $request  The incoming HTTP request containing user data
     */
    public function store(StoreUserRequest $request, UserManagementService $users): RedirectResponse
    {
        $users->create($request->user(), $request->validated());

        return redirect()->route('users.index')
            ->with('success', 'User created successfully.');
    }

    /**
     * Display the specified user.
     *
     * @param  Request  $request  The incoming HTTP request
     * @param  User  $user  The user to display
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
     *
     * @param  Request  $request  The incoming HTTP request
     * @param  User  $user  The user to edit
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
     *
     * @param  Request  $request  The incoming HTTP request containing updated user data
     * @param  User  $user  The user to update
     */
    public function update(UpdateUserRequest $request, User $user, UserManagementService $users): RedirectResponse
    {
        $currentUser = $request->user();

        // Ensure the user belongs to the same organization
        if ($user->organization_id !== $currentUser->organization_id) {
            abort(403, 'You can only update users in your organization.');
        }

        $users->update($currentUser, $user, $request->validated());

        return redirect()->route('users.index')
            ->with('success', 'User updated successfully.');
    }

    /**
     * Remove the specified user.
     *
     * @param  Request  $request  The incoming HTTP request
     * @param  User  $user  The user to delete
     */
    public function destroy(Request $request, User $user): RedirectResponse
    {
        $currentUser = $request->user();

        // Ensure the user belongs to the same organization
        if ($user->organization_id !== $currentUser->organization_id) {
            abort(403, 'You can only delete users in your organization.');
        }

        // A delegated user administrator may not delete someone more
        // privileged than themselves (for example an administrator), and
        // nobody deletes an account that works in organizations they do not
        // administer, or another organization's last administrator.
        RoleAssignmentGuard::authorizeDeletion($user, $currentUser);

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
