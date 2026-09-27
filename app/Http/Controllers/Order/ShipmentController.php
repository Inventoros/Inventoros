<?php

declare(strict_types=1);

namespace App\Http\Controllers\Order;

use App\Exceptions\ShippingException;
use App\Http\Controllers\Controller;
use App\Models\Order\Order;
use App\Models\Shipping\Shipment;
use App\Services\Shipping\CarrierManager;
use App\Services\Shipping\ShipmentService;
use App\Services\Shipping\ShippingRate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * The order page's "Ship" flow. Manual tracking posts a normal form and gets
 * a redirect; the carrier flow (create, pick a rate, buy) talks JSON so the
 * modal can step through it without a page reload.
 */
class ShipmentController extends Controller
{
    public function __construct(
        private readonly ShipmentService $shipments,
        private readonly CarrierManager $carriers,
    ) {}

    /**
     * Create a shipment for chosen order lines. Manual shipments can be marked
     * shipped in the same step; carrier shipments come back with rates.
     */
    public function store(Request $request, Order $order): Response
    {
        $this->assertSameOrganization($request, $order->organization_id);

        $organizationId = (int) $request->user()->organization_id;

        $validated = $request->validate([
            'carrier' => ['required', 'string', Rule::in($this->carriers->available($organizationId))],
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
            'to_address' => ['nullable', 'array'],
            'to_address.*' => ['nullable', 'string', 'max:255'],
        ]);

        $shipment = null;

        try {
            $shipment = $this->shipments->create($order, $validated, $request->user());
            $rates = [];

            if ($validated['carrier'] === 'manual') {
                if ($request->boolean('mark_shipped')) {
                    $shipment = $this->shipments->markShipped($shipment, $request->user());
                }
            } else {
                $rates = $this->shipments->fetchRates($shipment);
            }
        } catch (ShippingException $e) {
            return $this->failure($request, $e, $shipment);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'shipment' => $shipment->fresh()->toPublicArray(),
                'rates' => array_map(fn (ShippingRate $r) => $r->toArray(), $rates),
            ], 201);
        }

        return redirect()->back()->with('success', $shipment->fresh()->status->hasLeft()
            ? 'Shipment recorded and marked shipped.'
            : 'Shipment created.');
    }

    /**
     * Re-quote rates for a pending carrier shipment.
     */
    public function rates(Request $request, Shipment $shipment): JsonResponse
    {
        $this->assertSameOrganization($request, $shipment->organization_id);

        try {
            $rates = $this->shipments->fetchRates($shipment);
        } catch (ShippingException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'shipment' => $shipment->fresh()->toPublicArray(),
            'rates' => array_map(fn (ShippingRate $r) => $r->toArray(), $rates),
        ]);
    }

    /**
     * Buy the label for one of the quoted rates.
     */
    public function buy(Request $request, Shipment $shipment): JsonResponse
    {
        $this->assertSameOrganization($request, $shipment->organization_id);

        $validated = $request->validate([
            'rate_id' => ['required', 'string', 'max:100'],
        ]);

        try {
            $shipment = $this->shipments->buyLabel($shipment, $validated['rate_id']);
        } catch (ShippingException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['shipment' => $shipment->toPublicArray()]);
    }

    /**
     * Mark a pending or labelled shipment as handed to the carrier.
     */
    public function ship(Request $request, Shipment $shipment): RedirectResponse
    {
        $this->assertSameOrganization($request, $shipment->organization_id);

        try {
            $this->shipments->markShipped($shipment, $request->user());
        } catch (ShippingException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', 'Shipment marked shipped.');
    }

    /**
     * Cancel a shipment that has not left, voiding its label if one was bought.
     */
    public function cancel(Request $request, Shipment $shipment): RedirectResponse
    {
        $this->assertSameOrganization($request, $shipment->organization_id);

        $hadLabel = $shipment->hasLabel();

        try {
            $this->shipments->cancel($shipment);
        } catch (ShippingException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', $hadLabel ? 'Label voided and shipment cancelled.' : 'Shipment cancelled.');
    }

    /**
     * Download (or print) the shipment's label.
     */
    public function label(Request $request, Shipment $shipment): Response
    {
        $this->assertSameOrganization($request, $shipment->organization_id);

        try {
            $contents = $this->shipments->labelContents($shipment);
        } catch (ShippingException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        $isPdf = str_starts_with($contents, '%PDF');
        $name = 'label-'.($shipment->tracking_number ?: $shipment->id).($isPdf ? '.pdf' : '.png');

        return response($contents, 200, [
            'Content-Type' => $isPdf ? 'application/pdf' : 'image/png',
            'Content-Disposition' => 'inline; filename="'.preg_replace('/[^A-Za-z0-9._-]/', '', $name).'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function failure(Request $request, ShippingException $e, ?Shipment $shipment): Response
    {
        if ($request->expectsJson()) {
            return response()->json([
                'message' => $e->getMessage(),
                'shipment' => $shipment?->fresh()?->toPublicArray(),
            ], 422);
        }

        return redirect()->back()->withInput()->with('error', $e->getMessage());
    }

    /**
     * Route binding is already tenant-scoped; this is the explicit check the
     * other order controllers make too.
     */
    private function assertSameOrganization(Request $request, int|string|null $organizationId): void
    {
        if ((int) $organizationId !== (int) $request->user()->organization_id) {
            abort(404);
        }
    }
}
