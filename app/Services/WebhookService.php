<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\WebhookDeliveryJob;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use Illuminate\Support\Str;

/**
 * Service for managing webhook dispatching and delivery.
 *
 * Provides static methods for dispatching webhooks to subscribed endpoints,
 * generating and verifying HMAC signatures, and managing event definitions.
 */
final class WebhookService
{
    public const SIGNATURE_ALGORITHM = 'sha256';
    public const STATUS_PENDING = 'pending';
    /**
     * Dispatch webhook for an event to all subscribed webhooks.
     *
     * @param string $event The event name (e.g., 'order.created')
     * @param array $data The event data to send
     * @param int $organizationId The organization ID
     * @return void
     */
    public static function dispatch(string $event, array $data, int $organizationId): void
    {
        $webhooks = Webhook::forOrganization($organizationId)
            ->active()
            ->subscribedTo($event)
            ->get();

        foreach ($webhooks as $webhook) {
            $payload = [
                'id' => 'wh_' . Str::random(24),
                'event' => $event,
                'timestamp' => now()->toIso8601String(),
                'organization_id' => $organizationId,
                'data' => $data,
            ];

            $delivery = WebhookDelivery::create([
                'webhook_id' => $webhook->id,
                'event' => $event,
                'payload' => $payload,
                'status' => 'pending',
            ]);

            WebhookDeliveryJob::dispatch($delivery);
        }
    }

    /**
     * Generate HMAC signature for a webhook payload.
     *
     * @param string $payload The JSON-encoded payload
     * @param string $secret The webhook secret
     * @return string The HMAC-SHA256 signature
     */
    public static function sign(string $payload, string $secret): string
    {
        return hash_hmac('sha256', $payload, $secret);
    }

    /**
     * Verify a webhook signature.
     *
     * @param string $payload The JSON-encoded payload
     * @param string $secret The webhook secret
     * @param string $signature The signature to verify
     * @return bool True if the signature is valid
     */
    public static function verifySignature(string $payload, string $secret, string $signature): bool
    {
        $expectedSignature = self::sign($payload, $secret);

        return hash_equals($expectedSignature, $signature);
    }

    /**
     * Get the list of all available webhook events.
     *
     * @return array<string> List of event names
     */
    public static function availableEvents(): array
    {
        return [
            // Product events
            'product.created',
            'product.updated',
            'product.deleted',
            'product.low_stock',
            'product.out_of_stock',

            // Order events
            'order.created',
            'order.updated',
            'order.status_changed',
            'order.approved',
            'order.rejected',

            // Payment events
            'payment.recorded',
            'payment.voided',

            // Shipment events
            'shipment.created',
            'shipment.delivered',

            // Stock events
            'stock.adjusted',

            // Purchase order events
            'purchase_order.created',
            'purchase_order.received',
            'purchase_order.cancelled',

            // Customer events
            'customer.created',
            'customer.updated',
            'customer.deleted',

            // Return (RMA) events
            'return.created',
            'return.received',

            // Stock transfer events
            'transfer.created',
            'transfer.completed',

            // Work order events
            'work_order.completed',

            // Stock audit events
            'stock_audit.completed',
        ];
    }

    /**
     * Get event groups for UI display.
     *
     * @return array<string, array<string, string>> Grouped events with descriptions
     */
    public static function eventGroups(): array
    {
        return [
            'Product' => [
                'product.created' => 'When a new product is created',
                'product.updated' => 'When a product is updated',
                'product.deleted' => 'When a product is deleted',
                'product.low_stock' => 'When product stock falls below minimum',
                'product.out_of_stock' => 'When product stock reaches zero',
            ],
            'Order' => [
                'order.created' => 'When a new order is created',
                'order.updated' => 'When an order is updated',
                'order.status_changed' => 'When order status changes',
                'order.approved' => 'When an order is approved',
                'order.rejected' => 'When an order is rejected',
            ],
            'Payment' => [
                'payment.recorded' => 'When a payment or refund is recorded against an order',
                'payment.voided' => 'When a payment or refund is voided',
            ],
            'Shipment' => [
                'shipment.created' => 'When a shipment is created for an order',
                'shipment.delivered' => 'When the carrier reports a shipment delivered',
            ],
            'Stock' => [
                'stock.adjusted' => 'When stock is manually adjusted',
            ],
            'Purchase Order' => [
                'purchase_order.created' => 'When a purchase order is created',
                'purchase_order.received' => 'When a purchase order is received',
                'purchase_order.cancelled' => 'When a purchase order is cancelled',
            ],
            'Customer' => [
                'customer.created' => 'When a new customer is created',
                'customer.updated' => 'When a customer is updated',
                'customer.deleted' => 'When a customer is deleted',
            ],
            'Return' => [
                'return.created' => 'When a return (RMA) is requested',
                'return.received' => 'When returned items are received',
            ],
            'Stock Transfer' => [
                'transfer.created' => 'When a stock transfer is created',
                'transfer.completed' => 'When a stock transfer is completed',
            ],
            'Work Order' => [
                'work_order.completed' => 'When a work order is completed',
            ],
            'Stock Audit' => [
                'stock_audit.completed' => 'When a stock audit is completed',
            ],
        ];
    }

    /**
     * Get a description for an event.
     *
     * @param string $event The event name
     * @return string|null The event description
     */
    public static function getEventDescription(string $event): ?string
    {
        foreach (self::eventGroups() as $group => $events) {
            if (isset($events[$event])) {
                return $events[$event];
            }
        }

        return null;
    }
}
