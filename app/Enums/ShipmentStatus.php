<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The lifecycle of a Shipment.
 *
 *   pending        created, lines allocated, nothing bought or sent yet
 *   label_created  a carrier label has been bought (it can still be voided)
 *   shipped        handed to the carrier
 *   in_transit     the carrier has scanned it on its way
 *   delivered      the carrier reports delivery
 *   exception      the carrier reports a problem (returned, failed, lost)
 *   cancelled      voided or abandoned before it left; frees its quantities
 */
enum ShipmentStatus: string
{
    case PENDING = 'pending';
    case LABEL_CREATED = 'label_created';
    case SHIPPED = 'shipped';
    case IN_TRANSIT = 'in_transit';
    case DELIVERED = 'delivered';
    case EXCEPTION = 'exception';
    case CANCELLED = 'cancelled';

    /**
     * Whether the goods in this shipment have physically left the warehouse.
     */
    public function hasLeft(): bool
    {
        return in_array($this, [self::SHIPPED, self::IN_TRANSIT, self::DELIVERED, self::EXCEPTION], true);
    }

    /**
     * Whether the shipment can still be cancelled (or its label voided).
     */
    public function isCancellable(): bool
    {
        return in_array($this, [self::PENDING, self::LABEL_CREATED], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Pending',
            self::LABEL_CREATED => 'Label created',
            self::SHIPPED => 'Shipped',
            self::IN_TRANSIT => 'In transit',
            self::DELIVERED => 'Delivered',
            self::EXCEPTION => 'Exception',
            self::CANCELLED => 'Cancelled',
        };
    }

    /**
     * Statuses whose goods have left the warehouse, as plain values.
     *
     * @return array<int, string>
     */
    public static function leftValues(): array
    {
        return [self::SHIPPED->value, self::IN_TRANSIT->value, self::DELIVERED->value, self::EXCEPTION->value];
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
