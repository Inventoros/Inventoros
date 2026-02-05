<?php

namespace App\Models;

use App\Models\Auth\Organization;
use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Setting extends Model
{
    use HasFactory, LogsActivity;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'organization_id',
        'key',
        'value',
        'encrypted',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected function casts(): array
    {
        return [
            'encrypted' => 'boolean',
        ];
    }

    /**
     * Get the organization that owns the setting.
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Get/set the value attribute with automatic encryption/decryption.
     *
     * @return \Illuminate\Database\Eloquent\Casts\Attribute
     */
    protected function value(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $this->encrypted ? decrypt($value) : $value,
            set: fn ($value) => $this->encrypted ? encrypt($value) : $value,
        );
    }

    /**
     * Scope a query to only include settings from a specific organization.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $organizationId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForOrganization($query, $organizationId)
    {
        return $query->where('organization_id', $organizationId);
    }
}
