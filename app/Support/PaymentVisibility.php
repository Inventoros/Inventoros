<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\Permission;
use App\Http\Middleware\CheckApiPermission;
use App\Models\Order\Order;
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

    /**
     * Order attributes and relations that are payment data. OrderResource
     * leaves them out for users without view_payments; pages that pass an
     * Order model straight through must hide them the same way.
     */
    public const ORDER_FIELDS = ['amount_paid', 'payment_status', 'payments'];

    /**
     * Hide the payment fields on raw Order models (one, a collection, or
     * null) unless the user may see payments.
     *
     * @param  Order|iterable<Order>|null  $orders
     */
    public static function hideOnOrders(?User $user, Order|iterable|null $orders): void
    {
        if ($orders === null || self::allows($user)) {
            return;
        }

        foreach ($orders instanceof Order ? [$orders] : $orders as $order) {
            $order?->makeHidden(self::ORDER_FIELDS);
        }
    }
}
