<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\Inventory\Product;
use App\Models\Purchasing\PurchaseOrder;
use App\Models\Purchasing\PurchaseOrderItem;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
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
     *
     * @return int
     */
    public function handle(): int
    {
        $this->info('Checking reorder points...');

        // Get all products that need reorder
        $products = Product::needsReorder()
            ->with(['suppliers' => function ($query) {
                $query->wherePivot('is_primary', true);
            }])
            ->get();

        if ($products->isEmpty()) {
            $this->info('No products need reordering.');
            return Command::SUCCESS;
        }

        // Get product IDs that already have pending/sent POs
        $productsWithExistingPOs = PurchaseOrderItem::whereHas('purchaseOrder', function ($query) {
            $query->whereIn('status', [PurchaseOrder::STATUS_DRAFT, PurchaseOrder::STATUS_SENT]);
        })
            ->pluck('product_id')
            ->unique()
            ->toArray();

        // Group products by organization and supplier
        $grouped = [];
        $skippedNoSupplier = 0;
        $skippedExistingPO = 0;

        foreach ($products as $product) {
            // Skip if product already has a pending/sent PO
            if (in_array($product->id, $productsWithExistingPOs)) {
                $skippedExistingPO++;
                $this->line("  Skipping {$product->name} (SKU: {$product->sku}) - existing PO found");
                continue;
            }

            // Get primary supplier
            $primarySupplier = $product->suppliers->first();

            if (!$primarySupplier) {
                $skippedNoSupplier++;
                $this->warn("  Skipping {$product->name} (SKU: {$product->sku}) - no supplier assigned");
                Log::warning("Reorder check: Product {$product->name} (ID: {$product->id}) has no supplier assigned");
                continue;
            }

            $key = $product->organization_id . '-' . $primarySupplier->id;
            if (!isset($grouped[$key])) {
                $grouped[$key] = [
                    'organization_id' => $product->organization_id,
                    'supplier_id' => $primarySupplier->id,
                    'products' => [],
                ];
            }

            $grouped[$key]['products'][] = $product;
        }

        // Create POs for each group
        $createdPOs = 0;

        foreach ($grouped as $group) {
            try {
                DB::transaction(function () use ($group, &$createdPOs) {
                    $po = PurchaseOrder::create([
                        'organization_id' => $group['organization_id'],
                        'supplier_id' => $group['supplier_id'],
                        'po_number' => PurchaseOrder::generatePONumber($group['organization_id']),
                        'status' => PurchaseOrder::STATUS_DRAFT,
                        'order_date' => now(),
                        'subtotal' => 0,
                        'tax' => 0,
                        'shipping' => 0,
                        'total' => 0,
                        'notes' => 'Auto-generated reorder',
                    ]);

                    $subtotal = 0;

                    foreach ($group['products'] as $product) {
                        $costPrice = $product->suppliers->first()->pivot->cost_price
                            ?? $product->purchase_price
                            ?? $product->price
                            ?? 0;

                        $itemSubtotal = $product->reorder_quantity * $costPrice;

                        PurchaseOrderItem::create([
                            'purchase_order_id' => $po->id,
                            'product_id' => $product->id,
                            'product_name' => $product->name,
                            'sku' => $product->sku,
                            'quantity_ordered' => $product->reorder_quantity,
                            'quantity_received' => 0,
                            'unit_cost' => $costPrice,
                            'subtotal' => $itemSubtotal,
                            'tax' => 0,
                            'total' => $itemSubtotal,
                        ]);

                        $subtotal += $itemSubtotal;
                    }

                    // Update PO totals
                    $po->update([
                        'subtotal' => $subtotal,
                        'total' => $subtotal,
                    ]);

                    // Log activity
                    $productNames = collect($group['products'])->pluck('name')->implode(', ');
                    ActivityLog::create([
                        'organization_id' => $group['organization_id'],
                        'user_id' => null,
                        'subject_type' => PurchaseOrder::class,
                        'subject_id' => $po->id,
                        'action' => 'auto_reorder',
                        'description' => "Auto-generated purchase order {$po->po_number} for: {$productNames}",
                        'properties' => [
                            'po_number' => $po->po_number,
                            'supplier_id' => $group['supplier_id'],
                            'product_count' => count($group['products']),
                            'total' => $subtotal,
                        ],
                    ]);

                    $createdPOs++;

                    $this->info("  Created PO {$po->po_number} with " . count($group['products']) . " items");
                });
            } catch (\Exception $e) {
                $this->error("  Failed to create PO for supplier {$group['supplier_id']}: {$e->getMessage()}");
                Log::error("Reorder check: Failed to create PO", [
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
            $this->info("Skipped {$skippedExistingPO} products with existing pending/sent POs.");
        }

        return Command::SUCCESS;
    }
}
