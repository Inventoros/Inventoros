<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\InvalidStateException;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductLocation;
use App\Models\Inventory\ProductLocationStock;
use App\Models\Inventory\StockAdjustment;
use App\Models\Inventory\StockAudit;
use App\Models\Inventory\StockAuditItem;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The single implementation of the stock-audit (cycle count) write path:
 * create, start, record counts and complete.
 *
 * Shared by the web StockAuditController and the REST API so the snapshot of
 * system quantities, count recording and recount adjustments cannot drift
 * between surfaces. State violations throw InvalidStateException.
 */
final class StockAuditService
{
    public function __construct(
        private readonly WarehouseAccessService $warehouseAccess,
        private readonly StockAuditSnapshot $snapshot,
    ) {}

    /**
     * Create a draft audit and seed one item per product in scope.
     *
     * The lines are inserted with one INSERT ... SELECT (see
     * StockAuditSnapshot), so the cost does not grow with the inventory.
     *
     * @param  array{name: string, description?: string|null, audit_type: string, warehouse_location_id?: int|null, notes?: string|null, product_ids?: array<int, int>|null}  $data
     *
     * @api
     */
    public function create(int $organizationId, User $actor, array $data): StockAudit
    {
        // Verify location belongs to organization if provided
        if (! empty($data['warehouse_location_id'])) {
            ProductLocation::where('id', $data['warehouse_location_id'])
                ->forOrganization($organizationId)
                ->firstOrFail();
        }

        // A restricted user audits their own warehouses only; an audit with no
        // location spans the whole organization.
        $warehouseId = ! empty($data['warehouse_id']) ? (int) $data['warehouse_id'] : null;
        if ($warehouseId !== null) {
            Warehouse::forOrganization($organizationId)->findOrFail($warehouseId);
            $this->warehouseAccess->authorizeWarehouse($actor, $warehouseId);
            if (! empty($data['warehouse_location_id']) && ! ProductLocation::whereKey($data['warehouse_location_id'])->where('warehouse_id', $warehouseId)->exists()) {
                throw ValidationException::withMessages(['warehouse_location_id' => 'The location must be in the selected warehouse.']);
            }
        } else {
            $this->warehouseAccess->authorizeLocation($actor, $data['warehouse_location_id'] ?? null);
        }

        return DB::transaction(function () use ($data, $organizationId, $actor, $warehouseId) {
            $audit = StockAudit::create([
                'organization_id' => $organizationId,
                'audit_number' => StockAudit::generateAuditNumber($organizationId),
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'status' => 'draft',
                'audit_type' => $data['audit_type'],
                'warehouse_id' => $warehouseId,
                'warehouse_location_id' => $data['warehouse_location_id'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $actor->id,
            ]);

            // Get products to include in the audit
            $productQuery = Product::forOrganization($organizationId)->active();

            if (! empty($data['product_ids'])) {
                // Specific products selected
                $productQuery->whereIntegerInRaw('id', $data['product_ids']);
            } elseif (! empty($data['warehouse_location_id'])) {
                // Products assigned to the location, and products that have
                // stock binned there although their primary location is
                // elsewhere.
                $locationId = (int) $data['warehouse_location_id'];
                $productQuery->where(fn ($q) => $q
                    ->where('location_id', $locationId)
                    ->orWhereIn('id', ProductLocationStock::query()
                        ->select('product_id')
                        ->where('location_id', $locationId)
                        ->where('quantity', '>', 0))
                    ->orWhereHas('activeVariants.locationStocks', fn ($bins) => $bins->where('location_id', $locationId)->where('quantity', '>', 0)));
            }

            // A location-scoped audit counts that bin; otherwise the line
            // shows the product's primary location.
            $this->snapshot->seed($audit, $productQuery);

            return $audit;
        });
    }

    /**
     * Start a draft audit, snapshotting current system quantities.
     *
     * @api
     */
    public function start(StockAudit $stockAudit, User $actor): StockAudit
    {
        $this->warehouseAccess->authorizeStockAudit($actor, $stockAudit);

        return DB::transaction(function () use ($stockAudit, $actor) {
            $locked = StockAudit::whereKey($stockAudit->getKey())->lockForUpdate()->firstOrFail();
            $this->warehouseAccess->authorizeStockAudit($actor, $locked);
            $this->assertWarehouseScope($locked);

            if ($locked->status !== 'draft') {
                throw new InvalidStateException('Only draft audits can be started.', 'invalid_status');
            }

            if (! $locked->items()->exists()) {
                throw new InvalidStateException('Cannot start an audit with no items.', 'no_items');
            }

            // Snapshot only after claiming the draft under the same lock
            // completion and counting use. A stale start cannot reopen it.
            // One UPDATE re-reads every line, however many there are.
            $this->snapshot->refresh($locked);

            $locked->update([
                'status' => 'in_progress',
                'started_at' => now(),
            ]);
            $stockAudit->setRawAttributes($locked->getAttributes(), true);
            // Lines loaded before the snapshot are stale; load them again on use.
            $stockAudit->unsetRelation('items');

            return $stockAudit;
        });
    }

    /**
     * Record the physical count for one audit item.
     *
     * Pass the item's count_revision as expectedRevision to reject a delayed
     * update when its evidence has changed. Omitting it retains the existing
     * unconditional write contract. A conflict throws stale_count.
     *
     * @api
     */
    public function recordCount(StockAudit $stockAudit, StockAuditItem $item, User $actor, int $countedQuantity, ?string $notes = null, ?string $expectedRevision = null): StockAuditItem
    {
        $this->warehouseAccess->authorizeStockAudit($actor, $stockAudit);

        return DB::transaction(function () use ($stockAudit, $item, $actor, $countedQuantity, $notes, $expectedRevision) {
            // Parent before item: serialize counts with completion so no
            // count can overwrite the evidence after its stock was booked.
            $locked = StockAudit::whereKey($stockAudit->getKey())->lockForUpdate()->firstOrFail();
            $this->warehouseAccess->authorizeStockAudit($actor, $locked);
            $this->assertWarehouseScope($locked);

            if ($locked->status !== 'in_progress') {
                throw new InvalidStateException('Audit is not in progress', 'invalid_status');
            }

            $lockedItem = StockAuditItem::whereKey($item->getKey())->lockForUpdate()->firstOrFail();
            if ($lockedItem->stock_audit_id !== $locked->id) {
                throw new InvalidStateException('Item does not belong to this audit', 'item_mismatch');
            }

            if ($expectedRevision !== null && ! hash_equals($lockedItem->count_revision, $expectedRevision)) {
                throw new InvalidStateException('This count has changed. Review the latest count before saving yours.', 'stale_count');
            }

            $lockedItem->update([
                'counted_quantity' => $countedQuantity,
                'discrepancy' => $countedQuantity - $lockedItem->system_quantity,
                'status' => 'counted',
                'counted_by' => $actor->id,
                'counted_at' => now(),
                'notes' => $notes ?? $lockedItem->notes,
            ]);
            $item->setRawAttributes($lockedItem->getAttributes(), true);

            return $item;
        });
    }

    /**
     * Complete an in-progress audit, booking a recount adjustment for every
     * counted item whose count differs from the system quantity.
     *
     * The location bins move with the total: a location-scoped audit books
     * each variance into the audited bin; an organization-wide audit lets
     * StockAdjustment::adjust() drain a shortfall from the bins in
     * fulfilment order and put an overage in the primary bin.
     *
     * Lines nobody counted never adjust stock. Completing while any are left
     * is refused unless the caller confirms it with $allowUncounted, so an
     * audit cannot be closed at 0% by accident.
     *
     * @return int the number of stock adjustments created
     *
     * @throws InvalidStateException uncounted_items when lines are uncounted and not allowed
     * @throws InvalidStateException completion_vetoed when a stock_audit_completing filter refuses
     *
     * @api
     */
    public function complete(StockAudit $stockAudit, User $actor, bool $allowUncounted = false): int
    {
        $this->warehouseAccess->authorizeStockAudit($actor, $stockAudit);

        if ($stockAudit->status !== 'in_progress') {
            throw new InvalidStateException('Only in-progress audits can be completed.', 'invalid_status');
        }

        $adjustmentsCreated = 0;

        DB::transaction(function () use ($stockAudit, $actor, $allowUncounted, &$adjustmentsCreated) {
            // Lock and re-read the audit so two concurrent completions
            // serialize on this row; the second waits, then sees the
            // 'completed' status and is rejected below — otherwise both
            // would re-apply every recount adjustment (double write).
            $locked = StockAudit::whereKey($stockAudit->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'in_progress') {
                throw new InvalidStateException('Only in-progress audits can be completed.', 'invalid_status');
            }

            $this->assertWarehouseScope($locked);
            $locked->load(['items.product', 'items.variant']);
            Product::where('organization_id', $locked->organization_id)->whereIn('id', $locked->items->pluck('product_id')->unique())->orderBy('id')->lockForUpdate()->get();

            $uncounted = $locked->items->whereNull('counted_quantity')->count();
            if ($uncounted > 0 && ! $allowUncounted) {
                throw new InvalidStateException(
                    "{$uncounted} of {$locked->items->count()} item(s) have not been counted. Count them, or confirm completing without them (uncounted items are left unchanged).",
                    'uncounted_items',
                );
            }

            // Plugins may refuse (or hold) the completion: true allows it, a
            // string is the reason it is refused, false a generic refusal.
            // Runs under the audit lock, before anything is booked.
            $verdict = apply_filters('stock_audit_completing', true, $locked, $actor, $allowUncounted);

            if ($verdict !== true) {
                throw new InvalidStateException(
                    is_string($verdict) && trim($verdict) !== ''
                        ? $verdict
                        : 'An installed plugin is not allowing this audit to be completed yet.',
                    'completion_vetoed',
                );
            }

            foreach ($locked->items as $item) {
                // Skip items that haven't been counted
                if ($item->counted_quantity === null) {
                    continue;
                }

                $discrepancy = $item->counted_quantity - $item->system_quantity;

                $item->update([
                    'discrepancy' => $discrepancy,
                    'status' => 'adjusted',
                ]);

                // Create stock adjustment if there's a discrepancy
                if ($discrepancy !== 0) {
                    if ($item->product_variant_id !== null) {
                        StockAdjustment::adjustVariant(
                            variant: $item->variant,
                            quantity: $discrepancy,
                            type: 'recount',
                            reason: "Stock audit: {$locked->audit_number}",
                            notes: "Audit '{$locked->name}' - System: {$item->system_quantity}, Counted: {$item->counted_quantity}",
                            reference: $locked, actor: $actor, locationId: $locked->warehouse_id !== null ? $item->location_id : $locked->warehouse_location_id,
                        );
                    } else {
                        StockAdjustment::adjust(
                            product: $item->product,
                            quantity: $discrepancy,
                            type: 'recount',
                            reason: "Stock audit: {$locked->audit_number}",
                            notes: "Audit '{$locked->name}' - System: {$item->system_quantity}, Counted: {$item->counted_quantity}",
                            reference: $locked,
                            actor: $actor,
                            locationId: $locked->warehouse_id !== null ? $item->location_id : $locked->warehouse_location_id,
                        );
                    }
                    $adjustmentsCreated++;
                }
            }

            $locked->update([
                'status' => 'completed',
                'completed_at' => now(),
            ]);
        });

        return $adjustmentsCreated;
    }
    private function assertWarehouseScope(StockAudit $audit): void
    {
        if ($audit->warehouse_id !== null && $audit->items()->whereDoesntHave('location', fn ($q) => $q->where('warehouse_id', $audit->warehouse_id))->exists()) {
            throw new InvalidStateException('An audited location has moved or been removed. Create a new audit for the current warehouse layout.', 'scope_changed');
        }
    }

}
