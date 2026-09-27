<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\InvalidStateException;
use App\Models\Inventory\StockAdjustment;
use App\Models\Order\Order;
use App\Models\Order\OrderItem;
use App\Models\Order\ReturnOrder;
use App\Models\Order\ReturnOrderItem;
use App\Models\User;
use App\Support\Money;
use App\Support\SequenceNumberRetry;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The single implementation of the return (RMA) lifecycle: create, approve,
 * receive (restock), complete and reject.
 *
 * Shared by the web ReturnOrderController and the REST API so the quantity
 * caps, locking and restock behaviour cannot drift between surfaces. State
 * violations throw InvalidStateException; per-line quantity violations throw
 * a ValidationException keyed by `items.{index}.*`.
 */
final class ReturnOrderService
{
    public function __construct(private readonly TrackedStockAllocationService $trackedStock) {}

    /**
     * Quantities already returned per order item (excluding rejected returns).
     *
     * @return Collection<int, int|string>
     */
    public function returnedQuantities(Order $order): Collection
    {
        return ReturnOrderItem::whereHas('returnOrder', function ($q) use ($order) {
            $q->where('order_id', $order->id)
                ->whereNotIn('status', ['rejected']);
        })->selectRaw('order_item_id, SUM(quantity) as total_returned')
            ->groupBy('order_item_id')
            ->pluck('total_returned', 'order_item_id');
    }

    /**
     * Create a pending return against an order.
     *
     * @param  array{order_id: int, type: string, reason: string, notes?: string|null, items: array<int, array{order_item_id: int, quantity: int, condition: string, restock: bool}>}  $data
     *
     * @throws ValidationException when a line exceeds its returnable quantity
     */
    public function create(int $organizationId, array $data): ReturnOrder
    {
        // Verify the order belongs to this organization
        $order = Order::forOrganization($organizationId)->findOrFail($data['order_id']);
        $order->load('items');

        // Retry on a return_number unique collision: two returns for
        // different orders in the same org/second read the same MAX and
        // would otherwise 500 (the parent-order lock only serialises returns
        // of the SAME order).
        return SequenceNumberRetry::create(fn () => DB::transaction(function () use ($data, $organizationId, $order) {
            // Lock the parent Order row, not the aggregate. The previous
            // implementation applied lockForUpdate() to a SELECT with
            // GROUP BY — Postgres rejects locking aggregated rows
            // outright, SQLite silently ignores FOR UPDATE entirely,
            // and MySQL locks the visible aggregated rows rather than
            // the underlying return_order_items, so concurrent submissions
            // could still slip past the "already returned" check on
            // some engines. Locking the parent Order forces sequential
            // return submissions against the same order on every
            // supported driver.
            Order::where('id', $order->id)->lockForUpdate()->first();

            $returnedQuantities = $this->returnedQuantities($order);

            $errors = [];
            foreach ($data['items'] as $index => $item) {
                $orderItem = $order->items->firstWhere('id', $item['order_item_id']);
                if (! $orderItem) {
                    $errors["items.{$index}.order_item_id"] = 'Order item not found.';

                    continue;
                }

                $alreadyReturned = $returnedQuantities->get($item['order_item_id'], 0);
                $maxReturnable = $orderItem->quantity - $alreadyReturned;

                if ($item['quantity'] > $maxReturnable) {
                    $errors["items.{$index}.quantity"] = "Cannot return more than {$maxReturnable} units (ordered: {$orderItem->quantity}, already returned: {$alreadyReturned}).";
                }
            }

            if (! empty($errors)) {
                throw ValidationException::withMessages($errors);
            }

            // Calculate refund amount
            $refundAmount = '0';
            foreach ($data['items'] as $item) {
                $orderItem = $order->items->firstWhere('id', $item['order_item_id']);
                $refundAmount = Money::add($refundAmount, Money::multiply($orderItem->unit_price, $item['quantity']));
            }

            $returnOrder = ReturnOrder::create([
                'organization_id' => $organizationId,
                'order_id' => $order->id,
                'return_number' => ReturnOrder::generateReturnNumber($organizationId),
                'type' => $data['type'],
                'status' => 'pending',
                'reason' => $data['reason'],
                'notes' => $data['notes'] ?? null,
                'refund_amount' => $refundAmount,
            ]);

            foreach ($data['items'] as $item) {
                // Derive the product from the order line, NOT the client
                // payload. order_item_id is what caps the return quantity and
                // computes the refund; taking product_id from the request
                // instead let a caller point the restock at a different
                // product than the one actually on that line, inflating an
                // arbitrary product's on-hand on receive.
                $orderItem = $order->items->firstWhere('id', $item['order_item_id']);

                ReturnOrderItem::create([
                    'return_order_id' => $returnOrder->id,
                    'order_item_id' => $orderItem->id,
                    'product_id' => $orderItem->product_id,
                    'quantity' => $item['quantity'],
                    'condition' => $item['condition'],
                    'restock' => $item['restock'],
                ]);
            }

            return $returnOrder;
        }));
    }

    /**
     * Approve a pending return.
     */
    public function approve(ReturnOrder $returnOrder, User $actor): ReturnOrder
    {
        if ($returnOrder->status !== 'pending') {
            throw new InvalidStateException('Only pending returns can be approved.', 'invalid_status');
        }

        $returnOrder->update([
            'status' => 'approved',
            'processed_by' => $actor->id,
        ]);

        return $returnOrder;
    }

    /**
     * Receive an approved return, restocking every line marked for restock.
     */
    public function receive(ReturnOrder $returnOrder, User $actor): ReturnOrder
    {
        if ($returnOrder->status !== 'approved') {
            throw new InvalidStateException('Only approved returns can be received.', 'invalid_status');
        }

        return DB::transaction(function () use ($returnOrder, $actor) {
            // Re-read the return under a row lock and re-assert its status
            // inside the transaction. The pre-transaction guard above runs
            // against the unlocked route-model instance, so a concurrent
            // double-submit could pass that check twice and double-restock.
            // Locking + re-checking here forces the second request to wait,
            // observe status === 'received', and bail without restocking.
            $locked = ReturnOrder::whereKey($returnOrder->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'approved') {
                throw new InvalidStateException('Only approved returns can be received.', 'invalid_status');
            }

            $locked->load('items.product');

            foreach ($locked->items as $item) {
                if ($item->restock && $item->product) {
                    StockAdjustment::adjust(
                        $item->product,
                        $item->quantity,
                        'return',
                        'Return restock',
                        "Restocked from return {$locked->return_number}",
                        $locked,
                        // Book the returned units into the product's location
                        // bin so the per-location breakdown rises with the
                        // total instead of drifting into "unassigned" — the
                        // same treatment order-cancel and PO-receipt use.
                        locationId: $item->product->location_id,
                    );

                    // Return the specific serials/batches this line consumed
                    // back to available, up to the returned quantity, so
                    // COUNT(available) rises with the total instead of lagging
                    // until reconcile. Runs after adjust() locked the product,
                    // preserving the product-before-tracked lock order.
                    if ($item->order_item_id !== null) {
                        $orderItem = OrderItem::find($item->order_item_id);
                        if ($orderItem !== null) {
                            $this->trackedStock->releaseForOrderItem($orderItem, (int) $item->quantity);
                        }
                    }
                }
            }

            $locked->update([
                'status' => 'received',
                'processed_by' => $actor->id,
            ]);

            return $locked;
        });
    }

    /**
     * Complete a received return.
     */
    public function complete(ReturnOrder $returnOrder, User $actor): ReturnOrder
    {
        if ($returnOrder->status !== 'received') {
            throw new InvalidStateException('Only received returns can be completed.', 'invalid_status');
        }

        $returnOrder->update([
            'status' => 'completed',
            'completed_at' => now(),
            'processed_by' => $actor->id,
        ]);

        return $returnOrder;
    }

    /**
     * Reject a pending return, appending the reason to its notes.
     */
    public function reject(ReturnOrder $returnOrder, User $actor, ?string $reason = null): ReturnOrder
    {
        if ($returnOrder->status !== 'pending') {
            throw new InvalidStateException('Only pending returns can be rejected.', 'invalid_status');
        }

        $returnOrder->update([
            'status' => 'rejected',
            'processed_by' => $actor->id,
            'notes' => $returnOrder->notes
                ? $returnOrder->notes."\n\nRejection reason: ".($reason ?? 'No reason provided')
                : 'Rejection reason: '.($reason ?? 'No reason provided'),
        ]);

        return $returnOrder;
    }
}
