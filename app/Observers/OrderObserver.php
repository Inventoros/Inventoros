<?php

namespace App\Observers;

use App\Models\Order\Order;
use App\Services\NotificationService;

class OrderObserver
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
        // Only check if status changed
        if ($order->isDirty('status')) {
            $oldStatus = $order->getOriginal('status');
            $newStatus = $order->status;

            // Create appropriate notification based on new status
            if ($newStatus === 'shipped') {
                NotificationService::createOrderShippedNotification($order);
            } elseif ($newStatus === 'delivered') {
                NotificationService::createOrderDeliveredNotification($order);
            } else {
                NotificationService::createOrderStatusNotification($order, $oldStatus);
            }
        }
    }
}
