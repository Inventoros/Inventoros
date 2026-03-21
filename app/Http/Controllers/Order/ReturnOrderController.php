<?php

declare(strict_types=1);

namespace App\Http\Controllers\Order;

use App\Http\Controllers\Controller;
use App\Models\Inventory\Product;
use App\Models\Inventory\StockAdjustment;
use App\Models\Order\Order;
use App\Models\Order\ReturnOrder;
use App\Models\Order\ReturnOrderItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Controller for managing return orders (RMA).
 *
 * Handles CRUD operations for return orders including listing,
 * creating returns from orders, approving, receiving with restocking,
 * completing, and rejecting returns.
 */
class ReturnOrderController extends Controller
{
    /**
     * Display a listing of return orders.
     */
    public function index(Request $request): Response
    {
        $organizationId = $request->user()->organization_id;

        $returns = ReturnOrder::with(['order', 'items', 'processor'])
            ->forOrganization($organizationId)
            ->when($request->input('search'), function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('return_number', 'like', "%{$search}%")
                      ->orWhereHas('order', function ($oq) use ($search) {
                          $oq->where('order_number', 'like', "%{$search}%")
                            ->orWhere('customer_name', 'like', "%{$search}%");
                      });
                });
            })
            ->when($request->input('status'), function ($query, $status) {
                $query->byStatus($status);
            })
            ->when($request->input('type'), function ($query, $type) {
                $query->byType($type);
            })
            ->latest()
            ->paginate(config('limits.pagination.default', 15))
            ->withQueryString();

        return Inertia::render('Returns/Index', [
            'returns' => $returns,
            'filters' => $request->only(['search', 'status', 'type']),
            'statuses' => ['pending', 'approved', 'received', 'completed', 'rejected'],
            'types' => ['return', 'exchange'],
        ]);
    }

    /**
     * Show the form for creating a new return order.
     */
    public function create(Request $request): Response
    {
        $organizationId = $request->user()->organization_id;

        $order = Order::with(['items.product'])
            ->forOrganization($organizationId)
            ->findOrFail($request->input('order_id'));

        // Calculate already returned quantities for each order item
        $returnedQuantities = ReturnOrderItem::whereHas('returnOrder', function ($q) use ($order) {
            $q->where('order_id', $order->id)
              ->whereNotIn('status', ['rejected']);
        })->selectRaw('order_item_id, SUM(quantity) as total_returned')
          ->groupBy('order_item_id')
          ->pluck('total_returned', 'order_item_id');

        return Inertia::render('Returns/Create', [
            'order' => $order,
            'returnedQuantities' => $returnedQuantities,
        ]);
    }

    /**
     * Store a newly created return order.
     */
    public function store(Request $request)
    {
        $organizationId = $request->user()->organization_id;

        $validated = $request->validate([
            'order_id' => 'required|exists:orders,id',
            'type' => 'required|in:return,exchange',
            'reason' => 'required|string|max:1000',
            'notes' => 'nullable|string|max:2000',
            'items' => 'required|array|min:1',
            'items.*.order_item_id' => 'required|exists:order_items,id',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.condition' => 'required|in:new,used,damaged',
            'items.*.restock' => 'required|boolean',
        ]);

        // Verify the order belongs to this organization
        $order = Order::forOrganization($organizationId)->findOrFail($validated['order_id']);
        $order->load('items');

        try {
            $returnOrder = DB::transaction(function () use ($validated, $organizationId, $order) {
                // Lock existing return order items to prevent double-returns
                $returnedQuantities = ReturnOrderItem::whereHas('returnOrder', function ($q) use ($order) {
                    $q->where('order_id', $order->id)
                      ->whereNotIn('status', ['rejected']);
                })->lockForUpdate()
                  ->selectRaw('order_item_id, SUM(quantity) as total_returned')
                  ->groupBy('order_item_id')
                  ->pluck('total_returned', 'order_item_id');

                $errors = [];
                foreach ($validated['items'] as $index => $item) {
                    $orderItem = $order->items->firstWhere('id', $item['order_item_id']);
                    if (!$orderItem) {
                        $errors["items.{$index}.order_item_id"] = 'Order item not found.';
                        continue;
                    }

                    $alreadyReturned = $returnedQuantities->get($item['order_item_id'], 0);
                    $maxReturnable = $orderItem->quantity - $alreadyReturned;

                    if ($item['quantity'] > $maxReturnable) {
                        $errors["items.{$index}.quantity"] = "Cannot return more than {$maxReturnable} units (ordered: {$orderItem->quantity}, already returned: {$alreadyReturned}).";
                    }
                }

                if (!empty($errors)) {
                    throw new \Illuminate\Validation\ValidationException(
                        \Illuminate\Support\Facades\Validator::make([], []),
                        redirect()->back()->withErrors($errors)->withInput()
                    );
                }

                // Calculate refund amount
                $refundAmount = 0;
                foreach ($validated['items'] as $item) {
                    $orderItem = $order->items->firstWhere('id', $item['order_item_id']);
                    $refundAmount += $item['quantity'] * $orderItem->unit_price;
                }

                $returnOrder = ReturnOrder::create([
                    'organization_id' => $organizationId,
                    'order_id' => $order->id,
                    'return_number' => ReturnOrder::generateReturnNumber($organizationId),
                    'type' => $validated['type'],
                    'status' => 'pending',
                    'reason' => $validated['reason'],
                    'notes' => $validated['notes'] ?? null,
                    'refund_amount' => $refundAmount,
                ]);

                foreach ($validated['items'] as $item) {
                    ReturnOrderItem::create([
                        'return_order_id' => $returnOrder->id,
                        'order_item_id' => $item['order_item_id'],
                        'product_id' => $item['product_id'],
                        'quantity' => $item['quantity'],
                        'condition' => $item['condition'],
                        'restock' => $item['restock'],
                    ]);
                }

                return $returnOrder;
            });

            return redirect()->route('returns.show', $returnOrder)
                ->with('success', 'Return request created successfully.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            return redirect()->back()
                ->withInput()
                ->with('error', $e->getMessage());
        }
    }

    /**
     * Display the specified return order.
     */
    public function show(ReturnOrder $returnOrder): Response
    {
        if ($returnOrder->organization_id !== auth()->user()->organization_id) {
            abort(403, 'Unauthorized action.');
        }

        $returnOrder->load(['order.items.product', 'items.product', 'items.orderItem', 'processor']);

        return Inertia::render('Returns/Show', [
            'returnOrder' => $returnOrder,
        ]);
    }

    /**
     * Approve a pending return order.
     */
    public function approve(ReturnOrder $returnOrder)
    {
        if ($returnOrder->organization_id !== auth()->user()->organization_id) {
            abort(403, 'Unauthorized action.');
        }

        if ($returnOrder->status !== 'pending') {
            return redirect()->back()->with('error', 'Only pending returns can be approved.');
        }

        $returnOrder->update([
            'status' => 'approved',
            'processed_by' => auth()->id(),
        ]);

        return redirect()->back()->with('success', 'Return approved successfully.');
    }

    /**
     * Receive items for an approved return order.
     * Restocks inventory for items marked for restock.
     */
    public function receive(ReturnOrder $returnOrder)
    {
        if ($returnOrder->organization_id !== auth()->user()->organization_id) {
            abort(403, 'Unauthorized action.');
        }

        if ($returnOrder->status !== 'approved') {
            return redirect()->back()->with('error', 'Only approved returns can be received.');
        }

        DB::transaction(function () use ($returnOrder) {
            $returnOrder->load('items.product');

            foreach ($returnOrder->items as $item) {
                if ($item->restock && $item->product) {
                    StockAdjustment::adjust(
                        $item->product,
                        $item->quantity,
                        'return',
                        'Return restock',
                        "Restocked from return {$returnOrder->return_number}",
                        $returnOrder
                    );
                }
            }

            $returnOrder->update([
                'status' => 'received',
                'processed_by' => auth()->id(),
            ]);
        });

        return redirect()->back()->with('success', 'Return received and inventory updated.');
    }

    /**
     * Complete a received return order.
     */
    public function complete(ReturnOrder $returnOrder)
    {
        if ($returnOrder->organization_id !== auth()->user()->organization_id) {
            abort(403, 'Unauthorized action.');
        }

        if ($returnOrder->status !== 'received') {
            return redirect()->back()->with('error', 'Only received returns can be completed.');
        }

        $returnOrder->update([
            'status' => 'completed',
            'completed_at' => now(),
            'processed_by' => auth()->id(),
        ]);

        return redirect()->back()->with('success', 'Return completed successfully.');
    }

    /**
     * Reject a pending return order.
     */
    public function reject(Request $request, ReturnOrder $returnOrder)
    {
        if ($returnOrder->organization_id !== auth()->user()->organization_id) {
            abort(403, 'Unauthorized action.');
        }

        if ($returnOrder->status !== 'pending') {
            return redirect()->back()->with('error', 'Only pending returns can be rejected.');
        }

        $validated = $request->validate([
            'notes' => 'nullable|string|max:2000',
        ]);

        $returnOrder->update([
            'status' => 'rejected',
            'processed_by' => auth()->id(),
            'notes' => $returnOrder->notes
                ? $returnOrder->notes . "\n\nRejection reason: " . ($validated['notes'] ?? 'No reason provided')
                : 'Rejection reason: ' . ($validated['notes'] ?? 'No reason provided'),
        ]);

        return redirect()->back()->with('success', 'Return rejected.');
    }
}
