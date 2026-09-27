<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\Permission;
use App\Http\Middleware\CheckApiPermission;
use App\Models\User;

/**
 * Whether the acting user may see payment data (amount paid, balance due,
 * payment status, the payment rows) embedded in an order payload.
 *
 * Same gate as a `view_payments` route: the user must hold the permission
 * AND the acting token's declared abilities must allow it, so a token scoped
 * to `view_orders` does not reveal payments even when its owner could see
 * them in the browser. Session requests (no personal access token) are
 * governed by the user's permissions alone.
 */
final class PaymentVisibility
{
    public static function allows(?User $user): bool
    {
        if (! $user instanceof User || ! $user->hasPermission(Permission::VIEW_PAYMENTS)) {
            return false;
        }

        return CheckApiPermission::tokenAllows($user->currentAccessToken())(Permission::VIEW_PAYMENTS->value);
    }
}
