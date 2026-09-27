<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\ShipmentStatus;
use App\Exceptions\ShippingException;
use App\Http\Controllers\Controller;
use App\Models\Order\Order;
use App\Models\Shipping\Shipment;
use App\Services\Shipping\CarrierManager;
use App\Services\Shipping\ShipmentService;
use App\Services\Shipping\ShippingRate;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rule;

/**
 * Shipments over REST: manual tracking, or the carrier label flow (create
 * with carrier=easypost, then rates, buy-label, void-label) through the same
 * ShipmentService as the order page.
 *
 * @tags Shipments
 */
class ShipmentController extends Controller
{
    public function __construct(
        private readonly ShipmentService $shipments,
        private readonly CarrierManager $carriers,
    ) {}

    /**
     * List shipments.
     */
    #[QueryParameter('status', description: 'Filter by status', type: 'string', enum: ['pending', 'label_created', 'shipped', 'in_transit', 'delivered', 'exception', 'cancelled'])]
    #[QueryParameter('order_id', description: 'Filter by order ID', type: 'integer')]
    #[QueryParameter('carrier', description: 'Filter by integration (manual, easypost)', type: 'string')]
    #[QueryParameter('per_page', description: 'Items per page (default: 15, max: 100)', type: 'integer')]
    public function index(Request $request): JsonResponse
    {
        $query = Shipment::where('organization_id', $request->user()->organization_id)
            ->with('items.orderItem', 'warehouse')
            ->when($request->input('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->input('order_id'), fn ($q, $orderId) => $q->where('order_id', $orderId))
            ->when($request->input('carrier'), fn ($q, $carrier) => $q->where('carrier', $carrier))
            ->latest('id');

        return $this->paginated($query->paginate(min(max((int) $request->input('per_page', 15), 1), 100)));
    }

    /**
     * List an order's shipments.
     */
    public function forOrder(Request $request, Order $order): JsonResponse
    {
        $this->assertOwned($request, $order->organization_id);

        $shipments = Shipment::where('order_id', $order->id)
            ->with('items.orderItem', 'warehouse')
            ->latest('id')
            ->get();

        return response()->json(['data' => $shipments->map(fn (Shipment $s) => $s->toPublicArray())->all()]);
    }

    /**
     * Get a shipment.
     */
    public function show(Request $request, Shipment $shipment): JsonResponse
    {
        $this->assertOwned($request, $shipment->organization_id);

        return response()->json(['data' => $shipment->toPublicArray()]);
    }

    /**
     * Create a shipment.
     *
     * `carrier` is `manual` (default; enter tracking yourself) or `easypost`
     * when it is configured (then quote rates and buy a label). Omit `items`
     * to ship every unit not yet in a shipment. Set `mark_shipped` to hand a
     * manual shipment to the carrier in the same call.
     */
    public function store(Request $request, Order $order): JsonResponse
    {
        $this->assertOwned($request, $order->organization_id);
        $organizationId = (int) $request->user()->organization_id;

        $validated = $request->validate([
            'carrier' => ['nullable', 'string', Rule::in($this->carriers->available($organizationId))],
            'to_address' => ['nullable', 'array'],
            'to_address.*' => ['nullable', 'string', 'max:255'],
            'carrier_name' => ['nullable', 'string', 'max:100'],
            'service' => ['nullable', 'string', 'max:100'],
            'tracking_number' => ['nullable', 'string', 'max:100'],
            'tracking_url' => ['nullable', 'url:https,http', 'max:2048'],
            'cost' => ['nullable', 'numeric', 'min:0', 'max:999999'],
            'currency' => ['nullable', 'string', 'size:3'],
            'warehouse_id' => ['nullable', 'integer', Rule::exists('warehouses', 'id')->where('organization_id', $organizationId)],
            'weight_oz' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'length_in' => ['nullable', 'numeric', 'min:0', 'max:10000'],
            'width_in' => ['nullable', 'numeric', 'min:0', 'max:10000'],
            'height_in' => ['nullable', 'numeric', 'min:0', 'max:10000'],
            'notify_customer' => ['nullable', 'boolean'],
            'mark_shipped' => ['nullable', 'boolean'],
            'items' => ['nullable', 'array', 'max:500'],
            'items.*.order_item_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:0'],
        ]);

        try {
            $validated['carrier'] ??= 'manual';

            // mark_shipped only applies to manual tracking; a carrier shipment
            // is shipped after its label is bought.
            if ($validated['carrier'] !== 'manual' && $request->boolean('mark_shipped')) {
                throw new ShippingException('Buy a label before marking a carrier shipment shipped.');
            }

            $shipment = $this->shipments->create($order, $validated, $request->user());

            if ($request->boolean('mark_shipped')) {
                $shipment = $this->shipments->markShipped($shipment, $request->user());
            }
        } catch (ShippingException $e) {
            return $this->refused($e);
        }

        return response()->json([
            'message' => 'Shipment created.',
            'data' => $shipment->fresh()->toPublicArray(),
        ], 201);
    }

    /**
     * Mark a shipment shipped.
     */
    public function ship(Request $request, Shipment $shipment): JsonResponse
    {
        $this->assertOwned($request, $shipment->organization_id);

        try {
            $shipment = $this->shipments->markShipped($shipment, $request->user());
        } catch (ShippingException $e) {
            return $this->refused($e);
        }

        return response()->json(['data' => $shipment->fresh()->toPublicArray()]);
    }

    /**
     * Quote carrier rates for a pending carrier shipment.
     *
     * The quoted rates are remembered on the shipment; buy-label only accepts
     * one of them.
     */
    public function rates(Request $request, Shipment $shipment): JsonResponse
    {
        $this->assertOwned($request, $shipment->organization_id);

        try {
            $rates = $this->shipments->fetchRates($shipment);
        } catch (ShippingException $e) {
            return $this->refused($e);
        }

        return response()->json([
            'data' => array_map(fn (ShippingRate $rate) => [
                'id' => $rate->id,
                'carrier' => $rate->carrier,
                'service' => $rate->service,
                'amount' => $rate->amount,
                'currency' => $rate->currency,
                'delivery_days' => $rate->deliveryDays,
            ], $rates),
        ]);
    }

    /**
     * Buy the label for one of the shipment's quoted rates.
     */
    public function buyLabel(Request $request, Shipment $shipment): JsonResponse
    {
        $this->assertOwned($request, $shipment->organization_id);

        $validated = $request->validate([
            'rate_id' => ['required', 'string', 'max:100'],
        ]);

        try {
            $shipment = $this->shipments->buyLabel($shipment, $validated['rate_id']);
        } catch (ShippingException $e) {
            return $this->refused($e);
        }

        return response()->json(['data' => $shipment->toPublicArray()]);
    }

    /**
     * Void a shipment's label (asking the carrier for a refund) and cancel the
     * shipment, freeing its units to ship again. Refused once it has shipped;
     * if the carrier refuses the refund nothing changes.
     */
    public function voidLabel(Request $request, Shipment $shipment): JsonResponse
    {
        $this->assertOwned($request, $shipment->organization_id);

        try {
            $shipment = $this->shipments->cancel($shipment);
        } catch (ShippingException $e) {
            return $this->refused($e);
        }

        return response()->json(['data' => $shipment->fresh()->toPublicArray()]);
    }

    private function refused(ShippingException $e): JsonResponse
    {
        return response()->json([
            'message' => $e->getMessage(),
            'error' => 'shipping_error',
        ], 422);
    }

    private function paginated(LengthAwarePaginator $page): JsonResponse
    {
        return response()->json([
            'data' => collect($page->items())->map(fn (Shipment $s) => $s->toPublicArray())->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }

    private function assertOwned(Request $request, int|string|null $organizationId): void
    {
        if ((int) $organizationId !== (int) $request->user()->organization_id) {
            abort(response()->json(['message' => 'Not found', 'error' => 'not_found'], 404));
        }
    }
}
