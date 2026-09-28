<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\InvalidStateException;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductLocation;
use App\Models\Inventory\StockAdjustment;
use App\Models\Inventory\StockAudit;
use App\Models\Inventory\StockAuditItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;

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
    public function __construct(private readonly WarehouseAccessService $warehouseAccess) {}

    /**
     * Create a draft audit and seed one item per product in scope.
     *
     * @param  array{name: string, description?: string|null, audit_type: string, warehouse_location_id?: int|null, notes?: string|null, product_ids?: array<int, int>|null}  $data
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
        $this->warehouseAccess->authorizeLocation($actor, $data['warehouse_location_id'] ?? null);

        return DB::transaction(function () use ($data, $organizationId, $actor) {
            $audit = StockAudit::create([
                'organization_id' => $organizationId,
                'audit_number' => StockAudit::generateAuditNumber($organizationId),
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'status' => 'draft',
                'audit_type' => $data['audit_type'],
                'warehouse_location_id' => $data['warehouse_location_id'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $actor->id,
            ]);

            // Get products to include in the audit
            $productQuery = Product::forOrganization($organizationId)->active();

            if (! empty($data['product_ids'])) {
                // Specific products selected
                $productQuery->whereIn('id', $data['product_ids']);
            } elseif (! empty($data['warehouse_location_id'])) {
                // Filter by location
                $productQuery->where('location_id', $data['warehouse_location_id']);
            }

            foreach ($productQuery->get() as $product) {
                StockAuditItem::create([
                    'stock_audit_id' => $audit->id,
                    'product_id' => $product->id,
                    'location_id' => $product->location_id,
                    'system_quantity' => $product->stock,
                    'status' => 'pending',
                ]);
            }

            return $audit;
        });
    }

    /**
     * Start a draft audit, snapshotting current system quantities.
     */
    public function start(StockAudit $stockAudit, User $actor): StockAudit
    {
        $this->warehouseAccess->authorizeLocation($actor, $stockAudit->warehouse_location_id);

        if ($stockAudit->status !== 'draft') {
            throw new InvalidStateException('Only draft audits can be started.', 'invalid_status');
        }

        if ($stockAudit->items()->count() === 0) {
            throw new InvalidStateException('Cannot start an audit with no items.', 'no_items');
        }

        // Refresh system quantities from current stock levels
        DB::transaction(function () use ($stockAudit) {
            foreach ($stockAudit->items as $item) {
                $item->update(['system_quantity' => $item->product->stock]);
            }

            $stockAudit->update([
                'status' => 'in_progress',
                'started_at' => now(),
            ]);
        });

        return $stockAudit;
    }

    /**
     * Record the physical count for one audit item.
     */
    public function recordCount(StockAudit $stockAudit, StockAuditItem $item, User $actor, int $countedQuantity, ?string $notes = null): StockAuditItem
    {
        $this->warehouseAccess->authorizeLocation($actor, $stockAudit->warehouse_location_id);

        if ($stockAudit->status !== 'in_progress') {
            throw new InvalidStateException('Audit is not in progress', 'invalid_status');
        }

        if ($item->stock_audit_id !== $stockAudit->id) {
            throw new InvalidStateException('Item does not belong to this audit', 'item_mismatch');
        }

        $item->update([
            'counted_quantity' => $countedQuantity,
            'discrepancy' => $countedQuantity - $item->system_quantity,
            'status' => 'counted',
            'counted_by' => $actor->id,
            'counted_at' => now(),
            'notes' => $notes ?? $item->notes,
        ]);

        return $item;
    }

    /**
     * Complete an in-progress audit, booking a recount adjustment for every
     * counted item whose count differs from the system quantity.
     *
     * Lines nobody counted never adjust stock. Completing while any are left
     * is refused unless the caller confirms it with $allowUncounted, so an
     * audit cannot be closed at 0% by accident.
     *
     * @return int the number of stock adjustments created
     *
     * @throws InvalidStateException uncounted_items when lines are uncounted and not allowed
     */
    public function complete(StockAudit $stockAudit, User $actor, bool $allowUncounted = false): int
    {
        $this->warehouseAccess->authorizeLocation($actor, $stockAudit->warehouse_location_id);

        if ($stockAudit->status !== 'in_progress') {
            throw new InvalidStateException('Only in-progress audits can be completed.', 'invalid_status');
        }

        $adjustmentsCreated = 0;

        DB::transaction(function () use ($stockAudit, $allowUncounted, &$adjustmentsCreated) {
            // Lock and re-read the audit so two concurrent completions
            // serialize on this row; the second waits, then sees the
            // 'completed' status and is rejected below — otherwise both
            // would re-apply every recount adjustment (double write).
            $locked = StockAudit::whereKey($stockAudit->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'in_progress') {
                throw new InvalidStateException('Only in-progress audits can be completed.', 'invalid_status');
            }

            $locked->load('items.product');

            $uncounted = $locked->items->whereNull('counted_quantity')->count();
            if ($uncounted > 0 && ! $allowUncounted) {
                throw new InvalidStateException(
                    "{$uncounted} of {$locked->items->count()} item(s) have not been counted. Count them, or confirm completing without them (uncounted items are left unchanged).",
                    'uncounted_items',
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
                    StockAdjustment::adjust(
                        product: $item->product,
                        quantity: $discrepancy,
                        type: 'recount',
                        reason: "Stock audit: {$locked->audit_number}",
                        notes: "Audit '{$locked->name}' - System: {$item->system_quantity}, Counted: {$item->counted_quantity}",
                        reference: $locked,
                    );

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
}
