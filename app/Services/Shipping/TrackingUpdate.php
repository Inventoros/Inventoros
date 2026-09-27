<?php

declare(strict_types=1);

namespace App\Services\Shipping;

use App\Enums\ShipmentStatus;
use Illuminate\Support\Carbon;

/**
 * A carrier's latest tracking state for one shipment. $status is null when the
 * carrier status has no Inventoros equivalent (for example "unknown").
 */
final class TrackingUpdate
{
    public function __construct(
        public readonly ?ShipmentStatus $status,
        public readonly ?string $detail = null,
        public readonly ?string $trackingUrl = null,
        public readonly ?string $carrierTrackerId = null,
        public readonly ?Carbon $occurredAt = null,
    ) {}
}
