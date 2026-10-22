<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\TrackingType;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\InvalidStateException;
use App\Models\Inventory\OrderItemBatchAllocation;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductBatch;
use App\Models\Inventory\ProductSerial;
use App\Models\Inventory\WorkOrderAllocation;
use App\Models\Inventory\WorkOrderItem;
use App\Models\Inventory\StockReservation;
use App\Models\Order\OrderItem;
use Illuminate\Database\Eloquent\Collection;

/**
 * Allocates and releases tracked units (serials) as orders are fulfilled and
 * unwound, so the tracking records move with the goods instead of drifting.
 *
 * This is the first step toward the tracking records being the source of
 * truth for stock. It is deliberately best-effort during the transition: a
 * tracked product is only allocated when it actually has enough serials /
 * batch quantity, so order creation keeps working exactly as before for
 * products whose tracking records have not been populated yet. The #178
 * reconciliation surfaces any remaining drift.
 */
final class TrackedStockAllocationService
{
    /**
     * Allocate tracked units to a fulfilled order line, dispatching on the
     * product's tracking type: serials are marked sold and pinned to the line;
     * batches are consumed FEFO and recorded per batch. Skips (returns 0) any
     * product that is not tracked, or does not have at least $quantity
     * available.
     *
     * Runs inside the order transaction, which already holds a row lock on the
     * product; the tracking records are additionally locked here.
     *
     * Serials and batch units held by an active stock reservation are never
     * allocated. $consumedHolds are the reservation holds the order is
     * consuming (see OrderService::create()): their serials and batch units
     * are allocated first, so the hold's consumer gets exactly what it held.
     *
     * @param  Collection<int, StockReservation>|null  $consumedHolds
     * @return int the number of units allocated (0 when skipped)
     *
     * @api
     */
    public function allocateForOrderItem(Product $product, int $quantity, OrderItem $orderItem, ?Collection $consumedHolds = null): int
    {
        if ($quantity <= 0) {
            return 0;
        }

        $mine = ($consumedHolds ?? new Collection)->where('product_id', $product->id);

        return match ($this->trackingType($product)) {
            TrackingType::SERIAL => $this->allocateSerials(
                $product, $quantity, $orderItem,
                $mine->pluck('product_serial_id')->filter()->map(fn ($id) => (int) $id)->values()->all(),
            ),
            TrackingType::BATCH => $this->allocateBatches(
                $product, $quantity, $orderItem,
                $mine->whereNotNull('product_batch_id')->groupBy('product_batch_id')
                    ->map(fn ($holds) => (int) $holds->sum('quantity'))->all(),
            ),
            default => 0,
        };
    }

    /**
     * Release whatever tracked units this order line consumed — serials back to
     * available, batch quantities back to their batches. No-op when nothing was
     * allocated (untracked products and best-effort skips).
     *
     * $limit caps how many units are released: null releases everything the
     * line still holds (order cancel/delete/edit), while a partial return
     * passes the returned quantity so only those units come back and the rest
     * stay allocated to the still-open order.
     *
     * @return int the number of units released
     *
     * @api
     */
    public function releaseForOrderItem(OrderItem $orderItem, ?int $limit = null): int
    {
        return $this->releaseSerials($orderItem, $limit) + $this->releaseBatches($orderItem, $limit);
    }

    /**
     * Mark the oldest available serials sold and pin them to the order line,
     * the $preferred ones (a consumed reservation's) first. Serials held by
     * an active reservation are skipped. Skips unless at least $quantity
     * serials are available.
     *
     * @param  array<int, int>  $preferred
     */
    private function allocateSerials(Product $product, int $quantity, OrderItem $orderItem, array $preferred = []): int
    {
        $held = app(StockReservationService::class)->heldSerialIds((int) $product->id);

        $query = ProductSerial::query()
            ->where('product_id', $product->id)
            ->where('status', ProductSerial::STATUS_AVAILABLE)
            ->when($held !== [], fn ($q) => $q->whereNotIn('id', $held));

        if ($preferred !== []) {
            $query->orderByRaw('case when id in ('.implode(',', array_fill(0, count($preferred), '?')).') then 0 else 1 end', $preferred);
        }

        $available = $query
            ->orderBy('id')
            ->lockForUpdate()
            ->limit($quantity)
            ->get();

        if ($available->count() < $quantity) {
            if ($this->strictMode()) {
                throw new InsufficientStockException(
                    "Not enough tracked serials for {$product->name}: {$available->count()} available, {$quantity} required."
                );
            }

            // Not fully tracked — leave the serials alone rather than
            // partially allocating or failing the order.
            return 0;
        }

        foreach ($available as $serial) {
            // order_item_id is not mass-assignable; set it directly.
            $serial->status = ProductSerial::STATUS_SOLD;
            $serial->order_item_id = $orderItem->id;
            $serial->save();
        }

        return $available->count();
    }

    private function releaseSerials(OrderItem $orderItem, ?int $limit = null): int
    {
        if ($limit !== null && $limit <= 0) {
            return 0;
        }

        $query = ProductSerial::query()
            ->where('order_item_id', $orderItem->id)
            ->where('status', ProductSerial::STATUS_SOLD)
            ->orderBy('id')
            ->lockForUpdate();

        if ($limit !== null) {
            $query->limit($limit);
        }

        $serials = $query->get();

        foreach ($serials as $serial) {
            $serial->status = ProductSerial::STATUS_AVAILABLE;
            $serial->order_item_id = null;
            $serial->save();
        }

        return $serials->count();
    }

    /**
     * Consume $quantity from the product's batches, first-expiry-first-out
     * (earliest expiry first, undated batches last), recording how much came
     * from each batch. Skips unless the batches together hold at least
     * $quantity, so an under-tracked product is left untouched.
     *
     * Units held by an active reservation are not allocatable. $preferred
     * (batch id => units of a consumed reservation) are drawn first.
     *
     * @param  array<int, int>  $preferred
     */
    private function allocateBatches(Product $product, int $quantity, OrderItem $orderItem, array $preferred = []): int
    {
        $batches = ProductBatch::query()
            ->where('product_id', $product->id)
            ->where('quantity', '>', 0)
            // expiry_date IS NULL sorts undated batches last; then soonest
            // expiry first, then oldest row.
            ->orderByRaw('expiry_date is null')
            ->orderBy('expiry_date')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $reserved = app(StockReservationService::class)->reservedByBatch((int) $product->id);

        // A plugin may hold units of a batch back from allocation (a
        // quarantined or recalled lot) through batch_allocatable_quantity;
        // held units neither count toward coverage nor get consumed. Units
        // reserved for someone else are held back the same way.
        $allocatable = [];
        foreach ($batches as $batch) {
            $allocatable[$batch->id] = max(0, $this->allocatableQuantity($batch, $product, $orderItem) - ($reserved[$batch->id] ?? 0));
        }

        // A consumed reservation's batches go first, then FEFO as usual.
        if ($preferred !== []) {
            $batches = $batches->sortBy(fn (ProductBatch $batch) => isset($preferred[$batch->id]) ? 0 : 1)->values();
        }

        $totalAvailable = array_sum($allocatable);

        if ($totalAvailable < $quantity) {
            if ($this->strictMode()) {
                throw new InsufficientStockException(
                    "Not enough tracked batch quantity for {$product->name}: {$totalAvailable} available, {$quantity} required."
                );
            }

            return 0;
        }

        $remaining = $quantity;

        foreach ($batches as $batch) {
            if ($remaining <= 0) {
                break;
            }

            $take = min($remaining, $allocatable[$batch->id]);

            if ($take <= 0) {
                continue;
            }

            $batch->decrement('quantity', $take);

            OrderItemBatchAllocation::create([
                'organization_id' => $product->organization_id,
                'order_item_id' => $orderItem->id,
                'product_batch_id' => $batch->id,
                'quantity' => $take,
            ]);

            $remaining -= $take;
        }

        return $quantity;
    }

    /**
     * How many of a batch's units FEFO may draw on: the batch quantity unless
     * a batch_allocatable_quantity filter holds some back. The answer is
     * clamped to 0..quantity; anything that is not a number is ignored.
     */
    private function allocatableQuantity(ProductBatch $batch, Product $product, OrderItem|WorkOrderItem $line): int
    {
        $quantity = (int) $batch->quantity;
        $answer = apply_filters('batch_allocatable_quantity', $quantity, $batch, $product, $line);

        if (! is_int($answer) && ! (is_string($answer) && is_numeric($answer)) && ! is_float($answer)) {
            return $quantity;
        }

        return max(0, min($quantity, (int) $answer));
    }

    private function releaseBatches(OrderItem $orderItem, ?int $limit = null): int
    {
        if ($limit !== null && $limit <= 0) {
            return 0;
        }

        $allocations = OrderItemBatchAllocation::query()
            ->where('order_item_id', $orderItem->id)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $released = 0;
        $remaining = $limit; // null = unlimited

        foreach ($allocations as $allocation) {
            if ($remaining !== null && $remaining <= 0) {
                break;
            }

            $restore = $remaining === null
                ? (int) $allocation->quantity
                : min($remaining, (int) $allocation->quantity);

            ProductBatch::query()
                ->whereKey($allocation->product_batch_id)
                ->increment('quantity', $restore);

            $released += $restore;

            if ($restore >= (int) $allocation->quantity) {
                $allocation->delete();
            } else {
                // Partial restore: the allocation keeps the still-consumed
                // remainder so a later cancel returns exactly the rest.
                $allocation->decrement('quantity', $restore);
            }

            if ($remaining !== null) {
                $remaining -= $restore;
            }
        }

        return $released;
    }

    /**
     * How many tracked units a work order may draw on: available serials for
     * a serial-tracked product, allocatable batch quantity for a batch-tracked
     * one. Null for a product that is not tracked.
     */
    public function availableForProduction(Product $product, WorkOrderItem $line): ?int
    {
        return match ($this->trackingType($product)) {
            TrackingType::SERIAL => ProductSerial::withoutGlobalScopes()
                ->where('organization_id', $product->organization_id)
                ->where('product_id', $product->id)
                ->where('status', ProductSerial::STATUS_AVAILABLE)
                ->count(),
            TrackingType::BATCH => (int) ProductBatch::withoutGlobalScopes()
                ->where('organization_id', $product->organization_id)
                ->where('product_id', $product->id)
                ->where('quantity', '>', 0)
                ->get()
                ->sum(fn (ProductBatch $batch) => $this->allocatableQuantity($batch, $product, $line)),
            default => null,
        };
    }

    /**
     * Consume tracked units for a work order component line and record each
     * serial / batch quantity against the line. Unlike order allocation this
     * is always strict: production must say which units it used, so a
     * tracked component that cannot be fully covered refuses the completion.
     *
     * $serialIds / $batches name the units the user chose; null picks them
     * the same way orders do (oldest serial first; batches first-expiry-first
     * with undated batches last).
     *
     * Runs inside the work order transaction, which already holds the product
     * row lock; the tracking rows are locked here in ascending id order.
     *
     * @param  list<int>|null  $serialIds
     * @param  list<array{product_batch_id:int, quantity:int}>|null  $batches
     *
     * @throws InsufficientStockException when the tracked units cannot cover $quantity
     * @throws InvalidStateException when a chosen serial or batch is not usable
     */
    public function consumeForProduction(Product $product, int $quantity, WorkOrderItem $line, ?array $serialIds = null, ?array $batches = null): void
    {
        if ($quantity <= 0) {
            return;
        }

        match ($this->trackingType($product)) {
            TrackingType::SERIAL => $this->consumeSerialsForProduction($product, $quantity, $line, $serialIds),
            TrackingType::BATCH => $this->consumeBatchesForProduction($product, $quantity, $line, $batches),
            default => null,
        };
    }

    /**
     * Return whatever a work order line consumed: serials back to available,
     * batch quantities back to their batches. The allocation rows are removed.
     *
     * @return int the number of units released
     */
    public function releaseForProduction(WorkOrderItem $line): int
    {
        $allocations = WorkOrderAllocation::withoutGlobalScopes()
            ->where('work_order_item_id', $line->id)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $released = 0;

        foreach ($allocations as $allocation) {
            if ($allocation->product_serial_id !== null) {
                ProductSerial::withoutGlobalScopes()
                    ->whereKey($allocation->product_serial_id)
                    ->where('status', ProductSerial::STATUS_CONSUMED)
                    ->update(['status' => ProductSerial::STATUS_AVAILABLE]);
            } elseif ($allocation->product_batch_id !== null) {
                ProductBatch::withoutGlobalScopes()
                    ->whereKey($allocation->product_batch_id)
                    ->increment('quantity', $allocation->quantity);
            }

            $released += $allocation->quantity;
            $allocation->delete();
        }

        return $released;
    }

    /**
     * @param  list<int>|null  $serialIds
     */
    private function consumeSerialsForProduction(Product $product, int $quantity, WorkOrderItem $line, ?array $serialIds): void
    {
        $query = ProductSerial::withoutGlobalScopes()
            ->where('organization_id', $product->organization_id)
            ->where('product_id', $product->id)
            ->orderBy('id')
            ->lockForUpdate();

        if ($serialIds === null) {
            $serials = $query->where('status', ProductSerial::STATUS_AVAILABLE)->limit($quantity)->get();

            if ($serials->count() < $quantity) {
                throw new InsufficientStockException(
                    "Not enough serials for {$product->name}: {$serials->count()} available, {$quantity} required."
                );
            }
        } else {
            $ids = array_map('intval', $serialIds);

            if (count($ids) !== count(array_unique($ids))) {
                throw new InvalidStateException("The same serial was chosen twice for {$product->name}.", 'invalid_serial');
            }

            if (count($ids) !== $quantity) {
                throw new InvalidStateException(
                    "{$product->name} needs exactly {$quantity} serials; ".count($ids).' were chosen.',
                    'serial_count_mismatch',
                );
            }

            $serials = $query->whereIn('id', $ids)->get();

            // A serial of another product or organization is reported exactly
            // like one that does not exist.
            if ($serials->count() !== count($ids)) {
                throw new InvalidStateException("A chosen serial was not found for {$product->name}.", 'invalid_serial');
            }

            $unavailable = $serials->first(fn (ProductSerial $serial) => $serial->status !== ProductSerial::STATUS_AVAILABLE);
            if ($unavailable !== null) {
                throw new InvalidStateException("Serial {$unavailable->serial_number} is not available.", 'serial_unavailable');
            }
        }

        foreach ($serials as $serial) {
            $serial->status = ProductSerial::STATUS_CONSUMED;
            $serial->save();

            WorkOrderAllocation::create([
                'organization_id' => $product->organization_id,
                'work_order_id' => $line->work_order_id,
                'work_order_item_id' => $line->id,
                'product_id' => $product->id,
                'product_serial_id' => $serial->id,
                'quantity' => 1,
            ]);
        }
    }

    /**
     * @param  list<array{product_batch_id:int, quantity:int}>|null  $chosen
     */
    private function consumeBatchesForProduction(Product $product, int $quantity, WorkOrderItem $line, ?array $chosen): void
    {
        $query = ProductBatch::withoutGlobalScopes()
            ->where('organization_id', $product->organization_id)
            ->where('product_id', $product->id)
            ->lockForUpdate();

        $plan = [];

        if ($chosen === null) {
            $batches = $query->where('quantity', '>', 0)
                // Undated batches last; then soonest expiry, then oldest row.
                ->orderByRaw('expiry_date is null')
                ->orderBy('expiry_date')
                ->orderBy('id')
                ->get();

            $remaining = $quantity;
            foreach ($batches as $batch) {
                if ($remaining <= 0) {
                    break;
                }
                $take = min($remaining, $this->allocatableQuantity($batch, $product, $line));
                if ($take > 0) {
                    $plan[] = [$batch, $take];
                    $remaining -= $take;
                }
            }

            if ($remaining > 0) {
                $available = $quantity - $remaining;
                throw new InsufficientStockException(
                    "Not enough batch quantity for {$product->name}: {$available} available, {$quantity} required."
                );
            }
        } else {
            $wanted = [];
            foreach ($chosen as $row) {
                $id = (int) $row['product_batch_id'];
                $wanted[$id] = ($wanted[$id] ?? 0) + (int) $row['quantity'];
            }

            if (array_sum($wanted) !== $quantity) {
                throw new InvalidStateException(
                    "{$product->name} needs exactly {$quantity} units from its batches; ".array_sum($wanted).' were chosen.',
                    'batch_quantity_mismatch',
                );
            }

            $batches = $query->whereIn('id', array_keys($wanted))->orderBy('id')->get();

            if ($batches->count() !== count($wanted)) {
                throw new InvalidStateException("A chosen batch was not found for {$product->name}.", 'invalid_batch');
            }

            foreach ($batches as $batch) {
                $take = $wanted[$batch->id];
                $allocatable = $this->allocatableQuantity($batch, $product, $line);
                if ($take > $allocatable) {
                    throw new InsufficientStockException(
                        "Batch {$batch->batch_number} has {$allocatable} units available; {$take} were chosen."
                    );
                }
                $plan[] = [$batch, $take];
            }
        }

        foreach ($plan as [$batch, $take]) {
            $batch->decrement('quantity', $take);

            WorkOrderAllocation::create([
                'organization_id' => $product->organization_id,
                'work_order_id' => $line->work_order_id,
                'work_order_item_id' => $line->id,
                'product_id' => $product->id,
                'product_batch_id' => $batch->id,
                'quantity' => $take,
            ]);
        }
    }

    /**
     * When strict mode is on, tracked records are authoritative: an order for
     * a tracked product must be fully covered by serials/batches or it is
     * rejected. Off (default) is best-effort — under-populated products are
     * left untouched.
     */
    private function strictMode(): bool
    {
        return (bool) config('inventory.strict_tracked_stock', false);
    }

    private function trackingType(Product $product): ?TrackingType
    {
        $type = $product->tracking_type;

        if ($type instanceof TrackingType) {
            return $type;
        }

        return $type === null ? null : TrackingType::tryFrom((string) $type);
    }
}
