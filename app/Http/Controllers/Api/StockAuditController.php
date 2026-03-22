<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\StockAuditResource;
use App\Models\Inventory\StockAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Dedoc\Scramble\Attributes\QueryParameter;

/**
 * @tags Stock Audits
 */
class StockAuditController extends Controller
{
    /**
     * List stock audits.
     */
    #[QueryParameter('status', description: 'Filter by status', type: 'string', enum: ['draft', 'in_progress', 'completed', 'cancelled'])]
    #[QueryParameter('audit_type', description: 'Filter by audit type', type: 'string', enum: ['full', 'cycle', 'spot'])]
    #[QueryParameter('sort_by', description: 'Sort field (default: created_at)', type: 'string')]
    #[QueryParameter('sort_dir', description: 'Sort direction: asc or desc (default: desc)', type: 'string', enum: ['asc', 'desc'])]
    #[QueryParameter('per_page', description: 'Items per page (default: 15, max: 100)', type: 'integer')]
    public function index(Request $request): AnonymousResourceCollection
    {
        $organizationId = $request->user()->organization_id;

        $query = StockAudit::with(['warehouseLocation', 'creator'])
            ->withCount('items')
            ->forOrganization($organizationId)
            ->when($request->input('status'), function ($query, $status) {
                $query->byStatus($status);
            })
            ->when($request->input('audit_type'), function ($query, $type) {
                $query->byType($type);
            });

        // Sorting (allowlist to prevent SQL injection)
        $allowedSortColumns = ['created_at', 'updated_at', 'audit_number', 'status', 'started_at', 'completed_at'];
        $sortBy = in_array($request->input('sort_by'), $allowedSortColumns) ? $request->input('sort_by') : 'created_at';
        $sortDir = ($request->input('sort_dir') === 'asc') ? 'asc' : 'desc';
        $query->orderBy($sortBy, $sortDir);

        $perPage = min($request->input('per_page', 15), 100);
        $audits = $query->paginate($perPage);

        return StockAuditResource::collection($audits);
    }

    /**
     * Display the specified stock audit.
     *
     * @param Request $request The incoming HTTP request
     * @param StockAudit $stockAudit The stock audit to display
     * @return JsonResponse
     */
    public function show(Request $request, StockAudit $stockAudit): JsonResponse
    {
        if ($stockAudit->organization_id !== $request->user()->organization_id) {
            return response()->json([
                'message' => 'Stock audit not found',
                'error' => 'not_found',
            ], 404);
        }

        $stockAudit->load([
            'warehouseLocation',
            'creator',
            'items.product',
            'items.variant',
            'items.location',
            'items.countedByUser',
        ]);

        return response()->json([
            'data' => new StockAuditResource($stockAudit),
        ]);
    }
}
