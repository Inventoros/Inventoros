<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\SecurityEvent;
use App\Models\Role;
use App\Models\User;
use App\Support\RoleAssignmentGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Creates and updates organization users behind the privilege-escalation
 * guards. Shared by the web Admin\UserController and the REST API so a
 * delegated user manager cannot mint an admin through either surface.
 */
final class UserManagementService
{
    /**
     * Create a user in the actor's organization.
     *
     * @param  array{name: string, email: string, password: string, role: string, role_ids?: array<int, int|string>|null}  $data
     */
    public function create(User $actor, array $data): User
    {
        $this->assertCanAssignRoles($data['role_ids'] ?? [], $actor, $data['role']);

        return DB::transaction(function () use ($actor, $data) {
            $newUser = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
                'organization_id' => $actor->organization_id,
                'role' => $data['role'],
            ]);

            // Assign additional custom roles if provided
            if (! empty($data['role_ids'])) {
                $this->logRoleSync($newUser, $actor, $newUser->roles()->sync($data['role_ids']));
            }

            return $newUser;
        });
    }

    /**
     * Update a user in the actor's organization.
     *
     * @param  array{name: string, email: string, password?: string|null, role: string, role_ids?: array<int, int|string>|null}  $data
     *
     * @throws ValidationException when demoting the last administrator
     */
    public function update(User $actor, User $user, array $data): User
    {
        $this->assertCanAssignRoles($data['role_ids'] ?? [], $actor, $data['role']);

        // Don't allow removing admin from the last admin
        if ($data['role'] !== 'admin' && $user->role === 'admin') {
            $adminCount = User::where('organization_id', $actor->organization_id)
                ->where('role', 'admin')
                ->count();

            if ($adminCount <= 1) {
                throw ValidationException::withMessages([
                    'role' => 'Cannot remove admin role from the last administrator.',
                ]);
            }
        }

        $updateData = [
            'name' => $data['name'],
            'email' => $data['email'],
            'role' => $data['role'],
        ];

        if (! empty($data['password'])) {
            $updateData['password'] = Hash::make($data['password']);
        }

        DB::transaction(function () use ($user, $actor, $updateData, $data) {
            $user->update($updateData);

            // Sync roles if provided
            if (isset($data['role_ids'])) {
                $this->logRoleSync($user, $actor, $user->roles()->sync($data['role_ids']));
            }
        });

        return $user;
    }

    /**
     * Record custom role assignments to the security log. `roles()->sync()`
     * writes the pivot directly, so no model event reports it.
     *
     * @param  array{attached: array<int, int>, detached: array<int, int>, updated: array<int, int>}  $changes
     */
    private function logRoleSync(User $user, User $actor, array $changes): void
    {
        if ($changes['attached'] === [] && $changes['detached'] === []) {
            return;
        }

        $names = Role::whereIn('id', array_merge($changes['attached'], $changes['detached']))->pluck('name', 'id');

        app(SecurityEventLogger::class)->record(SecurityEvent::USER_ROLES_SYNCED, $user, $actor, [
            'roles_added' => array_values(array_map(fn ($id) => $names[$id] ?? $id, $changes['attached'])),
            'roles_removed' => array_values(array_map(fn ($id) => $names[$id] ?? $id, $changes['detached'])),
        ]);
    }

    /**
     * Prevent privilege escalation through role assignment. The rules live in
     * RoleAssignmentGuard, which the user CSV import also uses.
     *
     * @param  array<int|string>  $roleIds
     */
    public function assertCanAssignRoles(array $roleIds, User $actor, ?string $baseRole = null): void
    {
        RoleAssignmentGuard::authorize($roleIds, $actor, $baseRole);
    }
}
