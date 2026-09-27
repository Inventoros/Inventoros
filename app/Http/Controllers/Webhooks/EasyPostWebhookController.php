<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Enums\ShipmentStatus;
use App\Http\Controllers\Controller;
use App\Models\Shipping\Shipment;
use App\Models\Shipping\ShippingSetting;
use App\Services\Shipping\Carriers\EasyPostCarrier;
use App\Services\Shipping\ShipmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Receives EasyPost webhook events for one organization. The URL token picks
 * the organization; the X-Hmac-Signature header must match an HMAC-SHA256 of
 * the raw body under that organization's webhook secret, or nothing happens.
 *
 * Only tracker.* events are acted on. Events for trackers that match no
 * shipment are acknowledged with 200 so EasyPost does not keep retrying them.
 */
class EasyPostWebhookController extends Controller
{
    public function __invoke(Request $request, string $token, ShipmentService $shipments): JsonResponse
    {
        $settings = ShippingSetting::withoutGlobalScopes()->where('webhook_token', $token)->first();

        if ($settings === null) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $body = $request->getContent();

        if (! EasyPostCarrier::verifyWebhookSignature($body, $request->header('X-Hmac-Signature'), $settings->easypost_webhook_secret)) {
            Log::warning('Rejected EasyPost webhook with an invalid signature', [
                'organization_id' => $settings->organization_id,
                'ip' => $request->ip(),
            ]);

            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        $event = json_decode($body, true);

        if (! is_array($event)) {
            return response()->json(['message' => 'Invalid payload.'], 400);
        }

        $tracker = $event['result'] ?? null;

        if (! str_starts_with((string) ($event['description'] ?? ''), 'tracker.')
            || ! is_array($tracker)
            || ($tracker['object'] ?? null) !== 'Tracker') {
            return response()->json(['received' => true, 'updated' => 0]);
        }

        $trackerId = $tracker['id'] ?? null;
        $trackingCode = $tracker['tracking_code'] ?? null;

        if (blank($trackerId) && blank($trackingCode)) {
            return response()->json(['received' => true, 'updated' => 0]);
        }

        $matches = Shipment::withoutGlobalScopes()
            ->where('organization_id', $settings->organization_id)
            ->where('status', '!=', ShipmentStatus::CANCELLED->value)
            ->where(function ($query) use ($trackerId, $trackingCode) {
                if (filled($trackerId)) {
                    $query->orWhere('carrier_tracker_id', $trackerId);
                }
                if (filled($trackingCode)) {
                    $query->orWhere('tracking_number', $trackingCode);
                }
            })
            ->get();

        $update = EasyPostCarrier::trackingUpdateFromTracker($tracker);

        foreach ($matches as $shipment) {
            $shipments->applyTrackingUpdate($shipment, $update);
        }

        return response()->json(['received' => true, 'updated' => $matches->count()]);
    }
}
