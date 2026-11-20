<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\DiscountType;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\DocumentEmailException;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\InvalidOrderItemException;
use App\Exceptions\InvalidStateException;
use App\Http\Controllers\Api\Concerns\HandlesApiResponses;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Order\ApproveOrderRequest;
use App\Http\Requests\Api\Order\RejectOrderRequest;
use App\Http\Requests\SendDocumentEmailRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order\Order;
use App\Services\OrderInvoiceEmailService;
use App\Services\OrderService;
use App\Support\ListSortColumns;
use App\Support\PaymentVisibility;
use App\Support\Search;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * @tags Orders
 */
class OrderController extends Controller
{
    use HandlesApiResponses;

    public function __construct(
        protected OrderService $orderService,
    ) {}

    /**
     * List orders.
     */
    #[QueryParameter('search', description: 'Search by order number, customer name, or email', type: 'string')]
    #[QueryParameter('status', description: 'Filter by status', type: 'string', enum: ['pending', 'processing', 'shipped', 'delivered', 'cancelled'])]
    #[QueryParameter('source', description: 'Filter by order source', type: 'string')]
    #[QueryParameter('payment_status', description: 'Filter by payment status (requires view_payments)', type: 'string', enum: ['unpaid', 'partial', 'paid', 'overpaid', 'refunded', 'untracked'])]
    #[QueryParameter('warehouse_id', description: 'Filter by warehouse ID', type: 'integer')]
    #[QueryParameter('date_from', description: 'Filter orders from this date (YYYY-MM-DD)', type: 'string', example: '2025-01-01')]
    #[QueryParameter('date_to', description: 'Filter orders until this date (YYYY-MM-DD)', type: 'string', example: '2025-12-31')]
    #[QueryParameter('sort_by', description: 'Sort field (default: created_at)', type: 'string')]
    #[QueryParameter('sort_dir', description: 'Sort direction: asc or desc (default: desc)', type: 'string', enum: ['asc', 'desc'])]
    #[QueryParameter('per_page', description: 'Items per page (default: 15, max: 100)', type: 'integer')]
    public function index(Request $request): AnonymousResourceCollection
    {
        $organizationId = $request->user()->organization_id;

        $query = Order::withCount('items')
            ->forOrganization($organizationId)
            ->when($request->input('warehouse_id'), function ($query, $warehouseId) {
                $query->where('warehouse_id', $warehouseId);
            })
            // A string or number (JSON clients send numeric searches); an
            // array (?search[]=a) is ignored rather than a 500.
            ->when(is_string($request->input('search')) || is_int($request->input('search')) || is_float($request->input('search')) ? $request->input('search') : null, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    Search::apply($q, ['order_number', 'customer_name', 'customer_email', 'external_id', 'external_reference', 'metadata->channel_order_number', 'metadata->shopify->name'], $search);
                });
            })
            ->when($request->input('status'), function ($query, $status) {
                $query->byStatus($status);
            })
            ->when($request->input('source'), function ($query, $source) {
                $query->bySource($source);
            })
            ->when($this->dateFilter($request->input('date_from')), function ($query, $dateFrom) {
                $query->where('order_date', '>=', $dateFrom);
            })
            ->when($this->dateFilter($request->input('date_to')), function ($query, $dateTo) {
                $query->where('order_date', '<=', $dateTo);
            })
            // Only for callers who may see payments; otherwise ignored.
            ->when(
                PaymentVisibility::allows($request->user())
                    && in_array($request->input('payment_status'), PaymentStatus::values(), true),
                fn ($query) => $query->where('payment_status', $request->input('payment_status'))
            );

        // Sorting (allowlist to prevent SQL injection)
        $allowedSortColumns = ListSortColumns::ORDERS;
        $sortBy = in_array($request->input('sort_by'), $allowedSortColumns) ? $request->input('sort_by') : 'created_at';
        $sortDir = strtolower((string) $request->input('sort_dir')) === 'asc' ? 'asc' : 'desc';
        $query->orderBy($sortBy, $sortDir);

        $perPage = $this->perPage($request);
        $orders = $query->paginate($perPage);

        return OrderResource::collection($orders);
    }

    /**
     * Store a newly created order.
     *
     * @param  Request  $request  The incoming HTTP request containing order data
     */
    public function store(Request $request): JsonResponse
    {
        $organizationId = $request->user()->organization_id;

        $validated = $request->validate([
            'source' => ['nullable', 'string', 'max:255'],
            'external_id' => ['nullable', 'string', 'max:255'],
            'customer_name' => ['required', 'string', 'max:255'],
            'customer_email' => ['nullable', 'email', 'max:255'],
            'customer_address' => ['nullable', 'string'],
            'status' => ['nullable', 'string', 'in:pending,processing,shipped,delivered,cancelled'],
            'currency' => ['nullable', 'string', 'max:3'],
            'order_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
            'metadata' => ['nullable', 'array'],
            'items' => ['required', 'array', 'min:1', 'max:500'],
            'items.*.product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('organization_id', $organizationId)],
            'items.*.product_variant_id' => ['nullable', 'integer', Rule::exists('product_variants', 'id')->where('organization_id', $organizationId)],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit_price' => ['nullable', 'numeric', 'decimal:0,2', 'min:0'],
            // Tax amount for a line without a rate (kept as sent).
            'items.*.tax' => ['nullable', 'numeric', 'decimal:0,2', 'min:0'],
            // Tax rate in percent: the server computes the line's tax from it
            // (after discounts) and ignores items.*.tax for that line.
            'items.*.tax_rate' => ['nullable', 'numeric', 'decimal:0,3', 'min:0', 'max:100'],
            'items.*.discount_type' => ['nullable', Rule::in(DiscountType::values())],
            'items.*.discount_value' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:99999999'],
            'discount_type' => ['nullable', Rule::in(DiscountType::values())],
            'discount_value' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:99999999'],
        ]);

        // API-specific defaults; the OrderService owns the create invariant
        // (lock → validate → ledger + decrement, wrapped in SequenceNumberRetry)
        // shared with the web/GraphQL/MCP surfaces.
        $validated['status'] ??= 'pending';
        $validated['order_date'] ??= now();
        // No currency: OrderService uses the organization's currency.

        try {
            $order = $this->orderService->create(
                $validated,
                $request->user(),
                $validated['source'] ?? 'api'
            );
        } catch (InsufficientStockException|InvalidOrderItemException $e) {
            // Preserve the API's 422 contract: stock/variant problems surface as
            // an `items` validation error rather than a generic 500.
            throw ValidationException::withMessages(['items' => [$e->getMessage()]]);
        } catch (InvalidStateException $e) {
            // The order can't start in the requested status (an order on
            // backorder or waiting for approval cannot be created shipped).
            throw ValidationException::withMessages(['status' => [$e->getMessage()]]);
        } catch (BusinessRuleException $e) {
            throw ValidationException::withMessages(['items' => [$e->getMessage()]]);
        }

        $order->load('items');

        return response()->json([
            'message' => 'Order created successfully',
            'data' => new OrderResource($order),
        ], 201);
    }

    /**
     * Display the specified order.
     *
     * @param  Request  $request  The incoming HTTP request
     * @param  Order  $order  The order to display
     */
    public function show(Request $request, Order $order): JsonResponse
    {
        if ($order->organization_id !== $request->user()->organization_id) {
            return response()->json([
                'message' => 'Order not found',
                'error' => 'not_found',
            ], 404);
        }

        $order->load('items.product');

        return response()->json([
            'data' => new OrderResource($order),
        ]);
    }

    /**
     * Update the specified order.
     *
     * @param  Request  $request  The incoming HTTP request containing updated order data
     * @param  Order  $order  The order to update
     */
    public function update(Request $request, Order $order): JsonResponse
    {
        if ($order->organization_id !== $request->user()->organization_id) {
            return response()->json([
                'message' => 'Order not found',
                'error' => 'not_found',
            ], 404);
        }

        $validated = $request->validate([
            'customer_name' => ['sometimes', 'string', 'max:255'],
            'customer_email' => ['nullable', 'email', 'max:255'],
            'customer_address' => ['nullable', 'string'],
            'status' => ['nullable', 'string', 'in:pending,processing,shipped,delivered,cancelled'],
            'notes' => ['nullable', 'string'],
            'metadata' => ['nullable', 'array'],
            // Order-level discount; the totals are recomputed on the server.
            'discount_type' => ['sometimes', 'nullable', Rule::in(DiscountType::values())],
            'discount_value' => ['sometimes', 'nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:99999999'],
        ]);

        $discountChanged = array_key_exists('discount_type', $validated) || array_key_exists('discount_value', $validated);
        $discountType = $validated['discount_type'] ?? null;
        $discountValue = $validated['discount_value'] ?? null;
        unset($validated['discount_type'], $validated['discount_value']);

        $cancelTransition = isset($validated['status'])
            && $validated['status'] === 'cancelled'
            && $order->status !== OrderStatus::CANCELLED;

        // Status only moves forward (see Order::statusTransitionError()): a
        // cancelled order's stock was already restored, and a shipped or
        // delivered order's goods have left, so neither can be moved back.
        // Cancelling is checked below and by OrderService::cancel().
        if (isset($validated['status']) && ! $cancelTransition) {
            $transitionError = $order->transitionErrorTo($validated['status']);

            if ($transitionError !== null) {
                return response()->json([
                    'message' => $transitionError,
                    'error' => 'invalid_state_transition',
                ], 422);
            }
        }

        // An order waiting for approval cannot ship or be delivered yet.
        if (isset($validated['status']) && $order->approvalBlocks($validated['status'])) {
            return response()->json([
                'message' => Order::APPROVAL_PENDING_MESSAGE,
                'error' => 'approval_pending',
            ], 422);
        }

        // Reject cancellation of orders that already left the warehouse;
        // restocking would lie about inventory that physically isn't here.
        if ($cancelTransition && in_array($order->status, [OrderStatus::SHIPPED, OrderStatus::DELIVERED], true)) {
            return response()->json([
                'message' => "Cannot cancel an order that has already been {$order->status->value}.",
                'error' => 'invalid_state_transition',
            ], 422);
        }

        // Handle status change timestamps
        if (isset($validated['status'])) {
            if ($validated['status'] === 'shipped' && ! $order->shipped_at) {
                $validated['shipped_at'] = now();
            }
            if ($validated['status'] === 'delivered' && ! $order->delivered_at) {
                $validated['delivered_at'] = now();
            }
        }

        // Apply non-cancel field updates first, then route the cancel through
        // the shared, locked OrderService::cancel() so web, REST, and GraphQL
        // restock identically (once, idempotently, with the shipped/delivered
        // guard re-checked under a row lock).
        if ($cancelTransition) {
            unset($validated['status']);
        }

        // One transaction: a refused cancel (goods already shipped) must not
        // leave the discount or field changes from the same request saved.
        try {
            $order = DB::transaction(function () use ($order, $validated, $discountChanged, $discountType, $discountValue, $cancelTransition) {
                if ($discountChanged) {
                    $order = $this->orderService->changeOrderDiscount($order, $discountType, $discountValue);
                }

                if ($validated !== []) {
                    // The model's transition backstop (a concurrent status
                    // change) throws InvalidStateException.
                    $order->update($validated);
                }

                if ($cancelTransition) {
                    $order = $this->orderService->cancel($order);
                }

                return $order;
            });
        } catch (InvalidStateException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'error' => $e->errorCode,
            ], 422);
        } catch (BusinessRuleException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'error' => 'invalid_state_transition',
            ], 422);
        }

        $order->load('items');

        return response()->json([
            'message' => 'Order updated successfully',
            'data' => new OrderResource($order),
        ]);
    }

    /**
     * Remove the specified order.
     *
     * @param  Request  $request  The incoming HTTP request
     * @param  Order  $order  The order to delete
     */
    public function destroy(Request $request, Order $order): JsonResponse
    {
        if ($order->organization_id !== $request->user()->organization_id) {
            return response()->json([
                'message' => 'Order not found',
                'error' => 'not_found',
            ], 404);
        }

        try {
            return DB::transaction(function () use ($order) {
                // Restock only when the units are still on hand and unreturned. The
                // service re-reads the locked status so a shipped/delivered order
                // (goods gone) or an already-cancelled order (already restocked)
                // isn't restocked into phantom inventory.
                $this->orderService->restockForDeletion($order);

                // Delete order items, then the order itself.
                $order->items()->delete();
                $order->delete();

                return response()->json([
                    'message' => 'Order deleted successfully',
                ]);
            });
        } catch (BusinessRuleException $e) {
            // A partially shipped order: its shipped units cannot be restocked.
            return response()->json([
                'message' => $e->getMessage(),
                'error' => 'invalid_state_transition',
            ], 422);
        }
    }

    /**
     * Approve an order that is pending approval.
     */
    public function approve(ApproveOrderRequest $request, Order $order): JsonResponse
    {
        try {
            $approved = $this->orderService->approve($order, $request->user(), $request->validated()['notes'] ?? null);
        } catch (InvalidStateException $e) {
            return response()->json(['message' => $e->getMessage(), 'error' => $e->errorCode], 422);
        }

        return response()->json([
            'message' => 'Order approved successfully',
            'data' => new OrderResource($approved->load(['items', 'approver'])),
        ]);
    }

    /**
     * Reject an order that is pending approval.
     *
     * The order is cancelled and the stock it reserved is restored.
     */
    public function reject(RejectOrderRequest $request, Order $order): JsonResponse
    {
        try {
            $rejected = $this->orderService->reject($order, $request->user(), $request->validated()['notes']);
        } catch (InvalidStateException $e) {
            return response()->json(['message' => $e->getMessage(), 'error' => $e->errorCode], 422);
        }

        return response()->json([
            'message' => 'Order rejected',
            'data' => new OrderResource($rejected->load(['items', 'approver'])),
        ]);
    }

    /**
     * Correct an order marked shipped by mistake back to processing.
     *
     * Admins only. Refused (422 `invalid_state_transition`) unless the order
     * is shipped, none of its shipments has left the warehouse and it has no
     * returns. Clears `shipped_at`; stock is untouched.
     */
    public function revertShipment(Request $request, Order $order): JsonResponse
    {
        try {
            $order = $this->orderService->revertMistakenShipment($order, $request->user());
        } catch (InvalidStateException $e) {
            return response()->json(['message' => $e->getMessage(), 'error' => $e->errorCode], 422);
        }

        return response()->json([
            'message' => 'Order moved back to processing',
            'data' => new OrderResource($order->load('items')),
        ]);
    }

    /**
     * Email the order's invoice to the customer.
     *
     * Queues an email with the invoice PDF attached to the customer (or `to`,
     * when given), assigning the invoice number on first use. Re-sending is
     * allowed. Fails with 422 `missing_recipient` when there is no valid
     * recipient.
     *
     * @param  SendDocumentEmailRequest  $request  Optional `to`, `cc` (array or comma separated) and `message`
     */
    public function emailInvoice(SendDocumentEmailRequest $request, Order $order, OrderInvoiceEmailService $emails): JsonResponse
    {
        try {
            $order = $emails->send(
                $order,
                $request->user(),
                $request->recipient(),
                $request->ccList(),
                $request->customMessage(),
            );
        } catch (DocumentEmailException $e) {
            return response()->json(['message' => $e->getMessage(), 'error' => $e->reason], $e->status());
        }

        return response()->json([
            'message' => 'Invoice emailed',
            'data' => new OrderResource($order),
        ]);
    }

    /**
     * A Y-m-d date filter value, or null when it is missing or not a date,
     * so a malformed filter is ignored instead of reaching the database.
     */
    private function dateFilter(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        [$year, $month, $day] = array_map('intval', explode('-', $value));

        return checkdate($month, $day, $year) ? $value : null;
    }
}
