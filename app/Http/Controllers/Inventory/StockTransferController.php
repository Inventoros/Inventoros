<?php

declare(strict_types=1);

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductLocation;
use App\Models\Inventory\StockAdjustment;
use App\Models\Inventory\StockTransfer;
use App\Models\Inventory\StockTransferItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Controller for managing stock transfers between locations.
 *
 * Handles listing, creating, viewing, completing, and cancelling
 * stock transfers for inventory management.
 */
class StockTransferController extends Controller
{
    /**
     * Display a listing of stock transfers.
     *
     * @param Request $request The incoming HTTP request
     * @return Response
     */
    public function index(Request $request): Response
    {
        $organizationId = $request->user()->organization_id;
        $activeWarehouseId = session('active_warehouse_id');

        $query = StockTransfer::with(['fromLocation', 'toLocation', 'fromWarehouse', 'toWarehouse', 'transferredBy', 'items'])
            ->forOrganization($organizationId)
            ->when($activeWarehouseId, function ($query, $warehouseId) {
                $query->where(function ($q) use ($warehouseId) {
                    $q->where('from_warehouse_id', $warehouseId)
                      ->orWhere('to_warehouse_id', $warehouseId)
                      ->orWhereHas('fromLocation', function ($q2) use ($warehouseId) {
                          $q2->where('warehouse_id', $warehouseId);
                      })
                      ->orWhereHas('toLocation', function ($q2) use ($warehouseId) {
                          $q2->where('warehouse_id', $warehouseId);
                      });
                });
            })
            ->when($request->input('search'), function ($query, $search) {
                $query->where('transfer_number', 'like', "%{$search}%");
            })
            ->when($request->input('status'), function ($query, $status) {
                $query->where('status', $status);
            })
            ->latest();

        $transfers = $query->paginate(20)->withQueryString();

        return Inertia::render('StockTransfers/Index', [
            'transfers' => $transfers,
            'filters' => $request->only(['search', 'status']),
        ]);
    }

    /**
     * Show the form for creating a new stock transfer.
     *
     * @param Request $request The incoming HTTP request
     * @return Response
     */
    public function create(Request $request): Response
    {
        $organizationId = $request->user()->organization_id;

        $locations = ProductLocation::forOrganization($organizationId)
            ->active()
            ->orderBy('name')
            ->get(['id', 'name', 'code']);

        $products = Product::forOrganization($organizationId)
            ->active()
            ->orderBy('name')
            ->get(['id', 'name', 'sku', 'stock']);

        return Inertia::render('StockTransfers/Create', [
            'locations' => $locations,
            'products' => $products,
        ]);
    }

    /**
     * Store a newly created stock transfer.
     *
     * @param Request $request The incoming HTTP request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'from_location_id' => 'required|exists:product_locations,id',
            'to_location_id' => [
                'required',
                'exists:product_locations,id',
                'different:from_location_id',
            ],
            'notes' => 'nullable|string|max:1000',
            'shipping_method' => 'nullable|string|max:255',
            'tracking_number' => 'nullable|string|max:255',
            'estimated_arrival' => 'nullable|date',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.notes' => 'nullable|string|max:500',
        ]);

        $organizationId = $request->user()->organization_id;

        // Verify locations belong to the user's organization
        $fromLocation = ProductLocation::where('id', $validated['from_location_id'])
            ->forOrganization($organizationId)
            ->firstOrFail();

        $toLocation = ProductLocation::where('id', $validated['to_location_id'])
            ->forOrganization($organizationId)
            ->firstOrFail();

        // Determine if this is an inter-warehouse transfer
        $isInterWarehouse = false;
        $fromWarehouseId = $fromLocation->warehouse_id;
        $toWarehouseId = $toLocation->warehouse_id;

        if ($fromWarehouseId && $toWarehouseId && $fromWarehouseId !== $toWarehouseId) {
            $isInterWarehouse = true;
        }

        $transfer = DB::transaction(function () use ($validated, $organizationId, $request, $isInterWarehouse, $fromWarehouseId, $toWarehouseId) {
            $transferData = [
                'organization_id' => $organizationId,
                'transfer_number' => StockTransfer::generateTransferNumber($organizationId),
                'from_location_id' => $validated['from_location_id'],
                'to_location_id' => $validated['to_location_id'],
                'transferred_by' => $request->user()->id,
                'status' => 'pending',
                'notes' => $validated['notes'] ?? null,
                'is_inter_warehouse' => $isInterWarehouse,
            ];

            if ($isInterWarehouse) {
                $transferData['from_warehouse_id'] = $fromWarehouseId;
                $transferData['to_warehouse_id'] = $toWarehouseId;
                $transferData['shipping_method'] = $validated['shipping_method'] ?? null;
                $transferData['tracking_number'] = $validated['tracking_number'] ?? null;
                $transferData['estimated_arrival'] = $validated['estimated_arrival'] ?? null;
            }

            $transfer = StockTransfer::create($transferData);

            foreach ($validated['items'] as $item) {
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

        return redirect()->route('stock-transfers.show', $transfer)
            ->with('success', 'Stock transfer created successfully.');
    }

    /**
     * Display the specified stock transfer.
     *
     * @param Request $request The incoming HTTP request
     * @param StockTransfer $stockTransfer The stock transfer to display
     * @return Response
     */
    public function show(Request $request, StockTransfer $stockTransfer): Response
    {
        if ($stockTransfer->organization_id !== $request->user()->organization_id) {
            abort(403, 'Unauthorized action.');
        }

        $stockTransfer->load(['fromLocation', 'toLocation', 'transferredBy', 'items.product']);

        return Inertia::render('StockTransfers/Show', [
            'transfer' => $stockTransfer,
        ]);
    }

    /**
     * Update a stock transfer (e.g., status change to in_transit for inter-warehouse).
     *
     * @param Request $request The incoming HTTP request
     * @param StockTransfer $stockTransfer The stock transfer to update
     * @return \Illuminate\Http\RedirectResponse
     */
    public function update(Request $request, StockTransfer $stockTransfer)
    {
        if ($stockTransfer->organization_id !== $request->user()->organization_id) {
            abort(403, 'Unauthorized action.');
        }

        $validated = $request->validate([
            'status' => 'sometimes|string|in:in_transit',
            'shipping_method' => 'nullable|string|max:255',
            'tracking_number' => 'nullable|string|max:255',
            'estimated_arrival' => 'nullable|date',
        ]);

        // Handle status change to in_transit
        if (isset($validated['status']) && $validated['status'] === 'in_transit') {
            if ($stockTransfer->status !== 'pending') {
                return redirect()->route('stock-transfers.show', $stockTransfer)
                    ->with('error', 'Only pending transfers can be marked as in transit.');
            }

            $updateData = [
                'status' => 'in_transit',
                'shipped_at' => now(),
            ];

            if (isset($validated['shipping_method'])) {
                $updateData['shipping_method'] = $validated['shipping_method'];
            }
            if (isset($validated['tracking_number'])) {
                $updateData['tracking_number'] = $validated['tracking_number'];
            }
            if (isset($validated['estimated_arrival'])) {
                $updateData['estimated_arrival'] = $validated['estimated_arrival'];
            }

            $stockTransfer->update($updateData);

            return redirect()->route('stock-transfers.show', $stockTransfer)
                ->with('success', 'Stock transfer marked as in transit.');
        }

        return redirect()->route('stock-transfers.show', $stockTransfer);
    }

    /**
     * Complete a stock transfer, adjusting stock levels.
     *
     * @param Request $request The incoming HTTP request
     * @param StockTransfer $stockTransfer The stock transfer to complete
     * @return \Illuminate\Http\RedirectResponse
     */
    public function complete(Request $request, StockTransfer $stockTransfer)
    {
        if ($stockTransfer->organization_id !== $request->user()->organization_id) {
            abort(403, 'Unauthorized action.');
        }

        if (!in_array($stockTransfer->status, ['pending', 'in_transit'])) {
            return redirect()->route('stock-transfers.show', $stockTransfer)
                ->with('error', 'Only pending or in-transit transfers can be completed.');
        }

        DB::transaction(function () use ($stockTransfer) {
            $stockTransfer->load('items.product');

            foreach ($stockTransfer->items as $item) {
                $product = $item->product;

                // Deduct from source (stock adjustment for audit)
                StockAdjustment::create([
                    'organization_id' => $stockTransfer->organization_id,
                    'product_id' => $product->id,
                    'user_id' => auth()->id(),
                    'type' => 'transfer',
                    'quantity_before' => $product->stock,
                    'quantity_after' => $product->stock - $item->quantity,
                    'adjustment_quantity' => -$item->quantity,
                    'reason' => "Transfer out to {$stockTransfer->toLocation->name}",
                    'notes' => "Transfer #{$stockTransfer->transfer_number}",
                    'reference_type' => StockTransfer::class,
                    'reference_id' => $stockTransfer->id,
                ]);

                $product->update(['stock' => $product->stock - $item->quantity]);

                // Reload product to get updated stock
                $product->refresh();

                // Add to destination (stock adjustment for audit)
                StockAdjustment::create([
                    'organization_id' => $stockTransfer->organization_id,
                    'product_id' => $product->id,
                    'user_id' => auth()->id(),
                    'type' => 'transfer',
                    'quantity_before' => $product->stock,
                    'quantity_after' => $product->stock + $item->quantity,
                    'adjustment_quantity' => $item->quantity,
                    'reason' => "Transfer in from {$stockTransfer->fromLocation->name}",
                    'notes' => "Transfer #{$stockTransfer->transfer_number}",
                    'reference_type' => StockTransfer::class,
                    'reference_id' => $stockTransfer->id,
                ]);

                $product->update(['stock' => $product->stock + $item->quantity]);
            }

            $stockTransfer->update([
                'status' => 'completed',
                'completed_at' => now(),
            ]);
        });

        return redirect()->route('stock-transfers.show', $stockTransfer)
            ->with('success', 'Stock transfer completed successfully. Stock levels have been updated.');
    }

    /**
     * Cancel a stock transfer.
     *
     * @param Request $request The incoming HTTP request
     * @param StockTransfer $stockTransfer The stock transfer to cancel
     * @return \Illuminate\Http\RedirectResponse
     */
    public function cancel(Request $request, StockTransfer $stockTransfer)
    {
        if ($stockTransfer->organization_id !== $request->user()->organization_id) {
            abort(403, 'Unauthorized action.');
        }

        if (!in_array($stockTransfer->status, ['pending', 'in_transit'])) {
            return redirect()->route('stock-transfers.show', $stockTransfer)
                ->with('error', 'Only pending or in-transit transfers can be cancelled.');
        }

        $stockTransfer->update(['status' => 'cancelled']);

        return redirect()->route('stock-transfers.show', $stockTransfer)
            ->with('success', 'Stock transfer has been cancelled.');
    }
}
