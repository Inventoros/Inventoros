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
 */
final class OrderApprovalGate
{
    /**
     * @throws InvalidStateException (self_approval)
     */
    public static function assertMayApprove(Order $order, User $approver): void
    {
        if ($order->created_by === null || (int) $order->created_by !== (int) $approver->id) {
            return;
        }

        if ($approver->isAdmin() && ApprovalSettings::forOrganization((int) $order->organization_id)->adminsCanSelfApprove) {
            return;
        }

        throw new InvalidStateException('You cannot approve an order you created.', 'self_approval');
    }
}
