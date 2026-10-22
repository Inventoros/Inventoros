<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\InsufficientStockException;
use App\Exceptions\InvalidStateException;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductLocation;
use App\Models\Inventory\ProductVariant;
use App\Models\Inventory\StockAdjustment;
use App\Models\Inventory\StockTransfer;
use App\Models\Inventory\StockTransferItem;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * The single implementation of the stock-transfer lifecycle: create, ship
 * (mark in transit), complete (move stock between location bins) and cancel.
 *
 * Shared by the web StockTransferController and the REST API so the location
 * bin move, product locking and ledger rows cannot drift between surfaces.
 * Warehouse access is enforced here (WarehouseAccessService), so a user
 * restricted to some warehouses is held to them on every surface.
 * State violations throw InvalidStateException; a short source bin throws the
 * RuntimeException raised by ProductLocationStockService::move().
 */
final class StockTransferService
{
    public function __construct(
        private readonly ProductLocationStockService $locationStock,
        private readonly WarehouseAccessService $warehouseAccess,
        private readonly ApprovalService $approvals,
    ) {}

    /**
     * Create a pending transfer between two locations of the organization.
     *
     * @param  array{from_location_id: int, to_location_id: int, notes?: string|null, shipping_method?: string|null, tracking_number?: string|null, estimated_arrival?: string|null, items: array<int, array{product_id: int, quantity: int, notes?: string|null}>}  $data
     */
    public function create(int $organizationId, User $actor, array $data): StockTransfer
    {
        // Verify locations belong to the user's organization
        $fromLocation = ProductLocation::where('id', $data['from_location_id'])
            ->forOrganization($organizationId)
            ->firstOrFail();

        $toLocation = ProductLocation::where('id', $data['to_location_id'])
            ->forOrganization($organizationId)
            ->firstOrFail();

        // Stock can only leave a warehouse the actor works in; any location
        // may be the destination.
        $this->warehouseAccess->authorizeLocation($actor, $fromLocation);

        // Determine if this is an inter-warehouse transfer
        $fromWarehouseId = $fromLocation->warehouse_id;
        $toWarehouseId = $toLocation->warehouse_id;
        $isInterWarehouse = $fromWarehouseId && $toWarehouseId && $fromWarehouseId !== $toWarehouseId;

        $transfer = DB::transaction(function () use ($data, $organizationId, $actor, $isInterWarehouse, $fromWarehouseId, $toWarehouseId) {
            $transferData = [
                'organization_id' => $organizationId,
                'transfer_number' => StockTransfer::generateTransferNumber($organizationId),
                'from_location_id' => $data['from_location_id'],
                'to_location_id' => $data['to_location_id'],
                'transferred_by' => $actor->id,
                'status' => 'pending',
                'notes' => $data['notes'] ?? null,
                'is_inter_warehouse' => $isInterWarehouse,
            ];

            if ($isInterWarehouse) {
                $transferData['from_warehouse_id'] = $fromWarehouseId;
                $transferData['to_warehouse_id'] = $toWarehouseId;
                $transferData['shipping_method'] = $data['shipping_method'] ?? null;
                $transferData['tracking_number'] = $data['tracking_number'] ?? null;
                $transferData['estimated_arrival'] = $data['estimated_arrival'] ?? null;
            }

            $transfer = StockTransfer::create($transferData);

            foreach ($data['items'] as $item) {
                // Verify product belongs to organization
                $product = Product::where('id', $item['product_id'])
                    ->forOrganization($organizationId)
                    ->firstOrFail();

                $variantId = ! empty($item['product_variant_id']) ? (int) $item['product_variant_id'] : null;
                if ($variantId !== null) {
                    ProductVariant::where('organization_id', $organizationId)->where('product_id', $product->id)->findOrFail($variantId);
                } elseif ($product->has_variants && $product->variants()->exists()) {
                    throw \Illuminate\Validation\ValidationException::withMessages(['items' => 'Choose a variant for each product sold by variant.']);
                }

                StockTransferItem::create([
                    'product_variant_id' => $variantId,
                    'stock_transfer_id' => $transfer->id,
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                    'notes' => $item['notes'] ?? null,
                ]);
            }

            // Held for approval when the organization requires it (no-op
            // otherwise), inside the creating transaction: a transfer is
            // never visible, and so never completable, before its hold.
            return $this->approvals->holdTransferIfRequired($transfer, $actor);
        });

        return $transfer;
    }

    /** Reserve source-bin stock without moving the on-hand total. */
    public function prepare(StockTransfer $transfer, User $actor): StockTransfer
    {
        $this->authorizeTransfer($actor, $transfer);
        return DB::transaction(function () use ($transfer, $actor): StockTransfer {
            $locked = StockTransfer::whereKey($transfer->id)->lockForUpdate()->firstOrFail();
            $this->warehouseAccess->authorizeLocation($actor, $locked->from_location_id);
            if (! in_array($locked->status, ['pending', 'prepared'], true)) {
                throw new InvalidStateException('Only pending or prepared transfers can be prepared.', 'invalid_status');
            }
            $this->approvals->assertTransferMayProceed($locked);
            $holds = app(StockReservationService::class);
            foreach ($this->stockTargets($locked) as $line) {
                $holds->reserve($line['product'], $line['variant'], $line['quantity'], 'core', $this->holdReference($locked), $locked->from_location_id);
            }
            $locked->update(['status' => 'prepared', 'prepared_at' => $locked->prepared_at ?? now()]);
            $transfer->setRawAttributes($locked->getAttributes(), true);
            return $transfer;
        });
    }

    /**
     * Mark a pending transfer as shipped (in transit).
     *
     * @param  array{shipping_method?: string|null, tracking_number?: string|null, estimated_arrival?: string|null}  $data
     */
    public function ship(StockTransfer $transfer, User $actor, array $data = []): StockTransfer
    {
        $this->authorizeTransfer($actor, $transfer);

        return DB::transaction(function () use ($transfer, $actor, $data) {
            $locked = StockTransfer::whereKey($transfer->getKey())->lockForUpdate()->firstOrFail();
            $this->authorizeTransfer($actor, $locked);

            if (! in_array($locked->status, ['pending', 'prepared'], true)) {
                throw new InvalidStateException('Only pending or prepared transfers can be shipped.', 'invalid_status');
            }
            $this->warehouseAccess->authorizeLocation($actor, $locked->from_location_id);
            $this->prepare($locked, $actor);
            $lines = $this->stockTargets($locked);
            app(StockReservationService::class)->consume('core', $this->holdReference($locked), $locked, $locked->organization_id);
            foreach ($lines as $line) {
                $this->adjustLine($locked, $line, -$line['quantity'], 'transfer_shipped', $locked->from_location_id, $actor);
            }

            // Serialize with completion and approval decisions. A stale ship
            // request must never reopen a transfer whose stock already moved.
            $this->approvals->assertTransferMayProceed($locked);

            $updateData = [
                'status' => 'in_transit',
                'shipped_at' => now(),
                'stock_dispatched_at' => now(),
            ];

            foreach (['shipping_method', 'tracking_number', 'estimated_arrival'] as $field) {
                if (isset($data[$field])) {
                    $updateData[$field] = $data[$field];
                }
            }

            $locked->update($updateData);
            $transfer->setRawAttributes($locked->getAttributes(), true);

            return $transfer;
        });
    }

    /**
     * Complete a pending or in-transit transfer, moving each line's quantity
     * from the source location bin to the destination bin.
     */
    public function complete(StockTransfer $stockTransfer, User $actor): StockTransfer
    {
        $this->authorizeTransfer($actor, $stockTransfer);

        if (! in_array($stockTransfer->status, ['pending', 'prepared', 'in_transit'], true)) {
            throw new InvalidStateException('Only pending or in-transit transfers can be completed.', 'invalid_status');
        }

        // A transfer held for approval (or rejected) may not complete.
        $this->approvals->assertTransferMayProceed($stockTransfer);

        DB::transaction(function () use ($stockTransfer, $actor) {
            // Re-read under a row lock so two concurrent completions serialize
            // here; the second observes 'completed' and is refused instead of
            // moving the goods twice.
            $locked = StockTransfer::whereKey($stockTransfer->getKey())->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, ['pending', 'prepared', 'in_transit'], true)) {
                throw new InvalidStateException('Only pending or in-transit transfers can be completed.', 'invalid_status');
            }

            // The pre-transaction approval check read an unlocked instance; a
            // hold (or rejection) that landed since must still stop the move.
            $this->approvals->assertTransferMayProceed($locked);

            if ($locked->stock_dispatched_at !== null) {
                $this->warehouseAccess->authorizeLocation($actor, $locked->to_location_id);
                foreach ($this->stockTargets($locked, true) as $line) {
                    $this->adjustLine($locked, $line, $line['quantity'], 'transfer_received', $locked->to_location_id, $actor);
                    if ($line['variant'] === null) {
                        $line['product']->update(['location_id' => $locked->to_location_id]);
                    }
                }
                $locked->update(['status' => 'completed', 'completed_at' => now(), 'completed_by' => $actor->id]);
                $stockTransfer->setRawAttributes($locked->getAttributes(), true);
                return;
            }
            $this->stockTargets($locked);
            if ($locked->status === 'prepared') {
                app(StockReservationService::class)->consume('core', $this->holdReference($locked), $locked, $locked->organization_id);
            }
            $stockTransfer->load('items.variant', 'fromLocation', 'toLocation');

            // Lock every product row up front in ascending id order (products
            // before bins, the convention every stock path follows), so two
            // transfers sharing products cannot deadlock by locking them in
            // opposite line orders. Held for the whole transaction so no
            // concurrent movement can pass the stock check on the same
            // pre-image.
            $products = Product::query()
                ->whereIn('id', $stockTransfer->items->pluck('product_id')->unique()->sort()->values()->all())
                ->where('organization_id', $stockTransfer->organization_id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            foreach ($stockTransfer->items as $item) {
                $product = $products->get($item->product_id)
                    ?? throw (new ModelNotFoundException)->setModel(Product::class, [$item->product_id]);

                $target = $item->product_variant_id !== null ? ProductVariant::where('product_id', $product->id)->whereKey($item->product_variant_id)->lockForUpdate()->firstOrFail() : $product;
                if ($target->stock < $item->quantity) {
                    throw new InsufficientStockException(
                        "Insufficient stock for {$product->name}: have {$product->stock}, transfer requires {$item->quantity}."
                    );
                }

                // Move the quantity between the source and destination location
                // bins. products.stock (the total on hand) is unchanged — the
                // goods are still owned, just in a different place — but the
                // per-location breakdown now reflects the move, so the source
                // bin can no longer be over-drawn by a later transfer. Guards
                // the source bin (throws if short).
                $this->locationStock->move(
                    $target,
                    $stockTransfer->from_location_id,
                    $stockTransfer->to_location_id,
                    $item->quantity,
                );

                // Repoint the product's primary location to the destination so
                // single-location views keep matching where the bulk of the
                // goods moved.
                if ($item->product_variant_id === null) {
                    $product->update(['location_id' => $stockTransfer->to_location_id]);
                }

                // Record one audit row per item. products.stock does not change
                // on a transfer (the move is between locations, tracked in
                // product_location_stocks), so adjustment_quantity is 0; the
                // reason captures the source and destination for the ledger.
                StockAdjustment::create([
                    'organization_id' => $stockTransfer->organization_id,
                    'product_id' => $product->id,
                    'product_variant_id' => $item->product_variant_id,
                    'user_id' => $actor->id,
                    'type' => 'transfer',
                    'quantity_before' => $target->stock,
                    'quantity_after' => $target->stock,
                    'adjustment_quantity' => 0,
                    'reason' => "Transfer {$item->quantity} from {$stockTransfer->fromLocation->name} to {$stockTransfer->toLocation->name}",
                    'notes' => "Transfer #{$stockTransfer->transfer_number}",
                    'reference_type' => StockTransfer::class,
                    'reference_id' => $stockTransfer->id,
                ]);
            }

            $stockTransfer->update([
                'status' => 'completed',
                'completed_at' => now(),
                'completed_by' => $actor->id,
            ]);
        });

        return $stockTransfer;
    }

    /**
     * Cancel a pending or in-transit transfer. No stock has moved yet.
     */
    public function cancel(StockTransfer $transfer, User $actor): StockTransfer
    {
        $this->authorizeTransfer($actor, $transfer);

        return DB::transaction(function () use ($transfer, $actor) {
            $locked = StockTransfer::whereKey($transfer->getKey())->lockForUpdate()->firstOrFail();
            $this->authorizeTransfer($actor, $locked);

            if (! in_array($locked->status, ['pending', 'prepared', 'in_transit'], true)) {
                throw new InvalidStateException('Only pending or in-transit transfers can be cancelled.', 'invalid_status');
            }

            if ($locked->stock_dispatched_at !== null) {
                throw new InvalidStateException('A shipped transfer must be received. Use a reverse transfer to return its goods.', 'already_shipped');
            }
            app(StockReservationService::class)->release('core', $this->holdReference($locked), $locked->organization_id);
            $locked->update(['status' => 'cancelled']);
            $transfer->setRawAttributes($locked->getAttributes(), true);

            return $transfer;
        });
    }

    /**
     * A transfer is visible to, and actionable by, either end of the move.
     *
     * @throws AuthorizationException
     */
    public function authorizeTransfer(User $actor, StockTransfer $transfer): void
    {
        if ((int) $actor->organization_id !== (int) $transfer->organization_id) {
            throw new AuthorizationException('This transfer belongs to another organization.');
        }
        $this->warehouseAccess->authorizeAnyLocation($actor, [$transfer->from_location_id, $transfer->to_location_id]);
    }
    private function holdReference(StockTransfer $transfer): string
    {
        return 'stock-transfer:'.$transfer->id;
    }

    /** @return list<array{product: Product, variant: ?ProductVariant, quantity: int}> */
    private function stockTargets(StockTransfer $transfer, bool $allowArchived = false): array
    {
        $transfer->load('items');
        $products = Product::query()->when($allowArchived, fn ($q) => $q->withTrashed())
            ->where('organization_id', $transfer->organization_id)->whereIn('id', $transfer->items->pluck('product_id')->unique()->sort()->values()->all())
            ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $variants = ProductVariant::query()->when($allowArchived, fn ($q) => $q->withTrashed())
            ->where('organization_id', $transfer->organization_id)->whereIn('id', $transfer->items->pluck('product_variant_id')->filter()->unique()->sort()->values()->all())
            ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $targets = [];
        foreach ($transfer->items as $item) {
            $product = $products->get($item->product_id) ?? throw (new ModelNotFoundException)->setModel(Product::class, [$item->product_id]);
            $variant = $item->product_variant_id !== null ? $variants->get($item->product_variant_id) : null;
            if ($item->product_variant_id !== null && ($variant === null || (int) $variant->product_id !== (int) $product->id)) {
                throw new InvalidStateException('A transfer variant no longer matches its product.', 'invalid_variant');
            }
            if ($variant === null && $product->has_variants && $product->variants()->exists()) {
                throw new InvalidStateException('Choose a variant for each product sold by variant.', 'variant_required');
            }
            $key = $product->id.':'.($variant?->id ?? 0);
            $targets[$key] ??= ['product' => $product, 'variant' => $variant, 'quantity' => 0];
            $targets[$key]['quantity'] += (int) $item->quantity;
        }
        return array_values($targets);
    }

    private function adjustLine(StockTransfer $transfer, array $line, int $quantity, string $type, int $locationId, User $actor): void
    {
        if ($line['variant'] !== null) {
            StockAdjustment::adjustVariant($line['variant'], $quantity, $type, 'Transfer '.$transfer->transfer_number, reference: $transfer, allowNegative: false, actor: $actor, locationId: $locationId);
        } else {
            StockAdjustment::adjust($line['product'], $quantity, $type, 'Transfer '.$transfer->transfer_number, reference: $transfer, allowNegative: false, actor: $actor, locationId: $locationId);
        }
    }

}
