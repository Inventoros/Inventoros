<?php

declare(strict_types=1);

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Http\Requests\PurchaseOrder\QuickReorderRequest;
use App\Models\Purchasing\PurchaseOrder;
use App\Services\ReorderService;
use Illuminate\Http\RedirectResponse;

/**
 * "Create PO" on the dashboard reorder suggestions and the low-stock report.
 *
 * Turns the selected products into DRAFT purchase orders, one per primary
 * supplier, at the same suggested quantity the scheduled reorder check uses.
 * Products without a primary supplier are skipped and named back to the user.
 */
class QuickReorderController extends Controller
{
    public function __construct(private readonly ReorderService $reorder) {}

    public function store(QuickReorderRequest $request): RedirectResponse
    {
        $user = $request->user();
        $organizationId = (int) $user->organization_id;

        $products = $this->reorder->productsWithPrimarySupplier($organizationId)
            ->whereIn('id', $request->validated('product_ids'))
            ->orderBy('name')
            ->get();

        ['groups' => $groups, 'withoutSupplier' => $withoutSupplier] = $this->reorder->groupByPrimarySupplier($products);

        $missingMessage = $withoutSupplier === []
            ? null
            : 'Skipped, no primary supplier: '.$this->reorder->describe($withoutSupplier).'. Link a supplier on the product to reorder it.';

        if ($groups === []) {
            return back()->with('error', $missingMessage ?? 'Select at least one product to reorder.');
        }

        $orders = [];
        foreach ($groups as $group) {
            $orders[] = $this->reorder->createDraftPurchaseOrder(
                organizationId: $organizationId,
                supplierId: $group['supplier']->id,
                products: $group['products'],
                userId: $user->id,
                notes: 'Created from reorder suggestions',
                activityAction: 'quick_reorder',
                activityDescription: 'Draft purchase order',
            );
        }

        $numbers = collect($orders)->pluck('po_number')->implode(', ');
        $success = count($orders) === 1
            ? "Draft purchase order {$numbers} created."
            : count($orders)." draft purchase orders created: {$numbers}.";

        if (count($orders) === 1) {
            $po = $orders[0];
            $target = $user->hasPermission('edit_purchase_orders')
                ? route('purchase-orders.edit', $po)
                : route('purchase-orders.show', $po);
        } else {
            $target = route('purchase-orders.index', ['status' => PurchaseOrder::STATUS_DRAFT]);
        }

        $response = redirect()->to($target)->with('success', $success);

        return $missingMessage ? $response->with('warning', $missingMessage) : $response;
    }
}
