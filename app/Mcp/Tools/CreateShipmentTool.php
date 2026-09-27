<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Exceptions\ShippingException;
use App\Mcp\Concerns\AuthenticatesMcpRequest;
use App\Models\Order\Order;
use App\Services\Shipping\ShipmentService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class CreateShipmentTool extends Tool
{
    use AuthenticatesMcpRequest;

    protected string $name = 'create_shipment';

    protected string $description = 'Record a shipment for a sales order with manually entered tracking (label bought elsewhere). Omit items to ship every unit not yet in a shipment. With mark_shipped the order moves to shipped once all its units have shipped, which can email the customer. Confirm the order and tracking number with the user first.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'order_id' => $schema->integer()->required()->description('The sales order to ship.'),
            'carrier_name' => $schema->string()->description('Carrier, for example UPS, USPS, FedEx, Canada Post.'),
            'service' => $schema->string()->description('Service level, for example Ground.'),
            'tracking_number' => $schema->string()->description('Carrier tracking number.'),
            'tracking_url' => $schema->string()->description('Tracking page URL. Built automatically for common carriers when omitted.'),
            'cost' => $schema->number()->description('What the shipment cost.'),
            'items' => $schema->array()->description('Lines to ship: [{order_item_id, quantity}]. Omit to ship everything remaining.'),
            'mark_shipped' => $schema->boolean()->description('Mark the shipment shipped now (default false).'),
            'notify_customer' => $schema->boolean()->description('Email the customer their tracking details when it ships. Defaults to the organization setting.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $this->authorize(['create_shipments']);

        $validated = $request->validate([
            'order_id' => ['required', 'integer'],
            'carrier_name' => ['nullable', 'string', 'max:100'],
            'service' => ['nullable', 'string', 'max:100'],
            'tracking_number' => ['nullable', 'string', 'max:100'],
            'tracking_url' => ['nullable', 'url:https,http', 'max:2048'],
            'cost' => ['nullable', 'numeric', 'min:0', 'max:999999'],
            'items' => ['nullable', 'array', 'max:500'],
            'items.*.order_item_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:0'],
            'mark_shipped' => ['nullable', 'boolean'],
            'notify_customer' => ['nullable', 'boolean'],
        ]);

        $order = Order::forOrganization($this->organizationId())->find($validated['order_id']);

        if ($order === null) {
            return Response::error("Order {$validated['order_id']} not found.");
        }

        $service = app(ShipmentService::class);

        try {
            $shipment = $service->create($order, ['carrier' => 'manual'] + $validated, $this->user());

            if (! empty($validated['mark_shipped'])) {
                $shipment = $service->markShipped($shipment, $this->user());
            }
        } catch (ShippingException $e) {
            return Response::error($e->getMessage());
        }

        return Response::json([
            'message' => 'Shipment recorded.',
            'shipment' => $shipment->fresh()->toPublicArray(),
            'order_status' => $order->fresh()->status->value,
        ]);
    }
}
