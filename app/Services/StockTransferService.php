<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\InvalidStateException;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductLocation;
use App\Models\Inventory\StockAdjustment;
use App\Models\Inventory\StockTransfer;
use App\Models\Inventory\StockTransferItem;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
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

        return DB::transaction(function () use ($data, $organizationId, $actor, $isInterWarehouse, $fromWarehouseId, $toWarehouseId) {
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
                Product::where('id', $item['product_id'])
                    ->forOrganization($organizationId)
                    ->firstOrFail();

                StockTransferItem::create([
                    'stock_transfer_id' => $transfer->id,
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                    'notes' => $item['notes'] ?? null,
                ]);
            }

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

        if ($transfer->status !== 'pending') {
            throw new InvalidStateException('Only pending transfers can be marked as in transit.', 'invalid_status');
        }

        $updateData = [
            'status' => 'in_transit',
            'shipped_at' => now(),
        ];

        foreach (['shipping_method', 'tracking_number', 'estimated_arrival'] as $field) {
            if (isset($data[$field])) {
                $updateData[$field] = $data[$field];
            }
        }

        $transfer->update($updateData);

        return $transfer;
    }

    /**
     * Complete a pending or in-transit transfer, moving each line's quantity
     * from the source location bin to the destination bin.
     */
    public function complete(StockTransfer $stockTransfer, User $actor): StockTransfer
    {
        $this->authorizeTransfer($actor, $stockTransfer);

        if (! in_array($stockTransfer->status, ['pending', 'in_transit'], true)) {
            throw new InvalidStateException('Only pending or in-transit transfers can be completed.', 'invalid_status');
        }

        DB::transaction(function () use ($stockTransfer, $actor) {
            // Re-read under a row lock so two concurrent completions serialize
            // here; the second observes 'completed' and is refused instead of
            // moving the goods twice.
            $locked = StockTransfer::whereKey($stockTransfer->getKey())->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, ['pending', 'in_transit'], true)) {
                throw new InvalidStateException('Only pending or in-transit transfers can be completed.', 'invalid_status');
            }

            $stockTransfer->load('items', 'fromLocation', 'toLocation');

            foreach ($stockTransfer->items as $item) {
                // Lock the product row for the duration of this transaction so two
                // concurrent transfers cannot pass the stock check based on the
                // same pre-image.
                $product = Product::where('id', $item->product_id)
                    ->where('organization_id', $stockTransfer->organization_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($product->stock < $item->quantity) {
                    throw new \RuntimeException(
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
                    $product,
                    $stockTransfer->from_location_id,
                    $stockTransfer->to_location_id,
                    $item->quantity,
                );

                // Repoint the product's primary location to the destination so
                // single-location views keep matching where the bulk of the
                // goods moved.
                $product->update(['location_id' => $stockTransfer->to_location_id]);

                // Record one audit row per item. products.stock does not change
                // on a transfer (the move is between locations, tracked in
                // product_location_stocks), so adjustment_quantity is 0; the
                // reason captures the source and destination for the ledger.
                StockAdjustment::create([
                    'organization_id' => $stockTransfer->organization_id,
                    'product_id' => $product->id,
                    'user_id' => $actor->id,
                    'type' => 'transfer',
                    'quantity_before' => $product->stock,
                    'quantity_after' => $product->stock,
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

        if (! in_array($transfer->status, ['pending', 'in_transit'], true)) {
            throw new InvalidStateException('Only pending or in-transit transfers can be cancelled.', 'invalid_status');
        }

        $transfer->update(['status' => 'cancelled']);

        return $transfer;
    }

    /**
     * A transfer is visible to, and actionable by, either end of the move.
     *
     * @throws AuthorizationException
     */
    public function authorizeTransfer(User $actor, StockTransfer $transfer): void
    {
        $this->warehouseAccess->authorizeAnyLocation($actor, [$transfer->from_location_id, $transfer->to_location_id]);
    }
}
