<?php

namespace App\Services;

use App\Models\User;
use App\Models\Inventory\Product;
use App\Models\Notification;
use App\Models\Order\Order;

class NotificationService
{
    /**
     * Check if user wants to receive a specific notification type.
     */
    private static function shouldNotifyUser(User $user, string $notificationType): bool
    {
        $preferences = $user->notification_preferences ?? [];

        // Map notification types to preference keys
        $preferenceMap = [
            'low_stock' => 'low_stock_alerts',
            'out_of_stock' => 'low_stock_alerts',
            'order_created' => 'order_notifications',
            'order_status_updated' => 'order_notifications',
            'order_shipped' => 'order_notifications',
            'order_delivered' => 'order_notifications',
        ];

        $preferenceKey = $preferenceMap[$notificationType] ?? 'system_notifications';

        // Default to true if preference not set
        return $preferences[$preferenceKey] ?? true;
    }

    /**
     * Create a low stock notification for all users with appropriate permissions.
     */
    public static function createLowStockNotification(Product $product): void
    {
        // Get all users in the organization with manage_stock permission
        $users = User::where('organization_id', $product->organization_id)
            ->whereHas('role', function ($query) {
                $query->whereHas('permissions', function ($q) {
                    $q->where('name', 'manage_stock');
                });
            })
            ->get();

        foreach ($users as $user) {
            // Check if user wants low stock alerts
            if (!self::shouldNotifyUser($user, 'low_stock')) {
                continue;
            }

            Notification::create([
                'organization_id' => $product->organization_id,
                'user_id' => $user->id,
                'type' => 'low_stock',
                'title' => 'Low Stock Alert',
                'message' => "Product '{$product->name}' (SKU: {$product->sku}) is running low. Current stock: {$product->stock}, Minimum: {$product->min_stock}",
                'data' => [
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'sku' => $product->sku,
                    'current_stock' => $product->stock,
                    'min_stock' => $product->min_stock,
                ],
                'action_url' => route('products.show', $product->id),
                'priority' => $product->stock == 0 ? 'urgent' : 'high',
            ]);
        }
    }

    /**
     * Create an out of stock notification.
     */
    public static function createOutOfStockNotification(Product $product): void
    {
        // Get all users in the organization with manage_stock permission
        $users = User::where('organization_id', $product->organization_id)
            ->whereHas('role', function ($query) {
                $query->whereHas('permissions', function ($q) {
                    $q->where('name', 'manage_stock');
                });
            })
            ->get();

        foreach ($users as $user) {
            // Check if user wants low stock alerts
            if (!self::shouldNotifyUser($user, 'out_of_stock')) {
                continue;
            }

            Notification::create([
                'organization_id' => $product->organization_id,
                'user_id' => $user->id,
                'type' => 'out_of_stock',
                'title' => 'Out of Stock Alert',
                'message' => "Product '{$product->name}' (SKU: {$product->sku}) is now out of stock!",
                'data' => [
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'sku' => $product->sku,
                ],
                'action_url' => route('products.show', $product->id),
                'priority' => 'urgent',
            ]);
        }
    }

    /**
     * Create an order created notification.
     */
    public static function createOrderCreatedNotification(Order $order): void
    {
        // Get all users in the organization with view_orders permission
        $users = User::where('organization_id', $order->organization_id)
            ->whereHas('role', function ($query) {
                $query->whereHas('permissions', function ($q) {
                    $q->where('name', 'view_orders');
                });
            })
            ->where('id', '!=', $order->created_by) // Don't notify the creator
            ->get();

        $creatorName = $order->creator ? $order->creator->name : 'Unknown';

        foreach ($users as $user) {
            // Check if user wants order notifications
            if (!self::shouldNotifyUser($user, 'order_created')) {
                continue;
            }

            Notification::create([
                'organization_id' => $order->organization_id,
                'user_id' => $user->id,
                'type' => 'order_created',
                'title' => 'New Order Created',
                'message' => "Order #{$order->order_number} has been created by {$creatorName}",
                'data' => [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'customer_name' => $order->customer_name,
                    'total' => $order->total,
                ],
                'action_url' => route('orders.show', $order->id),
                'priority' => 'normal',
            ]);
        }
    }

    /**
     * Create an order status updated notification.
     */
    public static function createOrderStatusNotification(Order $order, string $oldStatus): void
    {
        // Get the user to check preferences
        $user = User::find($order->created_by);
        if (!$user || !self::shouldNotifyUser($user, 'order_status_updated')) {
            return;
        }

        // Notify the order creator
        Notification::create([
            'organization_id' => $order->organization_id,
            'user_id' => $order->created_by,
            'type' => 'order_status_updated',
            'title' => 'Order Status Updated',
            'message' => "Order #{$order->order_number} status changed from {$oldStatus} to {$order->status}",
            'data' => [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'old_status' => $oldStatus,
                'new_status' => $order->status,
            ],
            'action_url' => route('orders.show', $order->id),
            'priority' => $order->status === 'cancelled' ? 'high' : 'normal',
        ]);
    }

    /**
     * Create an order shipped notification.
     */
    public static function createOrderShippedNotification(Order $order): void
    {
        // Get the user to check preferences
        $user = User::find($order->created_by);
        if (!$user || !self::shouldNotifyUser($user, 'order_shipped')) {
            return;
        }

        // Notify the order creator
        Notification::create([
            'organization_id' => $order->organization_id,
            'user_id' => $order->created_by,
            'type' => 'order_shipped',
            'title' => 'Order Shipped',
            'message' => "Order #{$order->order_number} has been shipped to {$order->customer_name}",
            'data' => [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'customer_name' => $order->customer_name,
            ],
            'action_url' => route('orders.show', $order->id),
            'priority' => 'normal',
        ]);
    }

    /**
     * Create an order delivered notification.
     */
    public static function createOrderDeliveredNotification(Order $order): void
    {
        // Get the user to check preferences
        $user = User::find($order->created_by);
        if (!$user || !self::shouldNotifyUser($user, 'order_delivered')) {
            return;
        }

        // Notify the order creator
        Notification::create([
            'organization_id' => $order->organization_id,
            'user_id' => $order->created_by,
            'type' => 'order_delivered',
            'title' => 'Order Delivered',
            'message' => "Order #{$order->order_number} has been delivered successfully",
            'data' => [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
            ],
            'action_url' => route('orders.show', $order->id),
            'priority' => 'normal',
        ]);
    }

    /**
     * Create an order approval notification.
     */
    public static function createOrderApprovalNotification(Order $order): void
    {
        // Get the user to check preferences
        $user = User::find($order->created_by);
        if (!$user) {
            return;
        }

        $status = $order->approval_status;
        $approverName = $order->approver ? $order->approver->name : 'Unknown';

        // Notify the order creator
        Notification::create([
            'organization_id' => $order->organization_id,
            'user_id' => $order->created_by,
            'type' => 'order_' . $status,
            'title' => 'Order ' . ucfirst($status),
            'message' => "Order #{$order->order_number} has been {$status} by {$approverName}",
            'data' => [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'status' => $status,
                'notes' => $order->approval_notes,
                'approver' => $approverName,
            ],
            'action_url' => route('orders.show', $order->id),
            'priority' => $status === 'rejected' ? 'high' : 'normal',
        ]);
    }
}
