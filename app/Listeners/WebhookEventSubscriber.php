<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\Customer;
use App\Models\Inventory\Product;
use App\Models\Inventory\StockAdjustment;
use App\Models\Inventory\StockAudit;
use App\Models\Inventory\StockTransfer;
use App\Models\Inventory\WorkOrder;
use App\Models\Order\Order;
use App\Models\Order\OrderPayment;
use App\Models\Order\ReturnOrder;
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
final class WebhookEventSubscriber
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

        // Payment events (refunds are recorded payments of type "refund")
        add_action('payment_recorded', [static::class, 'onPaymentRecorded'], 100);
        add_action('payment_voided', [static::class, 'onPaymentVoided'], 100);

        // Purchase order events
        add_action('purchase_order_created', [static::class, 'onPurchaseOrderCreated'], 100);
        add_action('purchase_order_received', [static::class, 'onPurchaseOrderReceived'], 100);
        add_action('purchase_order_cancelled', [static::class, 'onPurchaseOrderCancelled'], 100);

        // Customer events
        add_action('customer_created', [static::class, 'onCustomerCreated'], 100);
        add_action('customer_updated', [static::class, 'onCustomerUpdated'], 100);
        add_action('customer_deleted', [static::class, 'onCustomerDeleted'], 100);

        // Return (RMA) events
        add_action('return_created', [static::class, 'onReturnCreated'], 100);
        add_action('return_received', [static::class, 'onReturnReceived'], 100);

        // Stock transfer events
        add_action('transfer_created', [static::class, 'onTransferCreated'], 100);
        add_action('transfer_completed', [static::class, 'onTransferCompleted'], 100);

        // Work order and stock audit events
        add_action('work_order_completed', [static::class, 'onWorkOrderCompleted'], 100);
        add_action('stock_audit_completed', [static::class, 'onStockAuditCompleted'], 100);
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
                        'quantity' => $adjustment->adjustment_quantity,
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

    public static function onCustomerCreated(Customer $customer, ?User $user = null): void
    {
        self::send('customer.created', $customer->organization_id, fn () => self::formatCustomerData($customer, $user), ['customer_id' => $customer->id]);
    }

    public static function onCustomerUpdated(Customer $customer, ?User $user = null): void
    {
        self::send('customer.updated', $customer->organization_id, fn () => self::formatCustomerData($customer, $user), ['customer_id' => $customer->id]);
    }

    public static function onCustomerDeleted(Customer $customer, ?User $user = null): void
    {
        self::send('customer.deleted', $customer->organization_id, fn () => self::formatCustomerData($customer, $user), ['customer_id' => $customer->id]);
    }

    public static function onReturnCreated(ReturnOrder $returnOrder, ?User $user = null): void
    {
        self::send('return.created', $returnOrder->organization_id, fn () => self::formatReturnData($returnOrder, $user), ['return_order_id' => $returnOrder->id]);
    }

    public static function onReturnReceived(ReturnOrder $returnOrder, ?User $user = null): void
    {
        self::send('return.received', $returnOrder->organization_id, fn () => self::formatReturnData($returnOrder, $user), ['return_order_id' => $returnOrder->id]);
    }

    public static function onTransferCreated(StockTransfer $transfer, ?User $user = null): void
    {
        self::send('transfer.created', $transfer->organization_id, fn () => self::formatTransferData($transfer, $user), ['stock_transfer_id' => $transfer->id]);
    }

    public static function onTransferCompleted(StockTransfer $transfer, ?User $user = null): void
    {
        self::send('transfer.completed', $transfer->organization_id, fn () => self::formatTransferData($transfer, $user), ['stock_transfer_id' => $transfer->id]);
    }

    public static function onWorkOrderCompleted(WorkOrder $workOrder, ?User $user = null): void
    {
        self::send('work_order.completed', $workOrder->organization_id, fn () => self::withUser([
            'work_order' => [
                'id' => $workOrder->id,
                'work_order_number' => $workOrder->work_order_number,
                'product_id' => $workOrder->product_id,
                'warehouse_id' => $workOrder->warehouse_id,
                'quantity' => $workOrder->quantity,
                'quantity_produced' => $workOrder->quantity_produced,
                'status' => $workOrder->status,
                'started_at' => $workOrder->started_at?->toIso8601String(),
                'completed_at' => $workOrder->completed_at?->toIso8601String(),
            ],
        ], $user), ['work_order_id' => $workOrder->id]);
    }

    public static function onStockAuditCompleted(StockAudit $stockAudit, ?User $user = null): void
    {
        self::send('stock_audit.completed', $stockAudit->organization_id, function () use ($stockAudit, $user) {
            $items = $stockAudit->items()->get(['id', 'counted_quantity', 'discrepancy']);

            return self::withUser([
                'stock_audit' => [
                    'id' => $stockAudit->id,
                    'audit_number' => $stockAudit->audit_number,
                    'name' => $stockAudit->name,
                    'audit_type' => $stockAudit->audit_type,
                    'status' => $stockAudit->status,
                    'warehouse_location_id' => $stockAudit->warehouse_location_id,
                    'started_at' => $stockAudit->started_at?->toIso8601String(),
                    'completed_at' => $stockAudit->completed_at?->toIso8601String(),
                    'items_count' => $items->count(),
                    'counted_items' => $items->whereNotNull('counted_quantity')->count(),
                    'discrepancies' => $items->filter(fn ($item) => (int) $item->discrepancy !== 0)->count(),
                ],
            ], $user);
        }, ['stock_audit_id' => $stockAudit->id]);
    }

    /**
     * Dispatch a webhook event, logging (never throwing) on failure so a
     * webhook problem can never break the business operation that fired it.
     *
     * @param  callable(): array<string, mixed>  $payload
     * @param  array<string, mixed>  $context
     */
    private static function send(string $event, int $organizationId, callable $payload, array $context): void
    {
        try {
            WebhookService::dispatch($event, $payload(), $organizationId);
        } catch (\Exception $e) {
            Log::error("Failed to dispatch {$event} webhook", $context + ['error' => $e->getMessage()]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function withUser(array $data, ?User $user): array
    {
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
     * @return array<string, mixed>
     */
    private static function formatCustomerData(Customer $customer, ?User $user = null): array
    {
        return self::withUser([
            'customer' => [
                'id' => $customer->id,
                'name' => $customer->name,
                'code' => $customer->code,
                'company_name' => $customer->company_name,
                'contact_name' => $customer->contact_name,
                'email' => $customer->email,
                'phone' => $customer->phone,
                'currency' => $customer->currency,
                'is_active' => $customer->is_active,
                'created_at' => $customer->created_at?->toIso8601String(),
                'updated_at' => $customer->updated_at?->toIso8601String(),
                'deleted_at' => $customer->deleted_at?->toIso8601String(),
            ],
        ], $user);
    }

    /**
     * @return array<string, mixed>
     */
    private static function formatReturnData(ReturnOrder $returnOrder, ?User $user = null): array
    {
        $returnOrder->load('items');

        return self::withUser([
            'return' => [
                'id' => $returnOrder->id,
                'return_number' => $returnOrder->return_number,
                'order_id' => $returnOrder->order_id,
                'type' => $returnOrder->type,
                'status' => $returnOrder->status,
                'reason' => $returnOrder->reason,
                'refund_amount' => $returnOrder->refund_amount,
                'created_at' => $returnOrder->created_at?->toIso8601String(),
                'updated_at' => $returnOrder->updated_at?->toIso8601String(),
                'items' => $returnOrder->items->map(fn ($item) => [
                    'id' => $item->id,
                    'order_item_id' => $item->order_item_id,
                    'product_id' => $item->product_id,
                    'quantity' => $item->quantity,
                    'condition' => $item->condition,
                    'restock' => $item->restock,
                ])->toArray(),
            ],
        ], $user);
    }

    /**
     * @return array<string, mixed>
     */
    private static function formatTransferData(StockTransfer $transfer, ?User $user = null): array
    {
        $transfer->load('items');

        return self::withUser([
            'transfer' => [
                'id' => $transfer->id,
                'transfer_number' => $transfer->transfer_number,
                'status' => $transfer->status,
                'from_location_id' => $transfer->from_location_id,
                'to_location_id' => $transfer->to_location_id,
                'from_warehouse_id' => $transfer->from_warehouse_id,
                'to_warehouse_id' => $transfer->to_warehouse_id,
                'is_inter_warehouse' => $transfer->is_inter_warehouse,
                'tracking_number' => $transfer->tracking_number,
                'shipped_at' => $transfer->shipped_at?->toIso8601String(),
                'completed_at' => $transfer->completed_at?->toIso8601String(),
                'created_at' => $transfer->created_at?->toIso8601String(),
                'items' => $transfer->items->map(fn ($item) => [
                    'id' => $item->id,
                    'product_id' => $item->product_id,
                    'quantity' => $item->quantity,
                ])->toArray(),
            ],
        ], $user);
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
    /**
     * Handle a payment (or refund) recorded against an order.
     */
    public static function onPaymentRecorded(OrderPayment $payment, Order $order, ?User $user = null): void
    {
        self::dispatchPayment('payment.recorded', $payment, $order, $user);
    }

    /**
     * Handle a payment (or refund) being voided.
     */
    public static function onPaymentVoided(OrderPayment $payment, Order $order, ?User $user = null): void
    {
        self::dispatchPayment('payment.voided', $payment, $order, $user);
    }

    private static function dispatchPayment(string $event, OrderPayment $payment, Order $order, ?User $user): void
    {
        try {
            WebhookService::dispatch($event, [
                'payment' => [
                    'id' => $payment->id,
                    'type' => $payment->type->value,
                    'amount' => (string) $payment->amount,
                    'method' => $payment->method->value,
                    'reference' => $payment->reference,
                    'paid_at' => $payment->paid_at?->toIso8601String(),
                    'notes' => $payment->notes,
                    'voided_at' => $payment->voided_at?->toIso8601String(),
                    'void_reason' => $payment->void_reason,
                ],
                'order' => [
                    'id' => $order->id,
                    'order_number' => $order->order_number,
                    'currency' => $order->currency,
                    'total' => (string) $order->total,
                    'amount_paid' => (string) $order->amount_paid,
                    'balance_due' => $order->balanceDue(),
                    'payment_status' => $order->payment_status?->value,
                ],
                'user' => $user ? ['id' => $user->id, 'name' => $user->name, 'email' => $user->email] : null,
            ], $order->organization_id);
        } catch (\Exception $e) {
            Log::error("Failed to dispatch {$event} webhook", [
                'payment_id' => $payment->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

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
