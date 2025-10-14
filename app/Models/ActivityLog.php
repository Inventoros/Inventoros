<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

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
     * Get the organization that owns the activity log
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Get the user who performed the action
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the subject model (product, order, etc.)
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Scope to filter by organization
     */
    public function scopeForOrganization($query, $organizationId)
    {
        return $query->where('organization_id', $organizationId);
    }

    /**
     * Scope to filter by subject type
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
     * Scope to filter by action
     */
    public function scopeForAction($query, $action)
    {
        return $query->where('action', $action);
    }

    /**
     * Get formatted changes for display
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
     * Log an activity
     */
    public static function log(
        string $action,
        Model $subject,
        ?string $description = null,
        ?array $properties = null
    ): self {
        return self::create([
            'organization_id' => auth()->user()->organization_id,
            'user_id' => auth()->id(),
            'subject_type' => get_class($subject),
            'subject_id' => $subject->id,
            'action' => $action,
            'description' => $description,
            'properties' => $properties,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);
    }
}
