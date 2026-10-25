<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Inventory\Product;
use App\Models\Purchasing\PurchaseOrder;
use App\Models\Purchasing\PurchaseOrderItem;
use App\Services\ReorderService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Console command to check product reorder points and auto-create purchase orders.
 *
 * Finds all products where stock is at or below the reorder point,
 * groups them by preferred supplier, and creates draft purchase orders.
 */
class CheckReorderPointsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'inventory:check-reorder-points';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check product reorder points and auto-create draft purchase orders for low stock items';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        // Acquire an exclusive lock so concurrent runs (scheduler firing on
        // two nodes, manual invocation overlapping the cron, container
        // restart re-running the schedule) cannot both pass the "no
        // existing PO" check and end up creating duplicate POs against the
        // same supplier. The TTL must outlive a normal run.
        $lock = Cache::lock('inventory:check-reorder-points', 600);

        if (! $lock->get()) {
            $this->warn('Another reorder check is already running — skipping this invocation.');

            return Command::SUCCESS;
        }

        try {
            return $this->runChecks();
        } finally {
            $lock->release();
        }
    }

    /**
     * The actual reorder logic, run only when this process holds the lock.
     */
    protected function runChecks(): int
    {
        $this->info('Checking reorder points...');

        // Get all products that need reorder
        $products = Product::needsReorder()
            ->withExists('variants')
            ->with(ReorderService::primarySupplierEagerLoad())
            ->get();

        if ($products->isEmpty()) {
            $this->info('No products need reordering.');

            return Command::SUCCESS;
        }

        // Count only outstanding lines. A partial PO still covers its open
        // units, but a fully received line on it must not block replenishment.
        $productsWithExistingPOs = PurchaseOrderItem::whereHas('purchaseOrder', function ($query) {
            $query->whereIn('status', [PurchaseOrder::STATUS_DRAFT, PurchaseOrder::STATUS_SENT, PurchaseOrder::STATUS_PARTIAL]);
        })
            ->whereColumn('purchase_order_items.quantity_ordered', '>', 'purchase_order_items.quantity_received')
            ->pluck('product_id')
            ->unique()
            ->toArray();

        // Group products by organization and supplier
        $grouped = [];
        $skippedNoSupplier = 0;
        $skippedExistingPO = 0;
        $skippedVariants = 0;
        $reorder = app(ReorderService::class);

        foreach ($products as $product) {
            if ($reorder->requiresVariantSelection($product)) {
                $skippedVariants++;
                $this->warn("  Skipping {$product->name}: choose specific variant SKUs on a purchase order.");

                continue;
            }

            // Skip if product already has a pending/sent PO
            if (in_array($product->id, $productsWithExistingPOs)) {
                $skippedExistingPO++;
                $this->line("  Skipping {$product->name} (SKU: {$product->sku}) - existing PO found");

                continue;
            }

            // Get primary supplier
            $primarySupplier = $product->suppliers->first();

            if (! $primarySupplier) {
                $skippedNoSupplier++;
                $this->warn("  Skipping {$product->name} (SKU: {$product->sku}) - no supplier assigned");
                Log::warning("Reorder check: Product {$product->name} (ID: {$product->id}) has no supplier assigned");

                continue;
            }

            $key = $product->organization_id.'-'.$primarySupplier->id;
            if (! isset($grouped[$key])) {
                $grouped[$key] = [
                    'organization_id' => $product->organization_id,
                    'supplier_id' => $primarySupplier->id,
                    'products' => [],
                ];
            }

            $grouped[$key]['products'][] = $product;
        }

        // Create POs for each group. Quantity and cost come from the shared
        // ReorderService so the quick "Create PO" action suggests the same.
        $createdPOs = 0;

        foreach ($grouped as $group) {
            try {
                $po = $reorder->createDraftPurchaseOrder(
                    organizationId: $group['organization_id'],
                    supplierId: $group['supplier_id'],
                    products: $group['products'],
                    userId: null,
                    notes: 'Auto-generated reorder',
                    activityAction: 'auto_reorder',
                    activityDescription: 'Auto-generated purchase order',
                );

                $createdPOs++;

                $this->info("  Created PO {$po->po_number} with ".count($group['products']).' items');
            } catch (\Exception $e) {
                $this->error("  Failed to create PO for supplier {$group['supplier_id']}: {$e->getMessage()}");
                Log::error('Reorder check: Failed to create PO', [
                    'supplier_id' => $group['supplier_id'],
                    'organization_id' => $group['organization_id'],
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("Reorder check complete. Created {$createdPOs} purchase orders.");

        if ($skippedNoSupplier > 0) {
            $this->warn("Skipped {$skippedNoSupplier} products with no supplier.");
        }

        if ($skippedExistingPO > 0) {
            $this->info("Skipped {$skippedExistingPO} products with outstanding purchase orders.");
        }

        if ($skippedVariants > 0) {
            $this->warn("Skipped {$skippedVariants} products requiring variant selection.");
        }

        return Command::SUCCESS;
    }
}
