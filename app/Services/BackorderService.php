<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductVariant;
use App\Models\Order\Order;
use App\Models\Order\OrderItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Fulfils outstanding backorders when stock becomes available.
 *
 * A backordered line is one `OrderService::create()` accepted short: the
 * available units were committed, the shortfall was recorded as
 * `quantity_backordered`, and the order was stamped `backordered_at`. When
 * stock later arrives (a purchase-order receipt, a manual adjustment, a
 * restock), this service converts the shortfall into fulfilled units through
 * the same audited path order creation uses — bin consume, serial/batch
 * allocation, and the `order_fulfillment` ledger — oldest order first, up to
 * what is now available. An order clears `backordered_at` once none of its
 * lines has units left on backorder.
 *
 * Reconciliation is data-driven: it keys off `backordered_at` and
 * `quantity_backordered`, never the org master switch, so disabling the switch
 * never strands stock already on backorder. It only touches OPEN orders
 * (pending / processing) and re-checks each order's status under its row lock,
 * so a reconcile racing a cancel has a defined winner (the cancel wins and the
 * reconcile skips the no-longer-open order).
 */
final class BackorderService
{
    public function __construct(private readonly OrderService $orders) {}

    /**
     * Fulfil outstanding backorders for one product (or variant), oldest order
     * first, up to currently available stock. Returns the units fulfilled.
     */
    public function reconcileForProduct(int $organizationId, int $productId, ?int $variantId = null): int
    {
        // Cheap guard so every stock increase does not open a transaction.
        if (! $this->hasOpenBackorder($organizationId, $productId, $variantId)) {
            return 0;
        }

        return DB::transaction(function () use ($organizationId, $productId, $variantId) {
            // Lock the candidate orders BEFORE the product/variant rows, in id
            // order. cancel(), replaceItems() and reconcileOrder() all lock the
            // order first and the product second; taking the product first
            // here would invert that order and deadlock against them.
            $orderIds = $this->openBackorderedQuery($organizationId, $productId, $variantId)
                ->distinct()
                ->pluck('order_id')
                ->sort()
                ->values();
            $orders = Order::whereIn('id', $orderIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            $product = Product::withTrashed()
                ->where('organization_id', $organizationId)
                ->whereKey($productId)
                ->lockForUpdate()
                ->first();
            if ($product === null) {
                return 0;
            }

            $variant = null;
            if ($variantId !== null) {
                $variant = ProductVariant::withTrashed()->whereKey($variantId)->lockForUpdate()->first();
                if ($variant === null) {
                    return 0;
                }
            }
            $target = $variant ?? $product;

            $reserved = app(StockReservationService::class)->reservedTotal($productId, $variantId);
            $onHand = (int) $target->stock;
            $fulfilled = 0;

            foreach ($this->openBackorderedItems($organizationId, $productId, $variantId) as $item) {
                if ($onHand - $reserved <= 0) {
                    break; // nothing left to give
                }

                $order = $orders->get($item->order_id);
                if (! $this->isOpenBackorder($order)) {
                    continue; // a cancel/ship won the race, or it already cleared
                }

                $give = min((int) $item->quantity_backordered, $onHand - $reserved);
                if ($give <= 0) {
                    continue;
                }

                $this->orders->fulfilLine(
                    $order, $product, $variant, $item, $give,
                    "Order {$order->order_number} backorder fulfilled",
                );
                $item->decrement('quantity_backordered', $give);
                $onHand -= $give;
                $fulfilled += $give;

                $this->clearIfComplete($order);
            }

            return $fulfilled;
        });
    }

    /**
     * Fulfil a single order's backorders as far as stock allows (the manual
     * "Fulfill backorder" action). Returns the refreshed order.
     */
    public function reconcileOrder(Order $order, ?User $actor = null): Order
    {
        DB::transaction(function () use ($order, $actor) {
            $locked = Order::whereKey($order->getKey())->lockForUpdate()->first();
            if (! $this->isOpenBackorder($locked)) {
                return;
            }

            $locked->load('items');
            $reservations = app(StockReservationService::class);

            foreach ($locked->items as $item) {
                if ((int) $item->quantity_backordered <= 0) {
                    continue;
                }

                // withTrashed, like reconcileForProduct(): a product deleted
                // since the order was placed still fulfils its backorder.
                $product = Product::withTrashed()->whereKey($item->product_id)->lockForUpdate()->first();
                if ($product === null) {
                    continue;
                }
                $variant = $item->product_variant_id !== null
                    ? ProductVariant::withTrashed()->whereKey($item->product_variant_id)->lockForUpdate()->first()
                    : null;
                if ($item->product_variant_id !== null && $variant === null) {
                    continue; // never fulfil a variant line from the parent's stock
                }
                $target = $variant ?? $product;

                $available = (int) $target->stock - $reservations->reservedTotal((int) $item->product_id, $variant?->id);
                $give = min((int) $item->quantity_backordered, max(0, $available));
                if ($give <= 0) {
                    continue;
                }

                $this->orders->fulfilLine(
                    $locked, $product, $variant, $item, $give,
                    "Order {$locked->order_number} backorder fulfilled",
                    $actor, $actor,
                );
                $item->decrement('quantity_backordered', $give);
            }

            $this->clearIfComplete($locked);
        });

        return $order->fresh();
    }

    /**
     * Sweep every product with an open backorder and reconcile it. The
     * scheduled safety net, so no stock-increase path can strand a backorder.
     * Returns the total units fulfilled.
     */
    public function reconcileAll(?int $organizationId = null): int
    {
        $groups = OrderItem::query()
            ->where('quantity_backordered', '>', 0)
            ->whereHas('order', fn ($query) => $query
                ->whereNotNull('backordered_at')
                ->whereIn('status', $this->openStatuses())
                ->when($organizationId !== null, fn ($q) => $q->where('organization_id', $organizationId)))
            ->with('order:id,organization_id')
            ->get()
            ->map(fn (OrderItem $item) => [
                'org' => (int) $item->order->organization_id,
                'product' => (int) $item->product_id,
                'variant' => $item->product_variant_id !== null ? (int) $item->product_variant_id : null,
            ])
            ->unique(fn (array $group) => $group['org'].'-'.$group['product'].'-'.($group['variant'] ?? 'null'))
            ->values();

        $total = 0;
        foreach ($groups as $group) {
            $total += $this->reconcileForProduct($group['org'], $group['product'], $group['variant']);
        }

        return $total;
    }

    private function hasOpenBackorder(int $organizationId, int $productId, ?int $variantId): bool
    {
        return $this->openBackorderedQuery($organizationId, $productId, $variantId)->exists();
    }

    /**
     * Open backordered lines for a product/variant, oldest order first (by
     * backordered_at, then order id).
     *
     * @return \Illuminate\Support\Collection<int, OrderItem>
     */
    private function openBackorderedItems(int $organizationId, int $productId, ?int $variantId)
    {
        return $this->openBackorderedQuery($organizationId, $productId, $variantId)
            ->with('order')
            ->get()
            ->sortBy(fn (OrderItem $item) => sprintf(
                '%020d-%020d',
                $item->order->backordered_at?->getTimestamp() ?? 0,
                (int) $item->order_id,
            ))
            ->values();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<OrderItem>
     */
    private function openBackorderedQuery(int $organizationId, int $productId, ?int $variantId)
    {
        return OrderItem::query()
            ->where('product_id', $productId)
            ->where('quantity_backordered', '>', 0)
            ->where(fn ($query) => $variantId === null
                ? $query->whereNull('product_variant_id')
                : $query->where('product_variant_id', $variantId))
            ->whereHas('order', fn ($query) => $query
                ->where('organization_id', $organizationId)
                ->whereNotNull('backordered_at')
                ->whereIn('status', $this->openStatuses()));
    }

    private function isOpenBackorder(?Order $order): bool
    {
        return $order !== null
            && $order->backordered_at !== null
            && in_array($order->status, [OrderStatus::PENDING, OrderStatus::PROCESSING], true);
    }

    private function clearIfComplete(Order $order): void
    {
        // Query the lines directly rather than reloading the whole order.
        if (! $order->items()->where('quantity_backordered', '>', 0)->exists()) {
            $order->update(['backordered_at' => null]);
        }
    }

    /**
     * @return array<int, string>
     */
    private function openStatuses(): array
    {
        return [OrderStatus::PENDING->value, OrderStatus::PROCESSING->value];
    }
}
