<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\OrderApprovalStatus;
use App\Enums\OrderStatus;
use App\Mail\LowStockEmail;
use App\Mail\OrderApprovalEmail;
use App\Mail\OrderStatusEmail;
use App\Models\DataExport;
use App\Models\Inventory\Product;
use App\Models\Notification;
use App\Models\Order\Order;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Service for creating and sending notifications.
 *
 * Handles in-app notifications and email notifications for various
 * events like low stock, order status changes, and approvals.
 */
final class NotificationService
{
    /**
     * Check if user wants to receive a specific notification type.
     *
     * @param  User  $user  The user to check
     * @param  string  $notificationType  The notification type to check
     * @return bool True if user should receive this notification type
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
     * Send email notification to user.
     *
     * Applies organization email config and respects user preferences.
     * Triggers hooks for customization.
     *
     * @param  User  $user  The user to notify
     * @param  string  $type  The notification type
     * @param  array  $data  Data to pass to the mailable
     */
    private static function sendEmailNotification(User $user, string $type, array $data): void
    {
        // Check if user has email enabled
        $preferences = $user->notification_preferences ?? [];
        if (! ($preferences['email_enabled'] ?? true)) {
            return;
        }

        // Check specific email preference
        $emailPreferenceMap = [
            'low_stock' => 'email_low_stock',
            'out_of_stock' => 'email_low_stock',
            'order_created' => 'email_orders',
            'order_status_updated' => 'email_orders',
            'order_approved' => 'email_approvals',
            'order_rejected' => 'email_approvals',
        ];

        $prefKey = $emailPreferenceMap[$type] ?? null;
        if ($prefKey && ! ($preferences[$prefKey] ?? true)) {
            return;
        }

        // Apply organization's email configuration
        SettingsService::applyEmailConfig();

        // HOOK: Allow plugins to modify email data
        $data = apply_filters('email_notification_data', $data, $type, $user);

        // HOOK: Allow plugins to prevent sending
        if (! apply_filters('should_send_email', true, $type, $user, $data)) {
            return;
        }

        // Validate email address before sending
        if (empty($user->email) || ! filter_var($user->email, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        try {
            // HOOK: Allow plugins to provide custom mailable
            $mailableClass = apply_filters('email_mailable_class', null, $type, $data);

            if ($mailableClass) {
                Mail::to($user->email)->send(new $mailableClass($data));
            } else {
                // Use default email types
                switch ($type) {
                    case 'low_stock':
                    case 'out_of_stock':
                        Mail::to($user->email)->send(new LowStockEmail($data));
                        break;
                    case 'order_status_updated':
                        Mail::to($user->email)->send(new OrderStatusEmail($data));
                        break;
                    case 'order_approved':
                    case 'order_rejected':
                        Mail::to($user->email)->send(new OrderApprovalEmail($data));
                        break;
                    default:
                        Log::warning('No email mailable configured for notification type', [
                            'type' => $type,
                            'user_id' => $user->id,
                        ]);

                        return;
                }
            }

            // HOOK: After email sent
            do_action('email_notification_sent', $type, $user, $data);

            // Log success
            EmailLogger::logSent($type, $user, $data);

        } catch (\Exception $e) {
            // HOOK: Email failed
            do_action('email_notification_failed', $type, $user, $data, $e);

            // Log failure
            EmailLogger::logFailed($type, $user, $e);

            Log::error('Failed to send email notification', [
                'user_id' => $user->id,
                'type' => $type,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Create a low stock notification for all users with appropriate permissions.
     *
     * @param  Product  $product  The product with low stock
     */
    public static function createLowStockNotification(Product $product): void
    {
        // Get all users in the organization with manage_stock permission
        $users = User::where('organization_id', $product->organization_id)
            ->whereHas('roles', function ($query) {
                $query->whereJsonContains('permissions', 'manage_stock');
            })
            ->get();

        foreach ($users as $user) {
            // Check if user wants low stock alerts
            if (! self::shouldNotifyUser($user, 'low_stock')) {
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

            // Send email notification
            self::sendEmailNotification($user, 'low_stock', [
                'product' => $product,
                'notification_url' => route('products.show', $product->id),
            ]);
        }
    }

    /**
     * Create an out of stock notification.
     *
     * @param  Product  $product  The product that is out of stock
     */
    public static function createOutOfStockNotification(Product $product): void
    {
        // Get all users in the organization with manage_stock permission
        $users = User::where('organization_id', $product->organization_id)
            ->whereHas('roles', function ($query) {
                $query->whereJsonContains('permissions', 'manage_stock');
            })
            ->get();

        foreach ($users as $user) {
            // Check if user wants low stock alerts
            if (! self::shouldNotifyUser($user, 'out_of_stock')) {
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

            // Send email notification
            self::sendEmailNotification($user, 'out_of_stock', [
                'product' => $product,
                'notification_url' => route('products.show', $product->id),
            ]);
        }
    }

    /**
     * Create an order created notification.
     *
     * Notifies all users with view_orders permission except the creator.
     *
     * @param  Order  $order  The newly created order
     */
    public static function createOrderCreatedNotification(Order $order): void
    {
        // Get all users in the organization with view_orders permission
        $users = User::where('organization_id', $order->organization_id)
            ->whereHas('roles', function ($query) {
                $query->whereJsonContains('permissions', 'view_orders');
            })
            ->where('id', '!=', $order->created_by) // Don't notify the creator
            ->get();

        $creatorName = $order->creator ? $order->creator->name : 'Unknown';

        foreach ($users as $user) {
            // Check if user wants order notifications
            if (! self::shouldNotifyUser($user, 'order_created')) {
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
     *
     * Notifies the order creator of status changes.
     *
     * @param  Order  $order  The order with updated status
     * @param  string  $oldStatus  The previous status
     */
    public static function createOrderStatusNotification(Order $order, OrderStatus|string $oldStatus): void
    {
        // Get the user to check preferences
        $user = User::find($order->created_by);
        if (! $user || ! self::shouldNotifyUser($user, 'order_status_updated')) {
            return;
        }

        $oldStatusValue = $oldStatus instanceof OrderStatus ? $oldStatus->value : $oldStatus;
        $newStatusValue = $order->status->value;

        // Notify the order creator
        Notification::create([
            'organization_id' => $order->organization_id,
            'user_id' => $order->created_by,
            'type' => 'order_status_updated',
            'title' => 'Order Status Updated',
            'message' => "Order #{$order->order_number} status changed from {$oldStatusValue} to {$newStatusValue}",
            'data' => [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'old_status' => $oldStatusValue,
                'new_status' => $newStatusValue,
            ],
            'action_url' => route('orders.show', $order->id),
            'priority' => $order->status === OrderStatus::CANCELLED ? 'high' : 'normal',
        ]);

        // Send email notification
        self::sendEmailNotification($user, 'order_status_updated', [
            'order' => $order,
            'old_status' => $oldStatus,
            'notification_url' => route('orders.show', $order->id),
        ]);
    }

    /**
     * Create an order shipped notification.
     *
     * @param  Order  $order  The shipped order
     */
    public static function createOrderShippedNotification(Order $order): void
    {
        // Get the user to check preferences
        $user = User::find($order->created_by);
        if (! $user || ! self::shouldNotifyUser($user, 'order_shipped')) {
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
     *
     * @param  Order  $order  The delivered order
     */
    public static function createOrderDeliveredNotification(Order $order): void
    {
        // Get the user to check preferences
        $user = User::find($order->created_by);
        if (! $user || ! self::shouldNotifyUser($user, 'order_delivered')) {
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
     *
     * Notifies the order creator when order is approved or rejected.
     *
     * @param  Order  $order  The order with updated approval status
     */
    public static function createOrderApprovalNotification(Order $order): void
    {
        // Get the user to check preferences
        $user = User::find($order->created_by);
        if (! $user) {
            return;
        }

        $status = $order->approval_status instanceof OrderApprovalStatus
            ? $order->approval_status->value
            : $order->approval_status;
        $approverName = $order->approver ? $order->approver->name : 'Unknown';

        // Notify the order creator
        Notification::create([
            'organization_id' => $order->organization_id,
            'user_id' => $order->created_by,
            'type' => 'order_'.$status,
            'title' => 'Order '.ucfirst($status),
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

        // Send email notification
        self::sendEmailNotification($user, 'order_'.$status, [
            'order' => $order,
            'notification_url' => route('orders.show', $order->id),
        ]);
    }

    /**
     * Notify the requesting user that a queued export is ready to download.
     *
     * @param  DataExport  $export  The completed export record
     */
    public static function createExportReadyNotification(DataExport $export): void
    {
        $user = User::find($export->user_id);
        if (! $user || ! self::shouldNotifyUser($user, 'export_ready')) {
            return;
        }

        $rows = $export->row_count !== null ? " ({$export->row_count} rows)" : '';

        Notification::create([
            'organization_id' => $export->organization_id,
            'user_id' => $export->user_id,
            'type' => 'export_ready',
            'title' => 'Export Ready',
            'message' => "Your {$export->type} export{$rows} is ready to download.",
            'data' => [
                'export_id' => $export->id,
                'type' => $export->type,
                'filename' => $export->filename,
                'row_count' => $export->row_count,
            ],
            'action_url' => route('import-export.index'),
            'priority' => 'normal',
        ]);
    }

    /**
     * Notify the requesting user that a queued export failed to generate.
     *
     * @param  DataExport  $export  The failed export record
     */
    public static function createExportFailedNotification(DataExport $export): void
    {
        $user = User::find($export->user_id);
        if (! $user || ! self::shouldNotifyUser($user, 'export_failed')) {
            return;
        }

        Notification::create([
            'organization_id' => $export->organization_id,
            'user_id' => $export->user_id,
            'type' => 'export_failed',
            'title' => 'Export Failed',
            'message' => "Your {$export->type} export could not be generated. Please try again.",
            'data' => [
                'export_id' => $export->id,
                'type' => $export->type,
                'filename' => $export->filename,
            ],
            'action_url' => route('import-export.index'),
            'priority' => 'high',
        ]);
    }

    /**
     * Notify the requesting user that a queued import has finished.
     *
     * @param  array{imported?: int, updated?: int, errors?: array<int, mixed>}  $stats
     */
    public static function createImportCompleteNotification(int $organizationId, int $userId, array $stats): void
    {
        $user = User::find($userId);
        if (! $user || ! self::shouldNotifyUser($user, 'import_complete')) {
            return;
        }

        $imported = $stats['imported'] ?? 0;
        $updated = $stats['updated'] ?? 0;
        $errorCount = count($stats['errors'] ?? []);
        $errorNote = $errorCount > 0 ? ", {$errorCount} error(s)" : '';

        Notification::create([
            'organization_id' => $organizationId,
            'user_id' => $userId,
            'type' => 'import_complete',
            'title' => 'Import Complete',
            'message' => "Your product import finished: {$imported} created, {$updated} updated{$errorNote}.",
            'data' => [
                'imported' => $imported,
                'updated' => $updated,
                'error_count' => $errorCount,
            ],
            'action_url' => route('import-export.index'),
            'priority' => $errorCount > 0 ? 'high' : 'normal',
        ]);
    }

    /**
     * Notify the requesting user that a queued import failed.
     */
    public static function createImportFailedNotification(int $organizationId, int $userId): void
    {
        $user = User::find($userId);
        if (! $user || ! self::shouldNotifyUser($user, 'import_failed')) {
            return;
        }

        Notification::create([
            'organization_id' => $organizationId,
            'user_id' => $userId,
            'type' => 'import_failed',
            'title' => 'Import Failed',
            'message' => 'Your product import could not be processed. Please check the file and try again.',
            'data' => [],
            'action_url' => route('import-export.index'),
            'priority' => 'high',
        ]);
    }
}
