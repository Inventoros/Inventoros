<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Auth\Organization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Represents a saved custom report configuration.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $created_by
 * @property string $name
 * @property string|null $description
 * @property string $data_source
 * @property array $columns
 * @property array|null $filters
 * @property array|null $sort
 * @property string|null $chart_type
 * @property string|null $chart_field
 * @property bool $is_shared
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Auth\Organization $organization
 * @property-read \App\Models\User $creator
 */
class SavedReport extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'organization_id',
        'created_by',
        'name',
        'description',
        'data_source',
        'columns',
        'filters',
        'sort',
        'chart_type',
        'chart_field',
        'is_shared',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'columns' => 'array',
            'filters' => 'array',
            'sort' => 'array',
            'is_shared' => 'boolean',
        ];
    }

    /**
     * Get the organization that owns the report.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\Auth\Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Get the user who created the report.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Scope to filter by organization.
     *
     * @param \Illuminate\Database\Eloquent\Builder<static> $query
     * @param int $organizationId
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function scopeForOrganization($query, int $organizationId)
    {
        return $query->where('organization_id', $organizationId);
    }

    /**
     * Scope to show reports accessible by a given user (own + shared).
     *
     * @param \Illuminate\Database\Eloquent\Builder<static> $query
     * @param \App\Models\User $user
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function scopeAccessibleBy($query, User $user)
    {
        return $query->where('organization_id', $user->organization_id)
            ->where(function ($q) use ($user) {
                $q->where('created_by', $user->id)
                    ->orWhere('is_shared', true);
            });
    }
}
