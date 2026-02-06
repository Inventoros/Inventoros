<?php

namespace App\Listeners;

use App\Models\Inventory\Product;
use App\Models\Inventory\StockAdjustment;
use App\Models\Order\Order;
use App\Models\Purchasing\PurchaseOrder;
use App\Models\User;
use App\Services\WebhookService;
use Illuminate\Support\Facades\Log;

/**
 * Subscribes to application events and dispatches webhooks.
 *
 * This class registers callbacks with the hook system to trigger
 * webhook deliveries when key events occur in the application.
 */
class WebhookEventSubscriber
{
    /**
     * Register the listeners for the subscriber.
     *
     * @return void
     */
    public static function subscribe(): void
    {
        // Product events
        add_action('product_created', [static::class, 'onProductCreated'], 100);
        add_action('product_updated', [static::class, 'onProductUpdated'], 100);
        add_action('product_deleted', [static::class, 'onProductDeleted'], 100);
        add_action('low_stock_alert', [static::class, 'onLowStock'], 100);
        add_action('out_of_stock_alert', [static::class, 'onOutOfStock'], 100);

        // Stock events
        add_action('stock_adjusted', [static::class, 'onStockAdjusted'], 100);

        // Order events - these need to be added to the OrderController
        add_action('order_created', [static::class, 'onOrderCreated'], 100);
        add_action('order_updated', [static::class, 'onOrderUpdated'], 100);
        add_action('order_status_changed', [static::class, 'onOrderStatusChanged'], 100);
        add_action('order_approved', [static::class, 'onOrderApproved'], 100);
        add_action('order_rejected', [static::class, 'onOrderRejected'], 100);

        // Purchase order events
        add_action('purchase_order_created', [static::class, 'onPurchaseOrderCreated'], 100);
        add_action('purchase_order_received', [static::class, 'onPurchaseOrderReceived'], 100);
        add_action('purchase_order_cancelled', [static::class, 'onPurchaseOrderCancelled'], 100);
    }

    /**
     * Handle product created event.
     *
     * @param Product $product
     * @param User|null $user
     * @return void
     */
    public static function onProductCreated(Product $product, ?User $user = null): void
    {
        try {
            WebhookService::dispatch(
                'product.created',
                self::formatProductData($product, $user),
                $product->organization_id
            );
        } catch (\Exception $e) {
            Log::error('Failed to dispatch product.created webhook', [
                'product_id' => $product->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle product updated event.
     *
     * @param Product $product
     * @param User|null $user
     * @return void
     */
    public static function onProductUpdated(Product $product, ?User $user = null): void
    {
        try {
            WebhookService::dispatch(
                'product.updated',
                self::formatProductData($product, $user),
                $product->organization_id
            );
        } catch (\Exception $e) {
            Log::error('Failed to dispatch product.updated webhook', [
                'product_id' => $product->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle product deleted event.
     *
     * @param Product $product
     * @param User|null $user
     * @return void
     */
    public static function onProductDeleted(Product $product, ?User $user = null): void
    {
        try {
            WebhookService::dispatch(
                'product.deleted',
                self::formatProductData($product, $user),
                $product->organization_id
            );
        } catch (\Exception $e) {
            Log::error('Failed to dispatch product.deleted webhook', [
                'product_id' => $product->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle low stock alert event.
     *
     * @param Product $product
     * @return void
     */
    public static function onLowStock(Product $product): void
    {
        try {
            WebhookService::dispatch(
                'product.low_stock',
                [
                    'product' => self::formatProductData($product),
                    'current_stock' => $product->stock,
                    'min_stock' => $product->min_stock,
                ],
                $product->organization_id
            );
        } catch (\Exception $e) {
            Log::error('Failed to dispatch product.low_stock webhook', [
                'product_id' => $product->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle out of stock alert event.
     *
     * @param Product $product
     * @return void
     */
    public static function onOutOfStock(Product $product): void
    {
        try {
            WebhookService::dispatch(
                'product.out_of_stock',
                [
                    'product' => self::formatProductData($product),
                    'current_stock' => 0,
                ],
                $product->organization_id
            );
        } catch (\Exception $e) {
            Log::error('Failed to dispatch product.out_of_stock webhook', [
                'product_id' => $product->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle stock adjusted event.
     *
     * @param StockAdjustment $adjustment
     * @param Product|null $product
     * @return void
     */
    public static function onStockAdjusted(StockAdjustment $adjustment, ?Product $product = null): void
    {
        try {
            $product = $product ?? $adjustment->product;

            WebhookService::dispatch(
                'stock.adjusted',
                [
                    'adjustment' => [
                        'id' => $adjustment->id,
                        'type' => $adjustment->type,
                        'quantity' => $adjustment->quantity,
                        'quantity_before' => $adjustment->quantity_before,
                        'quantity_after' => $adjustment->quantity_after,
                        'reason' => $adjustment->reason,
                        'notes' => $adjustment->notes,
                        'created_at' => $adjustment->created_at?->toIso8601String(),
                    ],
                    'product' => $product ? self::formatProductData($product) : null,
                    'user' => $adjustment->user ? [
                        'id' => $adjustment->user->id,
                        'name' => $adjustment->user->name,
                        'email' => $adjustment->user->email,
                    ] : null,
                ],
                $adjustment->organization_id
            );
        } catch (\Exception $e) {
            Log::error('Failed to dispatch stock.adjusted webhook', [
                'adjustment_id' => $adjustment->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle order created event.
     *
     * @param Order $order
     * @param User|null $user
     * @return void
     */
    public static function onOrderCreated(Order $order, ?User $user = null): void
    {
        try {
            WebhookService::dispatch(
                'order.created',
                self::formatOrderData($order, $user),
                $order->organization_id
            );
        } catch (\Exception $e) {
            Log::error('Failed to dispatch order.created webhook', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle order updated event.
     *
     * @param Order $order
     * @param User|null $user
     * @return void
     */
    public static function onOrderUpdated(Order $order, ?User $user = null): void
    {
        try {
            WebhookService::dispatch(
                'order.updated',
                self::formatOrderData($order, $user),
                $order->organization_id
            );
        } catch (\Exception $e) {
            Log::error('Failed to dispatch order.updated webhook', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle order status changed event.
     *
     * @param Order $order
     * @param string $oldStatus
     * @param string $newStatus
     * @param User|null $user
     * @return void
     */
    public static function onOrderStatusChanged(Order $order, string $oldStatus, string $newStatus, ?User $user = null): void
    {
        try {
            $data = self::formatOrderData($order, $user);
            $data['status_change'] = [
                'from' => $oldStatus,
                'to' => $newStatus,
            ];

            WebhookService::dispatch(
                'order.status_changed',
                $data,
                $order->organization_id
            );
        } catch (\Exception $e) {
            Log::error('Failed to dispatch order.status_changed webhook', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle order approved event.
     *
     * @param Order $order
     * @param User|null $approver
     * @return void
     */
    public static function onOrderApproved(Order $order, ?User $approver = null): void
    {
        try {
            $data = self::formatOrderData($order);
            $data['approval'] = [
                'status' => 'approved',
                'approved_at' => $order->approved_at?->toIso8601String(),
                'notes' => $order->approval_notes,
                'approver' => $approver ? [
                    'id' => $approver->id,
                    'name' => $approver->name,
                    'email' => $approver->email,
                ] : null,
            ];

            WebhookService::dispatch(
                'order.approved',
                $data,
                $order->organization_id
            );
        } catch (\Exception $e) {
            Log::error('Failed to dispatch order.approved webhook', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle order rejected event.
     *
     * @param Order $order
     * @param User|null $rejector
     * @return void
     */
    public static function onOrderRejected(Order $order, ?User $rejector = null): void
    {
        try {
            $data = self::formatOrderData($order);
            $data['approval'] = [
                'status' => 'rejected',
                'rejected_at' => $order->approved_at?->toIso8601String(),
                'notes' => $order->approval_notes,
                'rejector' => $rejector ? [
                    'id' => $rejector->id,
                    'name' => $rejector->name,
                    'email' => $rejector->email,
                ] : null,
            ];

            WebhookService::dispatch(
                'order.rejected',
                $data,
                $order->organization_id
            );
        } catch (\Exception $e) {
            Log::error('Failed to dispatch order.rejected webhook', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle purchase order created event.
     *
     * @param PurchaseOrder $purchaseOrder
     * @param User|null $user
     * @return void
     */
    public static function onPurchaseOrderCreated(PurchaseOrder $purchaseOrder, ?User $user = null): void
    {
        try {
            WebhookService::dispatch(
                'purchase_order.created',
                self::formatPurchaseOrderData($purchaseOrder, $user),
                $purchaseOrder->organization_id
            );
        } catch (\Exception $e) {
            Log::error('Failed to dispatch purchase_order.created webhook', [
                'purchase_order_id' => $purchaseOrder->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle purchase order received event.
     *
     * @param PurchaseOrder $purchaseOrder
     * @param User|null $user
     * @return void
     */
    public static function onPurchaseOrderReceived(PurchaseOrder $purchaseOrder, ?User $user = null): void
    {
        try {
            WebhookService::dispatch(
                'purchase_order.received',
                self::formatPurchaseOrderData($purchaseOrder, $user),
                $purchaseOrder->organization_id
            );
        } catch (\Exception $e) {
            Log::error('Failed to dispatch purchase_order.received webhook', [
                'purchase_order_id' => $purchaseOrder->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle purchase order cancelled event.
     *
     * @param PurchaseOrder $purchaseOrder
     * @param User|null $user
     * @return void
     */
    public static function onPurchaseOrderCancelled(PurchaseOrder $purchaseOrder, ?User $user = null): void
    {
        try {
            WebhookService::dispatch(
                'purchase_order.cancelled',
                self::formatPurchaseOrderData($purchaseOrder, $user),
                $purchaseOrder->organization_id
            );
        } catch (\Exception $e) {
            Log::error('Failed to dispatch purchase_order.cancelled webhook', [
                'purchase_order_id' => $purchaseOrder->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Format product data for webhook payload.
     *
     * @param Product $product
     * @param User|null $user
     * @return array
     */
    private static function formatProductData(Product $product, ?User $user = null): array
    {
        $data = [
            'product' => [
                'id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
                'price' => $product->price,
                'purchase_price' => $product->purchase_price,
                'stock' => $product->stock,
                'min_stock' => $product->min_stock,
                'is_active' => $product->is_active,
                'category_id' => $product->category_id,
                'location_id' => $product->location_id,
                'created_at' => $product->created_at?->toIso8601String(),
                'updated_at' => $product->updated_at?->toIso8601String(),
            ],
        ];

        if ($user) {
            $data['user'] = [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ];
        }

        return $data;
    }

    /**
     * Format order data for webhook payload.
     *
     * @param Order $order
     * @param User|null $user
     * @return array
     */
    private static function formatOrderData(Order $order, ?User $user = null): array
    {
        $order->loadMissing('items');

        $data = [
            'order' => [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'status' => $order->status,
                'approval_status' => $order->approval_status,
                'source' => $order->source,
                'customer_name' => $order->customer_name,
                'customer_email' => $order->customer_email,
                'customer_address' => $order->customer_address,
                'subtotal' => $order->subtotal,
                'tax' => $order->tax,
                'shipping' => $order->shipping,
                'total' => $order->total,
                'order_date' => $order->order_date,
                'shipped_at' => $order->shipped_at?->toIso8601String(),
                'delivered_at' => $order->delivered_at?->toIso8601String(),
                'created_at' => $order->created_at?->toIso8601String(),
                'updated_at' => $order->updated_at?->toIso8601String(),
                'items' => $order->items->map(fn ($item) => [
                    'id' => $item->id,
                    'product_id' => $item->product_id,
                    'product_name' => $item->product_name,
                    'sku' => $item->sku,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'subtotal' => $item->subtotal,
                    'total' => $item->total,
                ])->toArray(),
            ],
        ];

        if ($user) {
            $data['user'] = [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ];
        }

        return $data;
    }

    /**
     * Format purchase order data for webhook payload.
     *
     * @param PurchaseOrder $purchaseOrder
     * @param User|null $user
     * @return array
     */
    private static function formatPurchaseOrderData(PurchaseOrder $purchaseOrder, ?User $user = null): array
    {
        $purchaseOrder->loadMissing(['items', 'supplier']);

        $data = [
            'purchase_order' => [
                'id' => $purchaseOrder->id,
                'po_number' => $purchaseOrder->po_number,
                'status' => $purchaseOrder->status,
                'supplier' => $purchaseOrder->supplier ? [
                    'id' => $purchaseOrder->supplier->id,
                    'name' => $purchaseOrder->supplier->name,
                ] : null,
                'order_date' => $purchaseOrder->order_date,
                'expected_date' => $purchaseOrder->expected_date,
                'received_date' => $purchaseOrder->received_date,
                'subtotal' => $purchaseOrder->subtotal,
                'tax' => $purchaseOrder->tax,
                'shipping' => $purchaseOrder->shipping,
                'total' => $purchaseOrder->total,
                'currency' => $purchaseOrder->currency,
                'created_at' => $purchaseOrder->created_at?->toIso8601String(),
                'updated_at' => $purchaseOrder->updated_at?->toIso8601String(),
                'items' => $purchaseOrder->items->map(fn ($item) => [
                    'id' => $item->id,
                    'product_id' => $item->product_id,
                    'product_name' => $item->product_name,
                    'sku' => $item->sku,
                    'quantity_ordered' => $item->quantity_ordered,
                    'quantity_received' => $item->quantity_received,
                    'unit_cost' => $item->unit_cost,
                    'subtotal' => $item->subtotal,
                    'total' => $item->total,
                ])->toArray(),
            ],
        ];

        if ($user) {
            $data['user'] = [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ];
        }

        return $data;
    }
}
