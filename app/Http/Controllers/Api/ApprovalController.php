<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\ApprovalException;
use App\Http\Controllers\Controller;
use App\Http\Middleware\CheckApiPermission;
use App\Services\ApprovalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Approval queue and decisions for purchase orders, stock adjustments and
 * stock transfers. `type` is one of purchase_order, stock_adjustment,
 * stock_transfer.
 *
 * @tags Approvals
 */
class ApprovalController extends Controller
{
    public function __construct(private readonly ApprovalService $approvals) {}

    /**
     * List the requests waiting for the current user's decision.
     */
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->approvals->pendingFor($request->user()),
        ]);
    }

    /**
     * List the current user's own approval requests.
     */
    public function mine(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->approvals->requestedBy($request->user()),
        ]);
    }

    /**
     * Approve a pending request.
     */
    public function approve(Request $request, string $type, int $id): JsonResponse
    {
        $validated = $request->validate(['notes' => ['nullable', 'string', 'max:1000']]);

        return $this->decide($type, fn () => $this->approvals->approve($request->user(), $type, $id, $validated['notes'] ?? null), 'Request approved');
    }

    /**
     * Reject a pending request. A reason is required.
     */
    public function reject(Request $request, string $type, int $id): JsonResponse
    {
        $validated = $request->validate(['notes' => ['required', 'string', 'max:1000']]);

        return $this->decide($type, fn () => $this->approvals->reject($request->user(), $type, $id, $validated['notes']), 'Request rejected');
    }

    private function decide(string $type, callable $action, string $message): JsonResponse
    {
        // A scoped token must carry the ability for this particular type,
        // not just any approve_* ability (the route middleware checks "any").
        $tokenAllows = CheckApiPermission::tokenAllows(request()->user()->currentAccessToken());
        if (! $tokenAllows(ApprovalService::permissionFor($type)->value)) {
            return response()->json([
                'message' => 'This token cannot approve this kind of request.',
                'error' => ApprovalException::FORBIDDEN,
            ], 403);
        }

        try {
            $subject = $action();
        } catch (ApprovalException $e) {
            return response()->json(['message' => $e->getMessage(), 'error' => $e->reason], $e->status());
        }

        return response()->json([
            'message' => $message,
            'data' => $this->approvals->describe($type, $subject),
        ]);
    }
}
