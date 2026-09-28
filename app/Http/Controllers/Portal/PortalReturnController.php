<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Enums\OrderStatus;
use App\Models\ActivityLog;
use App\Models\Order\Order;
use App\Models\Order\OrderItem;
use App\Models\Order\ReturnOrder;
use App\Models\Order\ReturnOrderItem;
use App\Models\Scopes\OrganizationScope;
use App\Services\NotificationService;
use App\Services\ReturnOrderService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Return (RMA) requests from the customer portal.
 *
 * A contact can request a return for lines of their own delivered orders.
 * The request is created as a pending ReturnOrder through the same
 * ReturnOrderService the staff screen uses, so quantity caps, pricing and the
 * product-from-line rule are identical. Nothing moves in stock until staff
 * approve and receive it.
 */
class PortalReturnController extends PortalController
{
    public function index(Request $request): Response
    {
        $contact = $this->contact($request);

        $returns = $this->returns($contact)
            ->with(['order' => fn ($q) => $q->withoutGlobalScope(OrganizationScope::class)])
            ->latest('id')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (ReturnOrder $r) => $this->returnSummary($r));

        return Inertia::render('Portal/Returns/Index', [
            'returns' => $returns,
        ]);
    }

    public function show(Request $request, string $returnOrder): Response
    {
        $contact = $this->contact($request);

        abort_unless(ctype_digit($returnOrder), 404);

        $record = $this->returns($contact)
            ->with(['order' => fn ($q) => $q->withoutGlobalScope(OrganizationScope::class), 'items.orderItem'])
            ->whereKey((int) $returnOrder)
            ->firstOrFail();

        return Inertia::render('Portal/Returns/Show', [
            'returnOrder' => $this->returnSummary($record) + [
                'reason' => $record->reason,
                'notes' => $record->notes,
                'items' => $record->items->map(fn (ReturnOrderItem $item) => [
                    'id' => $item->id,
                    'product_name' => $item->orderItem?->product_name,
                    'sku' => $item->orderItem?->sku,
                    'quantity' => (int) $item->quantity,
                    'condition' => $item->condition,
                ])->values(),
            ],
        ]);
    }

    public function create(Request $request, string $order, ReturnOrderService $returns): Response|RedirectResponse
    {
        $contact = $this->contact($request);
        $record = $this->findOrder($contact, $order);

        if ($record->status !== OrderStatus::DELIVERED) {
            return $this->notReturnable($record);
        }

        $record->load('items');
        $returned = $returns->returnedQuantities($record);
        $paidNets = $returns->paidLineNets($record);

        return Inertia::render('Portal/Returns/Create', [
            'order' => $this->orderSummary($record) + [
                'items' => $record->items
                    ->map(fn (OrderItem $item) => $this->lineSummary($item, (int) $returned->get($item->id, 0)) + [
                        // What was paid for the whole line after discounts;
                        // a return refunds its share of this.
                        'paid_net' => $paidNets[$item->id] ?? null,
                    ])
                    ->values(),
            ],
        ]);
    }

    public function store(Request $request, string $order, ReturnOrderService $returns): RedirectResponse
    {
        $contact = $this->contact($request);
        $record = $this->findOrder($contact, $order);

        if ($record->status !== OrderStatus::DELIVERED) {
            return $this->notReturnable($record);
        }

        $validated = $request->validate([
            'type' => ['required', 'in:return,exchange'],
            'reason' => ['required', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.order_item_id' => ['required', 'integer', 'distinct'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'items.*.condition' => ['required', 'in:new,used,damaged'],
        ]);

        // The customer states each line's condition; staff decide the rest
        // when they approve and receive the return. Damaged goods are not
        // put back on the shelf by default.
        $validated['items'] = array_map(fn (array $item) => $item + [
            'restock' => $item['condition'] !== 'damaged',
        ], $validated['items']);

        // The order has already been matched to this contact's customer
        // above. There is no staff actor, so no staff warehouse restriction.
        $returnOrder = $returns->create((int) $contact->organization_id, null, $validated + ['order_id' => $record->id]);

        $this->recordRequest($request, $returnOrder, $record);
        NotificationService::createPortalReturnRequestedNotification($returnOrder, $record, $contact);

        return redirect()
            ->route('portal.returns.show', ['returnOrder' => $returnOrder->id])
            ->with('success', "Return {$returnOrder->return_number} requested. We'll review it and let you know.");
    }

    private function notReturnable(Order $order): RedirectResponse
    {
        return redirect()
            ->route('portal.orders.show', ['order' => $order->id])
            ->with('error', 'Returns can be requested once an order has been delivered.');
    }

    private function recordRequest(Request $request, ReturnOrder $returnOrder, Order $order): void
    {
        $contact = $this->contact($request);

        ActivityLog::create([
            'organization_id' => $returnOrder->organization_id,
            'user_id' => null,
            'category' => ActivityLog::CATEGORY_AUDIT,
            'subject_type' => ReturnOrder::class,
            'subject_id' => $returnOrder->id,
            'action' => 'portal.return_requested',
            'description' => "Return {$returnOrder->return_number} requested in the customer portal by {$contact->name}",
            'properties' => [
                'contact_id' => $contact->id,
                'contact_email' => $contact->email,
                'customer_id' => $contact->customer_id,
                'order_id' => $order->id,
                'order_number' => $order->order_number,
            ],
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 1000),
        ]);
    }
}
