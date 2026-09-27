<?php

declare(strict_types=1);

namespace App\Models\Inventory;

use App\Models\Auth\Organization;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\User;
use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A recurring cycle count.
 *
 * When due, the scheduler (inventory:run-cycle-counts) turns it into a draft
 * "cycle" stock audit covering the products in scope that were counted least
 * recently, assigned to `assigned_to`.
 *
 * @property int $id
 * @property int $organization_id
 * @property string $name
 * @property string $frequency daily, weekly or monthly
 * @property string $scope_type all, location, warehouse or category
 * @property int|null $scope_id
 * @property int $products_per_run
 * @property int|null $assigned_to
 * @property bool $is_active
 * @property Carbon|null $next_run_at
 * @property Carbon|null $last_run_at
 * @property int|null $last_audit_id
 * @property int|null $created_by
 * @property-read User|null $assignee
 * @property-read StockAudit|null $lastAudit
 */
class CycleCountSchedule extends Model
{
    use BelongsToOrganization, LogsActivity;

    public const FREQUENCIES = ['daily', 'weekly', 'monthly'];

    /**
     * ABC classification is not tracked, so scope is by place or category.
     */
    public const SCOPES = ['all', 'location', 'warehouse', 'category'];

    protected $fillable = [
        'organization_id',
        'name',
        'frequency',
        'scope_type',
        'scope_id',
        'products_per_run',
        'assigned_to',
        'is_active',
        'next_run_at',
        'last_run_at',
        'last_audit_id',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'scope_id' => 'integer',
            'products_per_run' => 'integer',
            'is_active' => 'boolean',
            'next_run_at' => 'datetime',
            'last_run_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<StockAudit, $this>
     */
    public function lastAudit(): BelongsTo
    {
        return $this->belongsTo(StockAudit::class, 'last_audit_id');
    }

    /**
     * @return HasMany<StockAudit, $this>
     */
    public function audits(): HasMany
    {
        return $this->hasMany(StockAudit::class);
    }

    public function isDue(?Carbon $now = null): bool
    {
        $now ??= Carbon::now();

        return $this->is_active
            && $this->next_run_at !== null
            && $this->next_run_at->lessThanOrEqualTo($now);
    }

    /**
     * The first run time after $now on this schedule's cadence, anchored on
     * the current next_run_at so the time of day is kept. Runs missed while
     * the scheduler was down are skipped, not replayed one by one.
     */
    public function nextRunAfter(Carbon $now): Carbon
    {
        // Step from the anchor rather than from the previous step, so a
        // monthly schedule on the 31st lands on the 30th in a short month
        // and goes back to the 31st after it.
        $anchor = ($this->next_run_at ?? $now)->copy();
        $step = 1;

        do {
            $next = match ($this->frequency) {
                'weekly' => $anchor->copy()->addWeeks($step),
                'monthly' => $anchor->copy()->addMonthsNoOverflow($step),
                default => $anchor->copy()->addDays($step),
            };
            $step++;
        } while ($next->lessThanOrEqualTo($now));

        return $next;
    }
}
