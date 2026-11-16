<?php

declare(strict_types=1);

namespace App\Support;

use App\Exceptions\InvalidStateException;
use App\Models\Order\Order;
use App\Models\User;

/**
 * Who may approve an order: nobody approves an order they created, except an
 * admin when the organization's approvals setting allows admins to
 * self-approve. The same rule purchase orders and stock requests follow in
 * ApprovalService.
 *
 * Whoever sent an approved order back for approval by changing its terms
 * (approval_requested_by) is its requester for the new decision: they may
 * neither approve nor reject it, under the same admin exception.
 */
final class OrderApprovalGate
{
    /**
     * @throws InvalidStateException (self_approval)
     */
    public static function assertMayApprove(Order $order, User $approver): void
    {
        self::assertNotTheReapprovalRequester($order, $approver);

        if ($order->created_by === null || (int) $order->created_by !== (int) $approver->id) {
            return;
        }

        if (self::adminMaySelfApprove($order, $approver)) {
            return;
        }

        throw new InvalidStateException('You cannot approve an order you created.', 'self_approval');
    }

    /**
     * @throws InvalidStateException (self_approval)
     */
    public static function assertMayReject(Order $order, User $approver): void
    {
        self::assertNotTheReapprovalRequester($order, $approver);
    }

    private static function assertNotTheReapprovalRequester(Order $order, User $approver): void
    {
        if ($order->approval_requested_by === null || (int) $order->approval_requested_by !== (int) $approver->id) {
            return;
        }

        if (self::adminMaySelfApprove($order, $approver)) {
            return;
        }

        throw new InvalidStateException('You changed this order after it was approved, so someone else has to decide it.', 'self_approval');
    }

    private static function adminMaySelfApprove(Order $order, User $approver): bool
    {
        return $approver->isAdmin() && ApprovalSettings::forOrganization((int) $order->organization_id)->adminsCanSelfApprove;
    }
}
