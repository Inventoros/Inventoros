<?php

declare(strict_types=1);

namespace App\Models\Inventory;

use App\Models\Auth\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Stock moved from one organization to another as a single operation (see
 * App\Services\Organizations\InterCompanyTransferService). It belongs to
 * both organizations, so it has no OrganizationScope: read it with
 * involving($organizationId), never unfiltered.
 *
 * @property int $id
 * @property string $reference
 * @property int $from_organization_id
 * @property int $to_organization_id
 * @property int|null $initiated_by
 * @property string|null $idempotency_key
 * @property string|null $notes
 * @property \Illuminate\Support\Carbon|null $completed_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read Organization $fromOrganization
 * @property-read Organization $toOrganization
 * @property-read User|null $initiator
 * @property-read \Illuminate\Database\Eloquent\Collection<int, InterCompanyTransferLine> $lines
 */
class InterCompanyTransfer extends Model
{
    protected $fillable = [
        'reference',
        'from_organization_id',
        'to_organization_id',
        'initiated_by',
        'idempotency_key',
        'notes',
        'completed_at',
    ];

    protected $casts = [
        'completed_at' => 'datetime',
    ];

    /**
     * Transfers in or out of the organization.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeInvolving(Builder $query, int $organizationId): Builder
    {
        return $query->where(fn (Builder $q) => $q->where('from_organization_id', $organizationId)->orWhere('to_organization_id', $organizationId));
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function fromOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'from_organization_id');
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function toOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'to_organization_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function initiator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }

    /**
     * @return HasMany<InterCompanyTransferLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(InterCompanyTransferLine::class);
    }
}
