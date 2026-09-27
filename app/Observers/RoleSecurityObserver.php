<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\SecurityEvent;
use App\Models\Role;
use App\Models\User;
use App\Services\SecurityEventLogger;

/**
 * Records role creation, permission changes, and deletion to the security
 * log. Only changes made by an authenticated actor are recorded, so seeders
 * and migrations that sync system roles stay quiet.
 */
final class RoleSecurityObserver
{
    public function __construct(private readonly SecurityEventLogger $logger) {}

    public function created(Role $role): void
    {
        $this->log(SecurityEvent::ROLE_CREATED, $role, [
            'role_id' => $role->id,
            'role_name' => $role->name,
            'permissions' => array_values($role->permissions ?? []),
        ]);
    }

    public function updated(Role $role): void
    {
        if (! $role->wasChanged(['permissions', 'name'])) {
            return;
        }

        $old = $this->decodePermissions($role->getOriginal('permissions'));
        $new = array_values($role->permissions ?? []);

        $this->log(SecurityEvent::ROLE_UPDATED, $role, [
            'role_id' => $role->id,
            'role_name' => $role->name,
            'permissions_added' => array_values(array_diff($new, $old)),
            'permissions_removed' => array_values(array_diff($old, $new)),
        ]);
    }

    public function deleted(Role $role): void
    {
        $this->log(SecurityEvent::ROLE_DELETED, $role, [
            'role_id' => $role->id,
            'role_name' => $role->name,
        ]);
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function log(SecurityEvent $event, Role $role, array $properties): void
    {
        $actor = auth()->user();
        if (! $actor instanceof User) {
            return;
        }

        $this->logger->record(
            $event,
            $role,
            $actor,
            $properties,
            $event->label().': '.$role->name,
            $role->organization_id ?? $actor->organization_id,
        );
    }

    /**
     * @return array<int, string>
     */
    private function decodePermissions(mixed $value): array
    {
        if (is_string($value)) {
            $value = json_decode($value, true);
        }

        return is_array($value) ? array_values($value) : [];
    }
}
