<?php

declare(strict_types=1);

namespace App\Models\Inventory;

use App\Models\Auth\Organization;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Represents a serial number for a product.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $product_id
 * @property string $serial_number
 * @property string $status
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Organization $organization
 * @property-read Product $product
 */
class ProductSerial extends Model
{
    use BelongsToOrganization, HasFactory;

    public const STATUS_AVAILABLE = 'available';

    public const STATUS_SOLD = 'sold';

    public const STATUS_RESERVED = 'reserved';

    public const STATUS_DAMAGED = 'damaged';

    /**
     * The unit left for another organization by an inter-company transfer.
     * The row stays for history; the unit lives on as a serial of the
     * destination organization. Set only by InterCompanyTransferService, so
     * it is not one of the VALID_STATUSES a user may choose, and a
     * transferred serial cannot be changed.
     */
    public const STATUS_TRANSFERRED = 'transferred';

    /**
     * Built into another product by a completed work order. Set only by the
     * work order service, so it is not one of the VALID_STATUSES a request
     * may pick.
     */
    public const STATUS_CONSUMED = 'consumed';

    public const VALID_STATUSES = [
        self::STATUS_AVAILABLE,
        self::STATUS_SOLD,
        self::STATUS_RESERVED,
        self::STATUS_DAMAGED,
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'organization_id',
        'product_id',
        'serial_number',
        'status',
        'notes',
    ];

    // order_item_id is deliberately NOT fillable: it links a serial to the
    // order line that consumed it and is set only by the allocation service. A
    // request must never be able to pin a serial to an arbitrary order line via
    // mass assignment.

    /**
     * Get the organization that owns this serial.
     *
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Get the product that owns this serial.
     *
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Scope a query to only include serials from a specific organization.
     *
     * @param  Builder<static>  $query
     * @param  int  $organizationId
     * @return Builder<static>
     */
    public function scopeForOrganization($query, $organizationId)
    {
        return $query->where('organization_id', $organizationId);
    }

    /**
     * Scope a query to only include serials with a specific status.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWithStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Check if the serial is available.
     */
    public function isAvailable(): bool
    {
        return $this->status === self::STATUS_AVAILABLE;
    }
}
