<?php

declare(strict_types=1);

namespace App\Services\Shipping;

use App\Enums\OrderStatus;
use App\Enums\ShipmentStatus;
use App\Exceptions\ShippingException;
use App\Mail\ShipmentShippedEmail;
use App\Models\Order\Order;
use App\Models\Order\OrderItem;
use App\Models\Shipping\Shipment;
use App\Models\Shipping\ShipmentItem;
use App\Models\Shipping\ShippingSetting;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\OrderService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/**
 * The single home for shipment state. Every surface (web, REST, MCP, the
 * tracking poller, the EasyPost webhook) goes through here, so the rules live
 * in one place:
 *
 *  - A shipment packs order lines; the quantities across an order's
 *    non-cancelled shipments can never exceed the order's line quantities.
 *  - Shipping never touches stock. Stock left inventory when the order was
 *    created (OrderService::create); a shipment only records which of those
 *    units went in which box.
 *  - Order status follows the shipments through OrderService::transitionStatus
 *    (the existing status flow, so OrderObserver notifications and webhooks
 *    fire as for a manual status change):
 *      some units shipped, some not       pending becomes processing
 *      every unit in a shipment that left  becomes shipped
 *      every such shipment delivered       becomes delivered
 *    Partial shipment is deliberately NOT a new order status: the status enum
 *    is matched in validation rules, filters and guards across the web, REST,
 *    GraphQL and MCP surfaces, and "processing" already means "in fulfilment".
 *  - A bought label is persisted (tracking, label URL, raw carrier response)
 *    in its own write straight after the carrier answers, before the label
 *    file is downloaded, so a paid label is never lost to a later failure.
 */
final class ShipmentService
{
    public const LABEL_DISK = 'local';

    public function __construct(
        private readonly OrderService $orders,
        private readonly CarrierManager $carriers,
    ) {}

    /**
     * Allocate order lines to a new pending shipment.
     *
     * @param  array<string, mixed>  $data  carrier, carrier_name, service,
     *                                      tracking_number, tracking_url, cost,
     *                                      currency, warehouse_id, weight_oz,
     *                                      length_in, width_in, height_in,
     *                                      notify_customer, and items
     *                                      [{order_item_id, quantity}]; no items
     *                                      means every unit not yet allocated.
     *
     * @throws ShippingException
     */
    public function create(Order $order, array $data, ?User $user = null): Shipment
    {
        $organizationId = (int) $order->organization_id;
        $carrierKey = (string) ($data['carrier'] ?? 'manual');
        $this->carriers->for($carrierKey, $organizationId);
        $settings = ShippingSetting::forOrganization($organizationId);

        $shipment = DB::transaction(function () use ($order, $data, $user, $carrierKey, $settings, $organizationId) {
            $locked = Order::withoutGlobalScopes()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            if (in_array($locked->status, [OrderStatus::CANCELLED, OrderStatus::DELIVERED], true)) {
                throw new ShippingException("Order {$locked->order_number} is {$locked->status->value} and cannot be shipped.");
            }

            $lines = $this->resolveLines($locked, $data['items'] ?? []);
            $warehouseId = $this->resolveWarehouse($locked, $data['warehouse_id'] ?? null, $settings);
            $parcel = $settings->default_parcel ?? [];

            $trackingNumber = $this->clean($data['tracking_number'] ?? null);
            $carrierName = $this->clean($data['carrier_name'] ?? null);

            $shipment = Shipment::create([
                'organization_id' => $organizationId,
                'order_id' => $locked->id,
                'warehouse_id' => $warehouseId,
                'created_by' => $user?->id,
                'carrier' => $carrierKey,
                'carrier_name' => $carrierName,
                'service' => $this->clean($data['service'] ?? null),
                'tracking_number' => $trackingNumber,
                'tracking_url' => $this->clean($data['tracking_url'] ?? null) ?? TrackingUrls::guess($carrierName, $trackingNumber),
                'to_address' => $this->address($data['to_address'] ?? null) ?? $this->defaultToAddress($locked),
                'from_address' => $this->address($data['from_address'] ?? null) ?? $this->defaultFromAddress($locked, $warehouseId),
                'cost' => $data['cost'] ?? null,
                'currency' => $data['currency'] ?? $locked->currency ?? 'USD',
                'weight_oz' => $data['weight_oz'] ?? ($parcel['weight_oz'] ?? null),
                'length_in' => $data['length_in'] ?? ($parcel['length_in'] ?? null),
                'width_in' => $data['width_in'] ?? ($parcel['width_in'] ?? null),
                'height_in' => $data['height_in'] ?? ($parcel['height_in'] ?? null),
                'status' => ShipmentStatus::PENDING,
                'notify_customer' => (bool) ($data['notify_customer'] ?? $settings->notify_customers),
            ]);

            foreach ($lines as $orderItemId => $quantity) {
                ShipmentItem::create([
                    'shipment_id' => $shipment->id,
                    'order_item_id' => $orderItemId,
                    'quantity' => $quantity,
                ]);
            }

            return $shipment;
        });

        $shipment->load('items');

        DB::afterCommit(fn () => do_action('shipment_created', $shipment, $user));

        return $shipment;
    }

    /**
     * Quote carrier rates for a pending shipment and remember them, so a later
     * purchase can only use a rate that was actually offered.
     *
     * @return array<int, ShippingRate>
     *
     * @throws ShippingException
     */
    public function fetchRates(Shipment $shipment): array
    {
        if ($shipment->status !== ShipmentStatus::PENDING) {
            throw new ShippingException('Rates can only be fetched for a pending shipment.');
        }

        $carrier = $this->carriers->for($shipment->carrier, (int) $shipment->organization_id);

        if (! $carrier->sellsLabels()) {
            throw new ShippingException('This shipment uses manual tracking; there are no rates to fetch.');
        }

        $rates = $carrier->rates($shipment);

        $shipment->forceFill([
            'carrier_shipment_id' => $rates[0]->carrierShipmentId ?? $shipment->carrier_shipment_id,
            'carrier_rates' => array_map(fn (ShippingRate $r) => $r->toArray(), $rates),
        ])->save();

        return $rates;
    }

    /**
     * Buy the label for one of the shipment's quoted rates.
     *
     * @throws ShippingException
     */
    public function buyLabel(Shipment $shipment, string $rateId): Shipment
    {
        $lock = Cache::lock("shipments:buy:{$shipment->id}", 120);

        if (! $lock->get()) {
            throw new ShippingException('A label is already being bought for this shipment.');
        }

        try {
            $shipment->refresh();

            if ($shipment->status !== ShipmentStatus::PENDING || $shipment->label_url !== null) {
                throw new ShippingException('This shipment already has a label.');
            }

            $rateData = collect($shipment->carrier_rates ?? [])->firstWhere('id', $rateId);

            if ($rateData === null) {
                throw new ShippingException('That rate is no longer available. Fetch rates again.');
            }

            $carrier = $this->carriers->for($shipment->carrier, (int) $shipment->organization_id);
            $label = $carrier->buyLabel($shipment, ShippingRate::fromArray($rateData));

            // Paid for: record it before anything else can fail.
            $shipment->forceFill([
                'status' => ShipmentStatus::LABEL_CREATED,
                'carrier_name' => $label->carrierName,
                'service' => $label->service,
                'tracking_number' => $label->trackingNumber,
                'tracking_url' => $label->trackingUrl ?? TrackingUrls::guess($label->carrierName, $label->trackingNumber),
                'label_url' => $label->labelUrl,
                'carrier_tracker_id' => $label->carrierTrackerId,
                'cost' => $label->cost,
                'currency' => $label->currency,
                'carrier_response' => $label->raw,
            ])->save();
        } finally {
            $lock->release();
        }

        $this->storeLabel($shipment);

        return $shipment->fresh();
    }

    /**
     * The label file's bytes, downloading and caching it on the private disk
     * when only the carrier's URL is known.
     *
     * @throws ShippingException
     */
    public function labelContents(Shipment $shipment): string
    {
        $disk = Storage::disk(self::LABEL_DISK);

        if ($shipment->label_path !== null && $disk->exists($shipment->label_path)) {
            return (string) $disk->get($shipment->label_path);
        }

        if ($shipment->label_url === null || ! $this->storeLabel($shipment)) {
            throw new ShippingException('The label file is not available.');
        }

        return (string) $disk->get((string) $shipment->label_path);
    }

    /**
     * Hand the shipment to the carrier.
     *
     * @throws ShippingException
     */
    public function markShipped(Shipment $shipment, ?User $user = null, ?Carbon $at = null): Shipment
    {
        if ($shipment->status->hasLeft()) {
            return $shipment;
        }

        if (! $shipment->status->isCancellable()) {
            throw new ShippingException("A {$shipment->status->label()} shipment cannot be marked shipped.");
        }

        $shipment = $this->applyTrackingStatus($shipment, ShipmentStatus::SHIPPED, null, $at);

        // applyTrackingStatus() leaves the shipment where it was when, under
        // its lock, the shipment or its order turned out to be cancelled.
        if (! $shipment->status->hasLeft()) {
            throw new ShippingException('This shipment can no longer ship: it or its order has been cancelled.');
        }

        return $shipment;
    }

    /**
     * Apply a status reported by the carrier (or the user). Moving from
     * pending/label_created straight to in_transit or delivered also stamps
     * shipped_at. A delivered shipment stays delivered; a cancelled one, or
     * one whose order was cancelled (its stock already went back), ignores
     * updates.
     */
    public function applyTrackingStatus(Shipment $shipment, ShipmentStatus $status, ?string $detail = null, ?Carbon $at = null): Shipment
    {
        $at ??= now();

        [$shipment, $wasLeft, $deliveredNow] = DB::transaction(function () use ($shipment, $status, $detail, $at) {
            // Order before shipment, the same order OrderService::cancel()
            // takes them in, so a concurrent cancel and ship cannot deadlock
            // and cannot both win.
            $order = Order::withoutGlobalScopes()->whereKey($shipment->order_id)->lockForUpdate()->first();
            $locked = Shipment::withoutGlobalScopes()->whereKey($shipment->getKey())->lockForUpdate()->firstOrFail();
            $wasLeft = $locked->status->hasLeft();

            $attributes = ['last_tracked_at' => now()];

            if ($detail !== null) {
                $attributes['tracking_status_detail'] = mb_substr($detail, 0, 255);
            }

            $ignore = $locked->status === ShipmentStatus::CANCELLED
                || $locked->status === ShipmentStatus::DELIVERED
                || ($order?->status === OrderStatus::CANCELLED && ! $locked->status->hasLeft())
                || ! $status->hasLeft();

            $deliveredNow = false;

            if (! $ignore && $status !== $locked->status) {
                $attributes['status'] = $status;
                $attributes['shipped_at'] = $locked->shipped_at ?? $at;

                if ($status === ShipmentStatus::DELIVERED) {
                    $attributes['delivered_at'] = $at;
                    $deliveredNow = true;
                }
            }

            $locked->forceFill($attributes)->save();

            if (isset($attributes['status'])) {
                $this->syncOrderStatus($locked);
            }

            return [$locked, $wasLeft, $deliveredNow];
        });

        if (! $wasLeft && $shipment->status->hasLeft()) {
            DB::afterCommit(fn () => $this->notifyCustomer($shipment));
        }

        if ($deliveredNow) {
            DB::afterCommit(fn () => do_action('shipment_delivered', $shipment));
        }

        return $shipment;
    }

    /**
     * Cancel a shipment that has not left, voiding its label with the carrier
     * first when one was bought. If the void fails nothing changes.
     *
     * @throws ShippingException
     */
    public function cancel(Shipment $shipment): Shipment
    {
        // Re-read and re-check under the same order-then-shipment locks
        // applyTrackingStatus() takes, so a concurrent "mark shipped" cannot
        // slip in between the check and the write.
        return DB::transaction(function () use ($shipment) {
            Order::withoutGlobalScopes()->whereKey($shipment->order_id)->lockForUpdate()->first();
            $locked = Shipment::withoutGlobalScopes()->whereKey($shipment->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->status->isCancellable()) {
                throw new ShippingException("A {$locked->status->label()} shipment cannot be cancelled.");
            }

            if ($locked->status === ShipmentStatus::LABEL_CREATED) {
                $this->carriers->for($locked->carrier, (int) $locked->organization_id)->void($locked);
            }

            $locked->forceFill(['status' => ShipmentStatus::CANCELLED])->save();

            $shipment->setRawAttributes($locked->getAttributes(), true);

            return $shipment;
        });
    }

    /**
     * Poll the carrier for a shipment's latest tracking state.
     *
     * @throws ShippingException
     */
    public function refreshTracking(Shipment $shipment): Shipment
    {
        $update = $this->carriers->for($shipment->carrier, (int) $shipment->organization_id)->track($shipment);

        if ($update === null) {
            $shipment->forceFill(['last_tracked_at' => now()])->save();

            return $shipment;
        }

        return $this->applyTrackingUpdate($shipment, $update);
    }

    /**
     * Apply a TrackingUpdate (from polling or the webhook).
     */
    public function applyTrackingUpdate(Shipment $shipment, TrackingUpdate $update): Shipment
    {
        $extra = array_filter([
            'carrier_tracker_id' => $shipment->carrier_tracker_id === null ? $update->carrierTrackerId : null,
            'tracking_url' => $shipment->tracking_url === null ? $update->trackingUrl : null,
        ]);

        if ($extra !== []) {
            $shipment->forceFill($extra)->save();
        }

        if ($update->status === null) {
            $shipment->forceFill([
                'last_tracked_at' => now(),
                'tracking_status_detail' => $update->detail ? mb_substr($update->detail, 0, 255) : $shipment->tracking_status_detail,
            ])->save();

            return $shipment;
        }

        return $this->applyTrackingStatus($shipment, $update->status, $update->detail, $update->occurredAt);
    }

    /**
     * Units of each order line not yet in a non-cancelled shipment.
     *
     * @return array<int, int> order_item_id => remaining quantity
     */
    public function remainingQuantities(Order $order): array
    {
        $allocated = $this->allocatedQuantities($order, onlyLeft: false);

        return OrderItem::where('order_id', $order->getKey())
            ->orderBy('id')
            ->get(['id', 'quantity'])
            ->mapWithKeys(fn (OrderItem $item) => [
                $item->id => max(0, (int) $item->quantity - ($allocated[$item->id] ?? 0)),
            ])
            ->all();
    }

    /**
     * Bring the order's status in line with its shipments. Runs inside the
     * caller's transaction.
     */
    private function syncOrderStatus(Shipment $shipment): void
    {
        $order = Order::withoutGlobalScopes()->whereKey($shipment->order_id)->lockForUpdate()->first();

        if ($order === null || $order->status === OrderStatus::CANCELLED) {
            return;
        }

        $shipped = $this->allocatedQuantities($order, onlyLeft: true);
        $items = OrderItem::where('order_id', $order->id)->get(['id', 'quantity']);

        $fullyShipped = $items->isNotEmpty()
            && $items->every(fn (OrderItem $i) => ($shipped[$i->id] ?? 0) >= (int) $i->quantity);

        if (! $fullyShipped) {
            if ($shipped !== [] && $order->status === OrderStatus::PENDING) {
                $this->orders->transitionStatus($order, OrderStatus::PROCESSING);
            }

            return;
        }

        $allDelivered = ! Shipment::withoutGlobalScopes()
            ->where('order_id', $order->id)
            ->leftWarehouse()
            ->where('status', '!=', ShipmentStatus::DELIVERED->value)
            ->exists();

        if ($allDelivered) {
            if ($order->status !== OrderStatus::DELIVERED) {
                if ($order->status !== OrderStatus::SHIPPED) {
                    $order = $this->orders->transitionStatus($order, OrderStatus::SHIPPED);
                }
                $this->orders->transitionStatus($order, OrderStatus::DELIVERED);
            }

            return;
        }

        if (in_array($order->status, [OrderStatus::PENDING, OrderStatus::PROCESSING], true)) {
            $this->orders->transitionStatus($order, OrderStatus::SHIPPED);
        }
    }

    /**
     * @return array<int, int> order_item_id => quantity
     */
    private function allocatedQuantities(Order $order, bool $onlyLeft): array
    {
        $query = ShipmentItem::query()
            ->join('shipments', 'shipments.id', '=', 'shipment_items.shipment_id')
            ->where('shipments.order_id', $order->getKey())
            ->where('shipments.status', '!=', ShipmentStatus::CANCELLED->value);

        if ($onlyLeft) {
            $query->whereIn('shipments.status', ShipmentStatus::leftValues());
        }

        return $query
            ->groupBy('shipment_items.order_item_id')
            ->selectRaw('shipment_items.order_item_id as order_item_id, SUM(shipment_items.quantity) as qty')
            ->pluck('qty', 'order_item_id')
            ->map(fn ($q) => (int) $q)
            ->all();
    }

    /**
     * @param  array<int, array{order_item_id?: mixed, quantity?: mixed}>  $requested
     * @return array<int, int> order_item_id => quantity
     *
     * @throws ShippingException
     */
    private function resolveLines(Order $order, array $requested): array
    {
        $remaining = $this->remainingQuantities($order);

        if ($requested === []) {
            $lines = array_filter($remaining, fn (int $qty) => $qty > 0);

            if ($lines === []) {
                throw new ShippingException('Every item on this order is already in a shipment.');
            }

            return $lines;
        }

        $lines = [];

        foreach ($requested as $line) {
            $itemId = (int) ($line['order_item_id'] ?? 0);
            $quantity = (int) ($line['quantity'] ?? 0);

            if ($quantity === 0) {
                continue;
            }

            if (! array_key_exists($itemId, $remaining)) {
                throw new ShippingException("Line {$itemId} is not on order {$order->order_number}.");
            }

            $lines[$itemId] = ($lines[$itemId] ?? 0) + $quantity;

            if ($quantity < 0 || $lines[$itemId] > $remaining[$itemId]) {
                throw new ShippingException(
                    "Only {$remaining[$itemId]} unit(s) of line {$itemId} are left to ship."
                );
            }
        }

        if ($lines === []) {
            throw new ShippingException('Choose at least one item to ship.');
        }

        return $lines;
    }

    private function resolveWarehouse(Order $order, mixed $requested, ShippingSetting $settings): ?int
    {
        $candidate = $requested ?? $order->warehouse_id ?? $settings->default_warehouse_id;

        if ($candidate === null) {
            return null;
        }

        $exists = Warehouse::withoutGlobalScopes()
            ->whereKey($candidate)
            ->where('organization_id', $order->organization_id)
            ->exists();

        if (! $exists) {
            throw new ShippingException('The ship-from warehouse does not belong to this organization.');
        }

        return (int) $candidate;
    }

    /**
     * Download the carrier's label file to the private disk. Failure is logged
     * and tolerated: label_url stays, and labelContents() retries later.
     */
    private function storeLabel(Shipment $shipment): bool
    {
        if ($shipment->label_url === null) {
            return false;
        }

        try {
            $response = Http::timeout(30)->get($shipment->label_url);

            if (! $response->successful() || $response->body() === '') {
                throw new \RuntimeException("Label download returned HTTP {$response->status()}.");
            }

            $extension = str_contains((string) $response->header('Content-Type'), 'pdf') || str_ends_with(strtolower(parse_url($shipment->label_url, PHP_URL_PATH) ?? ''), '.pdf')
                ? 'pdf'
                : 'png';
            $path = "shipping-labels/{$shipment->organization_id}/shipment-{$shipment->id}.{$extension}";

            Storage::disk(self::LABEL_DISK)->put($path, $response->body());
            $shipment->forceFill(['label_path' => $path])->save();

            return true;
        } catch (\Throwable $e) {
            Log::warning('Could not download shipping label; it can be retried from the order page', [
                'shipment_id' => $shipment->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Email the customer their tracking details, once, when the shipment has
     * notifications on and the order has a customer email.
     */
    private function notifyCustomer(Shipment $shipment): void
    {
        if (! $shipment->notify_customer || $shipment->customer_notified_at !== null) {
            return;
        }

        $email = Order::withoutGlobalScopes()->whereKey($shipment->order_id)->value('customer_email');

        if (blank($email) || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        // Claim the notification atomically so a webhook and a poll racing on
        // the same shipment cannot both send it.
        $claimed = Shipment::withoutGlobalScopes()
            ->whereKey($shipment->getKey())
            ->whereNull('customer_notified_at')
            ->update(['customer_notified_at' => now()]);

        if ($claimed === 0) {
            return;
        }

        try {
            Mail::to($email)->queue(new ShipmentShippedEmail($shipment->fresh()));
        } catch (\Throwable $e) {
            Log::warning('Could not queue the shipment email', ['shipment_id' => $shipment->id, 'error' => $e->getMessage()]);
            Shipment::withoutGlobalScopes()->whereKey($shipment->getKey())->update(['customer_notified_at' => null]);
        }
    }

    /**
     * The ship-to address for an order, in EasyPost's address shape: the
     * linked customer's shipping address (else billing), else the order's
     * free-text address as street lines for the user to complete.
     *
     * @return array<string, string|null>
     */
    public function defaultToAddress(Order $order): array
    {
        $customer = $order->customer_id
            ? \App\Models\Customer::withoutGlobalScopes()->find($order->customer_id)
            : null;

        $base = [
            'name' => $order->customer_name,
            'company' => $customer?->company_name,
            'phone' => $customer?->phone,
            'email' => $order->customer_email ?? $customer?->email,
        ];

        if ($customer && filled($customer->shipping_address)) {
            return $base + [
                'street1' => $customer->shipping_address,
                'street2' => null,
                'city' => $customer->shipping_city,
                'state' => $customer->shipping_state,
                'zip' => $customer->shipping_zip_code,
                'country' => $customer->shipping_country,
            ];
        }

        if ($customer && filled($customer->billing_address)) {
            return $base + [
                'street1' => $customer->billing_address,
                'street2' => null,
                'city' => $customer->billing_city,
                'state' => $customer->billing_state,
                'zip' => $customer->billing_zip_code,
                'country' => $customer->billing_country,
            ];
        }

        $lines = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string) $order->customer_address))));

        return $base + [
            'street1' => $lines[0] ?? null,
            'street2' => $lines[1] ?? null,
            'city' => null,
            'state' => null,
            'zip' => null,
            'country' => null,
        ];
    }

    /**
     * The ship-from address: the organization's configured default, else the
     * ship-from warehouse's address.
     *
     * @return array<string, string|null>
     */
    public function defaultFromAddress(Order $order, ?int $warehouseId = null): array
    {
        $settings = ShippingSetting::forOrganization((int) $order->organization_id);
        $configured = $this->address($settings->from_address);

        if ($configured !== null && filled($configured['street1'] ?? null)) {
            return $configured;
        }

        $warehouse = Warehouse::withoutGlobalScopes()->find($warehouseId ?? $order->warehouse_id ?? $settings->default_warehouse_id);
        $organization = \App\Models\Auth\Organization::query()->withoutGlobalScopes()->find($order->organization_id);

        return [
            'name' => $warehouse?->manager_name ?: $organization?->name,
            'company' => $organization?->name,
            'street1' => $warehouse?->address_line_1,
            'street2' => $warehouse?->address_line_2,
            'city' => $warehouse?->city,
            'state' => $warehouse?->province,
            'zip' => $warehouse?->postal_code,
            'country' => $warehouse?->country,
            'phone' => $warehouse?->phone ?: $organization?->phone,
            'email' => $warehouse?->email ?: $organization?->email,
        ];
    }

    /**
     * Normalise a submitted address to EasyPost's keys, or null when empty.
     *
     * @return array<string, string|null>|null
     */
    private function address(mixed $address): ?array
    {
        if (! is_array($address)) {
            return null;
        }

        $keys = ['name', 'company', 'street1', 'street2', 'city', 'state', 'zip', 'country', 'phone', 'email'];
        $normalised = [];

        foreach ($keys as $key) {
            $normalised[$key] = $this->clean($address[$key] ?? null);
        }

        return array_filter($normalised) === [] ? null : $normalised;
    }

    private function clean(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : $value;

        return $value === null || $value === '' ? null : (string) $value;
    }
}
