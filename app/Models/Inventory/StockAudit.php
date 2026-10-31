<?php

declare(strict_types=1);

namespace App\Models\Inventory;

use App\Models\Auth\Organization;
use App\Models\User;
use App\Models\Warehouse;
use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

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
 * @property int|null $warehouse_id
 * @property int|null $warehouse_location_id
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property string|null $notes
 * @property int $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Organization $organization
 * @property-read ProductLocation|null $warehouseLocation
 * @property-read User $creator
 * @property-read Collection|StockAuditItem[] $items
 */
class StockAudit extends Model
{
    use LogsActivity, SoftDeletes;

    protected $fillable = [
        'organization_id',
        'audit_number',
        'name',
        'description',
        'status',
        'audit_type',
        'cycle_count_schedule_id',
        'warehouse_id',
        'warehouse_location_id',
        'started_at',
        'completed_at',
        'notes',
        'created_by',
        'assigned_to',
        'require_variance_approval',
    ];

    protected function casts(): array
    {
        return [
            'warehouse_id' => 'integer',
            'warehouse_location_id' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'require_variance_approval' => 'boolean',
        ];
    }

    /**
     * Get the organization that owns this audit.
     *
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * Get the warehouse location for this audit.
     *
     * @return BelongsTo<ProductLocation, $this>
     */
    public function warehouseLocation(): BelongsTo
    {
        return $this->belongsTo(ProductLocation::class, 'warehouse_location_id');
    }

    /**
     * Get the user who created this audit.
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The user a scheduled cycle count was assigned to.
     *
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * The cycle count schedule that generated this audit, if any.
     *
     * @return BelongsTo<CycleCountSchedule, $this>
     */
    public function cycleCountSchedule(): BelongsTo
    {
        return $this->belongsTo(CycleCountSchedule::class);
    }

    /**
     * Get the audit items.
     *
     * @return HasMany<StockAuditItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(StockAuditItem::class);
    }

    /**
     * Scope to filter by organization.
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
     * Scope to filter by status.
     *
     * @param  Builder<static>  $query
     * @param  string  $status
     * @return Builder<static>
     */
    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope to filter by audit type.
     *
     * @param  Builder<static>  $query
     * @param  string  $type
     * @return Builder<static>
     */
    public function scopeByType($query, $type)
    {
        return $query->where('audit_type', $type);
    }

    /**
     * Generate a unique audit number scoped by organization.
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

        return $prefix.$newNumber;
    }
}
