<?php

declare(strict_types=1);

namespace App\Services\Shipping;

/**
 * Public tracking page URLs for the common carriers, used when a manual
 * shipment gets a tracking number but no link.
 */
final class TrackingUrls
{
    private const TEMPLATES = [
        'usps' => 'https://tools.usps.com/go/TrackConfirmAction?tLabels=%s',
        'ups' => 'https://www.ups.com/track?tracknum=%s',
        'fedex' => 'https://www.fedex.com/fedextrack/?trknbr=%s',
        'dhl' => 'https://www.dhl.com/en/express/tracking.html?AWB=%s',
        'canada post' => 'https://www.canadapost-postescanada.ca/track-reperage/en#/search?searchFor=%s',
        'canadapost' => 'https://www.canadapost-postescanada.ca/track-reperage/en#/search?searchFor=%s',
        'purolator' => 'https://www.purolator.com/en/shipping/tracker?pin=%s',
    ];

    public static function guess(?string $carrierName, ?string $trackingNumber): ?string
    {
        if (blank($carrierName) || blank($trackingNumber)) {
            return null;
        }

        $name = strtolower(trim((string) $carrierName));

        foreach (self::TEMPLATES as $carrier => $template) {
            if ($name === $carrier || str_starts_with($name, $carrier.' ')) {
                return sprintf($template, rawurlencode(trim((string) $trackingNumber)));
            }
        }

        return null;
    }
}
