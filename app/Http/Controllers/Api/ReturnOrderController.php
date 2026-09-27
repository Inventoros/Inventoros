<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\HandlesApiResponses;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ReturnOrder\StoreReturnOrderRequest;
use App\Http\Requests\ReturnOrder\RejectReturnOrderRequest;
use App\Http\Resources\ReturnOrderResource;
use App\Models\Order\ReturnOrder;
use App\Services\ReturnOrderService;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Returns (RMA). Every transition runs through ReturnOrderService, the same
 * implementation the web ReturnOrderController uses.
 *
 * @tags Returns
 */
class ReturnOrderController extends Controller
{
    use HandlesApiResponses;

    public function __construct(private readonly ReturnOrderService $returns) {}

    /**
     * List return orders.
     */
    #[QueryParameter('search', description: 'Search by return number, order number or customer name', type: 'string')]
    #[QueryParameter('status', description: 'Filter by status', type: 'string', enum: ['pending', 'approved', 'received', 'completed', 'rejected'])]
    #[QueryParameter('type', description: 'Filter by type', type: 'string', enum: ['return', 'exchange'])]
    #[QueryParameter('order_id', description: 'Filter by order ID', type: 'integer')]
    #[QueryParameter('per_page', description: 'Items per page (default: 15, max: 100)', type: 'integer')]
    public function index(Request $request): AnonymousResourceCollection
    {
        $returns = ReturnOrder::with(['order', 'processor'])
            ->withCount('items')
            ->forOrganization($request->user()->organization_id)
            ->when($request->input('search'), function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('return_number', 'like', "%{$search}%")
                        ->orWhereHas('order', function ($oq) use ($search) {
                            $oq->where('order_number', 'like', "%{$search}%")
                                ->orWhere('customer_name', 'like', "%{$search}%");
                        });
                });
            })
            ->when($request->input('status'), fn ($query, $status) => $query->byStatus($status))
            ->when($request->input('type'), fn ($query, $type) => $query->byType($type))
            ->when($request->input('order_id'), fn ($query, $orderId) => $query->where('order_id', $orderId))
            ->latest()
            ->latest('id')
            ->paginate($this->perPage($request));

        return ReturnOrderResource::collection($returns);
    }

    /**
     * Create a return against an order.
     *
     * Each line is capped at the ordered quantity minus what earlier
     * (non-rejected) returns already claimed; exceeding it is a 422 on
     * `items.{index}.quantity`.
     */
    public function store(StoreReturnOrderRequest $request): JsonResponse
    {
        $returnOrder = $this->returns->create($request->user()->organization_id, $request->validated());

        return response()->json([
            'message' => 'Return created successfully',
            'data' => new ReturnOrderResource($this->loaded($returnOrder)),
        ], 201);
    }

    /**
     * Show a return order.
     */
    public function show(Request $request, ReturnOrder $returnOrder): JsonResponse
    {
        $this->ensureOwned($request, $returnOrder, 'Return');

        return response()->json(['data' => new ReturnOrderResource($this->loaded($returnOrder))]);
    }

    /**
     * Approve a pending return.
     */
    public function approve(Request $request, ReturnOrder $returnOrder): JsonResponse
    {
        return $this->transition($request, $returnOrder, fn () => $this->returns->approve($returnOrder, $request->user()), 'Return approved');
    }

    /**
     * Receive an approved return, restocking lines marked for restock.
     */
    public function receive(Request $request, ReturnOrder $returnOrder): JsonResponse
    {
        return $this->transition($request, $returnOrder, fn () => $this->returns->receive($returnOrder, $request->user()), 'Return received and inventory updated');
    }

    /**
     * Complete a received return.
     */
    public function complete(Request $request, ReturnOrder $returnOrder): JsonResponse
    {
        return $this->transition($request, $returnOrder, fn () => $this->returns->complete($returnOrder, $request->user()), 'Return completed');
    }

    /**
     * Reject a pending return.
     */
    public function reject(RejectReturnOrderRequest $request, ReturnOrder $returnOrder): JsonResponse
    {
        $notes = $request->validated()['notes'] ?? null;

        return $this->transition($request, $returnOrder, fn () => $this->returns->reject($returnOrder, $request->user(), $notes), 'Return rejected');
    }

    /**
     * @param  callable(): ReturnOrder  $action
     */
    private function transition(Request $request, ReturnOrder $returnOrder, callable $action, string $message): JsonResponse
    {
        $this->ensureOwned($request, $returnOrder, 'Return');

        try {
            $updated = $action();
        } catch (\RuntimeException $e) {
            return $this->stateError($e);
        }

        return response()->json([
            'message' => $message,
            'data' => new ReturnOrderResource($this->loaded($updated->fresh())),
        ]);
    }

    private function loaded(ReturnOrder $returnOrder): ReturnOrder
    {
        return $returnOrder->load(['order', 'items.product', 'processor']);
    }
}
