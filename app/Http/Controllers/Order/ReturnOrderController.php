<?php

declare(strict_types=1);

namespace App\Http\Controllers\Order;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\ReturnOrder\RejectReturnOrderRequest;
use App\Http\Requests\ReturnOrder\StoreReturnOrderRequest;
use App\Http\Requests\ReturnOrder\UpdateReturnLinesRequest;
use App\Models\Order\Order;
use App\Models\Order\ReturnOrder;
use App\Services\ReturnOrderService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
            ->tap(fn ($q) => app(ReturnOrderService::class)->scopeForUser($q, $request->user()))
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
        // "New Return" from the returns list has no order yet: pick one first.
        if (! $request->filled('order_id')) {
            return $this->selectOrder($request);
        }

        $organizationId = $request->user()->organization_id;

        $order = Order::with(['items.product', 'items.variant'])
            ->forOrganization($organizationId)
            ->findOrFail($request->input('order_id'));

        $returns = app(ReturnOrderService::class);
        $returnedQuantities = $returns->returnedQuantities($order);

        return Inertia::render('Returns/Create', [
            'order' => $order,
            'returnedQuantities' => $returnedQuantities,
            // Per line, what the customer paid after line and order
            // discounts: the estimate matches the refund the service records.
            'paidNets' => (object) $returns->paidLineNets($order),
        ]);
    }

    /**
     * List the orders a return can be raised against, newest first.
     */
    private function selectOrder(Request $request): Response
    {
        $search = trim((string) $request->input('search', ''));

        $orders = app(ReturnOrderService::class)
            ->returnableOrders($request->user(), $search !== '' ? $search : null)
            ->withCount('items')
            ->latest('order_date')
            ->latest('id')
            ->paginate(config('limits.pagination.default', 15), ['id', 'order_number', 'customer_name', 'customer_email', 'status', 'total', 'currency', 'order_date'])
            ->withQueryString();

        return Inertia::render('Returns/SelectOrder', [
            'orders' => $orders,
            'filters' => ['search' => $search],
        ]);
    }

    /**
     * Store a newly created return order.
     */
    public function store(StoreReturnOrderRequest $request, ReturnOrderService $returns)
    {
        try {
            $returnOrder = $returns->create($request->user()->organization_id, $request->user(), $request->validated());

            return redirect()->route('returns.show', $returnOrder)
                ->with('success', 'Return request created successfully.');
        } catch (BusinessRuleException $e) {
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

        app(ReturnOrderService::class)->authorizeView($returnOrder, auth()->user());

        $returnOrder->load(['order.items.product', 'items.product', 'items.variant', 'items.orderItem', 'processor']);

        return Inertia::render('Returns/Show', [
            'returnOrder' => $returnOrder,
        ]);
    }

    /**
     * Change the restock flag and/or condition of lines before the return is
     * received.
     */
    public function updateLines(UpdateReturnLinesRequest $request, ReturnOrder $returnOrder, ReturnOrderService $returns)
    {
        $this->authorizeReturn($returnOrder);

        return $this->transition(
            fn () => $returns->updateLines($returnOrder, $request->user(), $request->validated()['items']),
            'Return lines updated.',
        );
    }

    /**
     * Approve a pending return order.
     */
    public function approve(ReturnOrder $returnOrder, ReturnOrderService $returns)
    {
        $this->authorizeReturn($returnOrder);

        return $this->transition(fn () => $returns->approve($returnOrder, auth()->user()), 'Return approved successfully.');
    }

    /**
     * Receive items for an approved return order.
     * Restocks inventory for items marked for restock.
     */
    public function receive(ReturnOrder $returnOrder, ReturnOrderService $returns)
    {
        $this->authorizeReturn($returnOrder);

        return $this->transition(fn () => $returns->receive($returnOrder, auth()->user()), 'Return received and inventory updated.');
    }

    /**
     * Complete a received return order.
     */
    public function complete(ReturnOrder $returnOrder, ReturnOrderService $returns)
    {
        $this->authorizeReturn($returnOrder);

        return $this->transition(fn () => $returns->complete($returnOrder, auth()->user()), 'Return completed successfully.');
    }

    /**
     * Reject a pending return order.
     */
    public function reject(RejectReturnOrderRequest $request, ReturnOrder $returnOrder, ReturnOrderService $returns)
    {
        $this->authorizeReturn($returnOrder);

        $notes = $request->validated()['notes'] ?? null;

        return $this->transition(fn () => $returns->reject($returnOrder, $request->user(), $notes), 'Return rejected.');
    }

    private function authorizeReturn(ReturnOrder $returnOrder): void
    {
        if ($returnOrder->organization_id !== auth()->user()->organization_id) {
            abort(403, 'Unauthorized action.');
        }
    }

    /**
     * Run a lifecycle transition, flashing the service's refusal as an error.
     */
    private function transition(callable $action, string $success): RedirectResponse
    {
        try {
            $action();
        } catch (BusinessRuleException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', $success);
    }
}
