<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\Permission;
use App\Http\Middleware\CheckApiPermission;
use App\Models\User;

/**
 * Whether the acting user may see shipments embedded in an order payload.
 *
 * Same gate as a `view_shipments` route: the user must hold the permission
 * AND the acting token's declared abilities must allow it. Session requests
 * are governed by the user's permissions alone.
 */
final class ShipmentVisibility
{
    public static function allows(?User $user): bool
    {
        if (! $user instanceof User || ! $user->hasPermission(Permission::VIEW_SHIPMENTS)) {
            return false;
        }

        return CheckApiPermission::tokenAllows($user->currentAccessToken())(Permission::VIEW_SHIPMENTS->value);
    }
}
