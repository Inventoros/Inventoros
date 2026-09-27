<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\SecurityEvent;
use App\Models\User;
use App\Services\SecurityEventLogger;
use App\Services\UserActivityAlertService;

/**
 * Records account creation and base-role changes to the security log and
 * triggers the matching admin alerts.
 *
 * Covers every write path (admin users page, organization settings, API,
 * GraphQL) because it listens on the model rather than a controller. Only
 * changes made by an authenticated actor are recorded: installer seeding and
 * self-registration create the organization's first account, which nobody
 * else needs to be told about.
 */
final class UserSecurityObserver
{
    public function __construct(
        private readonly SecurityEventLogger $logger,
        private readonly UserActivityAlertService $alerts,
    ) {}

    public function created(User $user): void
    {
        $actor = auth()->user();
        if (! $actor instanceof User || ! $user->organization_id) {
            return;
        }

        $this->logger->record(SecurityEvent::USER_CREATED, $user, $actor, [
            'email' => $user->email,
            'role' => $user->role,
        ]);

        $this->alerts->notifyUserCreated($user, $actor);
    }

    public function updated(User $user): void
    {
        if (! $user->wasChanged('role')) {
            return;
        }

        $actor = auth()->user();
        if (! $actor instanceof User) {
            return;
        }

        $oldRole = $user->getOriginal('role');
        $newRole = $user->role;

        $this->logger->record(SecurityEvent::USER_ROLE_CHANGED, $user, $actor, [
            'old_role' => $oldRole,
            'new_role' => $newRole,
        ]);

        if ($newRole === 'admin' && $oldRole !== 'admin') {
            $this->alerts->notifyPromotedToAdmin($user, $actor);
        }
    }
}
