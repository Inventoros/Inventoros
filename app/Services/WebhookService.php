<?php

namespace App\Services;

use App\Jobs\WebhookDeliveryJob;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use Illuminate\Support\Str;

/**
 * Service for managing webhook dispatching and delivery.
 */
class WebhookService
{
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

            // Stock events
            'stock.adjusted',

            // Purchase order events
            'purchase_order.created',
            'purchase_order.received',
            'purchase_order.cancelled',
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
            'Stock' => [
                'stock.adjusted' => 'When stock is manually adjusted',
            ],
            'Purchase Order' => [
                'purchase_order.created' => 'When a purchase order is created',
                'purchase_order.received' => 'When a purchase order is received',
                'purchase_order.cancelled' => 'When a purchase order is cancelled',
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
