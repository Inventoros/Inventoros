<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Auth\Organization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Represents an activity log entry for tracking changes in the system.
 *
 * @property int $id
 * @property int $organization_id
 * @property int|null $user_id
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property string $action
 * @property string|null $description
 * @property array|null $properties
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read array $changes
 * @property-read \App\Models\Auth\Organization $organization
 * @property-read \App\Models\User|null $user
 * @property-read \Illuminate\Database\Eloquent\Model|null $subject
 */
class ActivityLog extends Model
{
    protected $fillable = [
        'organization_id',
        'user_id',
        'subject_type',
        'subject_id',
        'action',
        'description',
        'properties',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'properties' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the organization that owns the activity log.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\Auth\Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Get the user who performed the action.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the subject model (product, order, etc.).
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphTo<\Illuminate\Database\Eloquent\Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
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
     * Scope to filter by subject type.
     *
     * @param \Illuminate\Database\Eloquent\Builder<static> $query
     * @param string $subjectType
     * @param int|null $subjectId
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function scopeForSubject($query, $subjectType, $subjectId = null)
    {
        $query->where('subject_type', $subjectType);

        if ($subjectId) {
            $query->where('subject_id', $subjectId);
        }

        return $query;
    }

    /**
     * Scope to filter by action.
     *
     * @param \Illuminate\Database\Eloquent\Builder<static> $query
     * @param string $action
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function scopeForAction($query, $action)
    {
        return $query->where('action', $action);
    }

    /**
     * Get formatted changes for display.
     *
     * @return array<string, array{old: mixed, new: mixed}>
     */
    public function getChangesAttribute(): array
    {
        $properties = $this->properties ?? [];

        if (isset($properties['old'], $properties['new'])) {
            $changes = [];
            foreach ($properties['new'] as $key => $newValue) {
                $oldValue = $properties['old'][$key] ?? null;
                if ($oldValue != $newValue) {
                    $changes[$key] = [
                        'old' => $oldValue,
                        'new' => $newValue,
                    ];
                }
            }
            return $changes;
        }

        return $properties;
    }

    /**
     * Log an activity.
     *
     * @param string $action
     * @param \Illuminate\Database\Eloquent\Model $subject
     * @param string|null $description
     * @param array|null $properties
     * @return static|null
     */
    public static function log(
        string $action,
        Model $subject,
        ?string $description = null,
        ?array $properties = null
    ): ?self {
        // Guard against missing auth context
        if (!auth()->check()) {
            return null;
        }

        $user = auth()->user();
        if (!$user || !$user->organization_id) {
            return null;
        }

        return self::create([
            'organization_id' => $user->organization_id,
            'user_id' => $user->id,
            'subject_type' => get_class($subject),
            'subject_id' => $subject->id ?? $subject->getKey(),
            'action' => $action,
            'description' => $description,
            'properties' => $properties,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);
    }
}
