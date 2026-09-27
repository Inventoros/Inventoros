<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ShipmentStatus;
use App\Exceptions\ShippingException;
use App\Models\Shipping\Shipment;
use App\Services\Shipping\Carriers\EasyPostCarrier;
use App\Services\Shipping\ShipmentService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Polls the carrier for shipments that are on their way (shipped or in
 * transit), as a backstop for the EasyPost webhook. Each shipment is polled at
 * most once per --stale minutes, the oldest first, and calls are capped per
 * organization per minute so one busy organization cannot exhaust its
 * EasyPost rate limit (or starve the others).
 */
class TrackShipmentsCommand extends Command
{
    protected $signature = 'shipping:track
        {--limit=200 : Maximum shipments to poll in this run}
        {--stale=60 : Only poll shipments not checked in this many minutes}
        {--per-minute=30 : Maximum carrier calls per organization per minute}';

    protected $description = 'Refresh tracking for shipments that are in transit';

    public function handle(ShipmentService $shipments): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $stale = max(1, (int) $this->option('stale'));
        $perMinute = max(1, (int) $this->option('per-minute'));

        $candidates = Shipment::withoutGlobalScopes()
            ->where('carrier', EasyPostCarrier::KEY)
            ->whereIn('status', [ShipmentStatus::SHIPPED->value, ShipmentStatus::IN_TRANSIT->value])
            ->where(function ($query) use ($stale) {
                $query->whereNull('last_tracked_at')
                    ->orWhere('last_tracked_at', '<=', now()->subMinutes($stale));
            })
            ->orderByRaw('last_tracked_at IS NOT NULL')
            ->orderBy('last_tracked_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $polled = 0;
        $deferred = 0;
        $failed = 0;

        foreach ($candidates as $shipment) {
            $key = "shipping-track:{$shipment->organization_id}";

            if (RateLimiter::tooManyAttempts($key, $perMinute)) {
                $deferred++;

                continue;
            }

            RateLimiter::hit($key, 60);

            try {
                $shipments->refreshTracking($shipment);
                $polled++;
            } catch (ShippingException $e) {
                $failed++;
                // Back off until the next stale window instead of retrying
                // a broken shipment on every run.
                $shipment->forceFill(['last_tracked_at' => now()])->save();
                Log::warning('Shipment tracking poll failed', [
                    'shipment_id' => $shipment->id,
                    'organization_id' => $shipment->organization_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("Polled {$polled} shipment(s); {$deferred} deferred by the rate limit; {$failed} failed.");

        return self::SUCCESS;
    }
}
