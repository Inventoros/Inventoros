<?php

declare(strict_types=1);

namespace App\Models\Inventory;

use App\Models\Auth\Organization;
use App\Models\User;
use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Represents a stock audit / cycle count record.
 *
 * @property int $id
 * @property int $organization_id
 * @property string $audit_number
 * @property string $name
 * @property string|null $description
 * @property string $status
 * @property string $audit_type
 * @property int|null $warehouse_location_id
 * @property \Illuminate\Support\Carbon|null $started_at
 * @property \Illuminate\Support\Carbon|null $completed_at
 * @property string|null $notes
 * @property int $created_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read \App\Models\Auth\Organization $organization
 * @property-read \App\Models\Inventory\ProductLocation|null $warehouseLocation
 * @property-read \App\Models\User $creator
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\Inventory\StockAuditItem[] $items
 */
class StockAudit extends Model
{
    use SoftDeletes, LogsActivity;

    protected $fillable = [
        'organization_id',
        'audit_number',
        'name',
        'description',
        'status',
        'audit_type',
        'warehouse_location_id',
        'started_at',
        'completed_at',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * Get the organization that owns this audit.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\Auth\Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Get the warehouse location for this audit.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\Inventory\ProductLocation, $this>
     */
    public function warehouseLocation(): BelongsTo
    {
        return $this->belongsTo(ProductLocation::class, 'warehouse_location_id');
    }

    /**
     * Get the user who created this audit.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the audit items.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<\App\Models\Inventory\StockAuditItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(StockAuditItem::class);
    }

    /**
     * Scope to filter by organization.
     *
     * @param \Illuminate\Database\Eloquent\Builder<static> $query
     * @param int $organizationId
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function scopeForOrganization($query, $organizationId)
    {
        return $query->where('organization_id', $organizationId);
    }

    /**
     * Scope to filter by status.
     *
     * @param \Illuminate\Database\Eloquent\Builder<static> $query
     * @param string $status
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope to filter by audit type.
     *
     * @param \Illuminate\Database\Eloquent\Builder<static> $query
     * @param string $type
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function scopeByType($query, $type)
    {
        return $query->where('audit_type', $type);
    }

    /**
     * Generate a unique audit number scoped by organization.
     *
     * @param int $organizationId
     * @return string
     */
    public static function generateAuditNumber(int $organizationId): string
    {
        $prefix = 'SA-';

        $lastAudit = static::where('organization_id', $organizationId)
            ->orderBy('id', 'desc')
            ->first();

        if ($lastAudit) {
            $lastNumber = (int) substr($lastAudit->audit_number, strlen($prefix));
            $newNumber = str_pad((string) ($lastNumber + 1), 4, '0', STR_PAD_LEFT);
        } else {
            $newNumber = '0001';
        }

        return $prefix . $newNumber;
    }
}
