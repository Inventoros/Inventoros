<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\ApprovalException;
use App\Exceptions\DocumentEmailException;
use App\Exceptions\InvalidStateException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\PurchaseOrder\ReceivePurchaseOrderRequest;
use App\Http\Requests\Api\PurchaseOrder\StorePurchaseOrderRequest;
use App\Http\Requests\Api\PurchaseOrder\UpdatePurchaseOrderRequest;
use App\Http\Requests\SendDocumentEmailRequest;
use App\Http\Resources\PurchaseOrderResource;
use App\Models\Purchasing\PurchaseOrder;
use App\Services\ApprovalService;
use App\Services\PurchaseOrderEmailService;
use App\Services\PurchaseOrderService;
use App\Services\WarehouseAccessService;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * @tags Purchase Orders
 */
class PurchaseOrderController extends Controller
{
    /**
     * List purchase orders.
     */
    #[QueryParameter('search', description: 'Search by PO number or supplier name', type: 'string')]
    #[QueryParameter('status', description: 'Filter by status', type: 'string', enum: ['draft', 'sent', 'partial', 'received', 'cancelled'])]
    #[QueryParameter('supplier_id', description: 'Filter by supplier ID', type: 'integer')]
    #[QueryParameter('sort_by', description: 'Sort field (default: order_date)', type: 'string')]
    #[QueryParameter('sort_dir', description: 'Sort direction: asc or desc (default: desc)', type: 'string', enum: ['asc', 'desc'])]
    #[QueryParameter('per_page', description: 'Items per page (default: 15, max: 100)', type: 'integer')]
    public function index(Request $request): AnonymousResourceCollection
    {
        $organizationId = $request->user()->organization_id;

        $query = PurchaseOrder::with(['supplier', 'creator'])
            ->withCount('items')
            ->forOrganization($organizationId)
            ->when($request->input('search'), function ($query, $search) {
                $query->search($search);
            })
            ->when($request->input('status'), function ($query, $status) {
                $query->byStatus($status);
            })
            ->when($request->input('supplier_id'), function ($query, $supplierId) {
                $query->bySupplier($supplierId);
            });

        // Sorting (allowlist to prevent SQL injection)
        $allowedSortColumns = ['created_at', 'updated_at', 'order_date', 'po_number', 'status', 'total'];
        $sortBy = in_array($request->input('sort_by'), $allowedSortColumns) ? $request->input('sort_by') : 'order_date';
        $sortDir = ($request->input('sort_dir') === 'asc') ? 'asc' : 'desc';
        $query->orderBy($sortBy, $sortDir);

        $perPage = min($request->input('per_page', 15), 100);
        $purchaseOrders = $query->paginate($perPage);

        return PurchaseOrderResource::collection($purchaseOrders);
    }

    /**
     * Store a newly created purchase order.
     *
     * @param  Request  $request  The incoming HTTP request containing purchase order data
     */
    public function store(StorePurchaseOrderRequest $request, PurchaseOrderService $purchaseOrders): JsonResponse
    {
        $purchaseOrder = $purchaseOrders->create($request->user()->organization_id, $request->user(), $request->validated());

        return response()->json([
            'message' => 'Purchase order created successfully',
            'data' => new PurchaseOrderResource($purchaseOrder->load(['supplier', 'items.product'])),
        ], 201);
    }

    /**
     * Display the specified purchase order.
     *
     * @param  Request  $request  The incoming HTTP request
     * @param  PurchaseOrder  $purchaseOrder  The purchase order to display
     */
    public function show(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        if ($purchaseOrder->organization_id !== $request->user()->organization_id) {
            return response()->json([
                'message' => 'Purchase order not found',
                'error' => 'not_found',
            ], 404);
        }

        $purchaseOrder->load(['supplier', 'creator', 'items.product']);

        return response()->json([
            'data' => new PurchaseOrderResource($purchaseOrder),
        ]);
    }

    /**
     * Update the specified purchase order.
     *
     * @param  Request  $request  The incoming HTTP request containing updated purchase order data
     * @param  PurchaseOrder  $purchaseOrder  The purchase order to update
     */
    public function update(UpdatePurchaseOrderRequest $request, PurchaseOrder $purchaseOrder, PurchaseOrderService $purchaseOrders): JsonResponse
    {
        if ($purchaseOrder->organization_id !== $request->user()->organization_id) {
            return response()->json([
                'message' => 'Purchase order not found',
                'error' => 'not_found',
            ], 404);
        }

        try {
            $updated = $purchaseOrders->update($purchaseOrder, $request->user()->organization_id, $request->validated());
        } catch (InvalidStateException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'error' => $e->errorCode,
            ], 422);
        }

        return response()->json([
            'message' => 'Purchase order updated successfully',
            'data' => new PurchaseOrderResource($updated->load(['supplier', 'items.product'])),
        ]);
    }

    /**
     * Remove the specified purchase order.
     *
     * @param  Request  $request  The incoming HTTP request
     * @param  PurchaseOrder  $purchaseOrder  The purchase order to delete
     */
    public function destroy(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        if ($purchaseOrder->organization_id !== $request->user()->organization_id) {
            return response()->json([
                'message' => 'Purchase order not found',
                'error' => 'not_found',
            ], 404);
        }

        if ($purchaseOrder->status !== PurchaseOrder::STATUS_DRAFT) {
            return response()->json([
                'message' => 'Only draft purchase orders can be deleted',
                'error' => 'cannot_delete',
            ], 422);
        }

        $purchaseOrder->delete();

        return response()->json([
            'message' => 'Purchase order deleted successfully',
        ]);
    }

    /**
     * Receive items for a purchase order.
     *
     * @param  Request  $request  The incoming HTTP request containing received quantities
     * @param  PurchaseOrder  $purchaseOrder  The purchase order to receive items for
     */
    public function receive(ReceivePurchaseOrderRequest $request, PurchaseOrder $purchaseOrder, PurchaseOrderService $purchaseOrders): JsonResponse
    {
        if ($purchaseOrder->organization_id !== $request->user()->organization_id) {
            return response()->json([
                'message' => 'Purchase order not found',
                'error' => 'not_found',
            ], 404);
        }

        if (! $purchaseOrder->canReceiveItems()) {
            return response()->json([
                'message' => 'This purchase order cannot receive items',
                'error' => 'cannot_receive',
            ], 422);
        }

        // Goods land in each product's primary location; a restricted user
        // can only book them into their own warehouses.
        app(WarehouseAccessService::class)->authorizeReceiving($request->user(), $purchaseOrder, $request->validated()['items']);

        try {
            $receivedCount = $purchaseOrders->receive($purchaseOrder, $request->validated()['items']);
        } catch (\RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'error' => 'cannot_receive',
            ], 422);
        }

        if ($receivedCount === 0) {
            return response()->json([
                'message' => 'No items were received',
                'error' => 'no_items_received',
            ], 422);
        }

        return response()->json([
            'message' => 'Items received successfully',
            'data' => new PurchaseOrderResource($purchaseOrder->fresh()->load(['supplier', 'items.product'])),
        ]);
    }

    /**
     * Email a purchase order to its supplier and mark it sent.
     *
     * Queues an email to the supplier (or `to`, when given) with the PO PDF
     * attached. A draft moves to `sent`; an order already sent (or partly
     * received) can be re-sent and keeps its status. Fails with 422
     * `missing_recipient` when there is no valid recipient, in which case the
     * status is left unchanged.
     *
     * @param  SendDocumentEmailRequest  $request  Optional `to`, `cc` (array or comma separated) and `message`
     * @param  PurchaseOrder  $purchaseOrder  The purchase order to send
     */
    public function send(SendDocumentEmailRequest $request, PurchaseOrder $purchaseOrder, PurchaseOrderEmailService $emails): JsonResponse
    {
        if ($purchaseOrder->organization_id !== $request->user()->organization_id) {
            return response()->json([
                'message' => 'Purchase order not found',
                'error' => 'not_found',
            ], 404);
        }

        try {
            $purchaseOrder = $emails->send(
                $purchaseOrder,
                $request->user(),
                $request->recipient(),
                $request->ccList(),
                $request->customMessage(),
            );
        } catch (DocumentEmailException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'error' => $e->reason,
            ], 422);
        }

        return response()->json([
            'message' => 'Purchase order sent',
            'data' => new PurchaseOrderResource($purchaseOrder),
        ]);
    }

    /**
     * Submit a draft purchase order for approval.
     *
     * Only needed when the organization requires approval for this PO
     * (see the approval settings); approvers are notified.
     */
    public function submitForApproval(Request $request, PurchaseOrder $purchaseOrder, ApprovalService $approvals): JsonResponse
    {
        try {
            $purchaseOrder = $approvals->submitPurchaseOrder($purchaseOrder, $request->user());
        } catch (ApprovalException $e) {
            return response()->json(['message' => $e->getMessage(), 'error' => $e->reason], $e->status());
        }

        return response()->json([
            'message' => 'Purchase order submitted for approval',
            'data' => new PurchaseOrderResource($purchaseOrder),
        ]);
    }

    /**
     * Cancel a purchase order.
     *
     * @param  Request  $request  The incoming HTTP request
     * @param  PurchaseOrder  $purchaseOrder  The purchase order to cancel
     */
    public function cancel(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        if ($purchaseOrder->organization_id !== $request->user()->organization_id) {
            return response()->json([
                'message' => 'Purchase order not found',
                'error' => 'not_found',
            ], 404);
        }

        if (! $purchaseOrder->canBeCancelled()) {
            return response()->json([
                'message' => 'This purchase order cannot be cancelled',
                'error' => 'cannot_cancel',
            ], 422);
        }

        $purchaseOrder->cancel();

        return response()->json([
            'message' => 'Purchase order cancelled',
            'data' => new PurchaseOrderResource($purchaseOrder),
        ]);
    }
}
