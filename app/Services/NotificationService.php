<?php

namespace App\Services;

use App\Models\Auth\User;
use App\Models\Inventory\Product;
use App\Models\Notification;
use App\Models\Order\Order;

class NotificationService
{
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
            ->where('id', '!=', $order->user_id) // Don't notify the creator
            ->get();

        foreach ($users as $user) {
            Notification::create([
                'organization_id' => $order->organization_id,
                'user_id' => $user->id,
                'type' => 'order_created',
                'title' => 'New Order Created',
                'message' => "Order #{$order->order_number} has been created by {$order->user->name}",
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
        // Notify the order creator
        Notification::create([
            'organization_id' => $order->organization_id,
            'user_id' => $order->user_id,
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
        // Notify the order creator
        Notification::create([
            'organization_id' => $order->organization_id,
            'user_id' => $order->user_id,
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
        // Notify the order creator
        Notification::create([
            'organization_id' => $order->organization_id,
            'user_id' => $order->user_id,
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
}
