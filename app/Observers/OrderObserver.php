<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\OrderApprovalStatus;
use App\Enums\OrderStatus;
use App\Models\Order\Order;
use App\Services\NotificationService;
use Illuminate\Support\Facades\DB;

/**
 * Observer for Order model events.
 *
 * Monitors order lifecycle events and triggers appropriate
 * notifications for order creation and status changes.
 */
final class OrderObserver
{
    /**
     * Handle the Order "created" event.
     */
    public function created(Order $order): void
    {
        NotificationService::createOrderCreatedNotification($order);
    }

    /**
     * Handle the Order "updated" event.
     * Check for status changes.
     */
    public function updated(Order $order): void
    {
        // Every update is an order.updated webhook event. Fire it post-commit
        // so subscribers never observe a change that was rolled back.
        DB::afterCommit(fn () => do_action('order_updated', $order, auth()->user()));

        // Only check if status changed
        if ($order->isDirty('status')) {
            $oldStatus = $order->getOriginal('status');
            $newStatus = $order->status;

            // Create appropriate notification based on new status
            if ($newStatus === OrderStatus::SHIPPED) {
                NotificationService::createOrderShippedNotification($order);
            } elseif ($newStatus === OrderStatus::DELIVERED) {
                NotificationService::createOrderDeliveredNotification($order);
            } else {
                NotificationService::createOrderStatusNotification($order, $oldStatus);
            }

            // The status webhook payload carries plain string from/to values.
            // getOriginal() applies the cast, so $oldStatus may be an enum.
            $oldVal = $oldStatus instanceof OrderStatus ? $oldStatus->value : (string) $oldStatus;
            $newVal = $newStatus instanceof OrderStatus ? $newStatus->value : (string) $newStatus;
            DB::afterCommit(fn () => do_action('order_status_changed', $order, $oldVal, $newVal, auth()->user()));
        }

        // Approval transitions get their own dedicated webhook events.
        if ($order->isDirty('approval_status')) {
            $approval = $order->approval_status;

            if ($approval === OrderApprovalStatus::APPROVED) {
                DB::afterCommit(fn () => do_action('order_approved', $order, auth()->user()));
            } elseif ($approval === OrderApprovalStatus::REJECTED) {
                DB::afterCommit(fn () => do_action('order_rejected', $order, auth()->user()));
            }
        }
    }
}
