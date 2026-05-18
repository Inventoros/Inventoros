<?php

declare(strict_types=1);

namespace App\Traits;

use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * Trait for automatic activity logging on model events.
 *
 * Automatically logs create, update, and delete events to the activity log.
 * Models using this trait must belong to an organization.
 */
trait LogsActivity
{
    /**
     * Boot the trait and register model event listeners.
     *
     * @return void
     */
    protected static function bootLogsActivity()
    {
        static::created(function (Model $model) {
            try {
                if (auth()->check() && auth()->user()->organization_id) {
                    $model->logActivity('created', 'Created ' . class_basename($model));
                }
            } catch (\Exception $e) {
                Log::warning('Activity logging failed for created event', [
                    'model' => get_class($model),
                    'error' => $e->getMessage(),
                ]);
            }
        });

        static::updated(function (Model $model) {
            try {
                if (auth()->check() && auth()->user()->organization_id) {
                    $changes = $model->getChanges();
                    $original = array_intersect_key($model->getOriginal(), $changes);

                    // Remove timestamps from changes
                    unset($changes['updated_at'], $original['updated_at']);

                    // Strip sensitive fields (password hashes, encrypted 2FA
                    // material, remember tokens) so they never land in the
                    // admin-readable activity log. Anything explicitly opted-
                    // in via $dontLogAttributes on the model is also stripped.
                    foreach ($model->getDontLogAttributes() as $sensitive) {
                        unset($changes[$sensitive], $original[$sensitive]);
                    }

                    if (!empty($changes)) {
                        $model->logActivity('updated', 'Updated ' . class_basename($model), [
                            'old' => $original,
                            'new' => $changes,
                        ]);
                    }
                }
            } catch (\Exception $e) {
                Log::warning('Activity logging failed for updated event', [
                    'model' => get_class($model),
                    'error' => $e->getMessage(),
                ]);
            }
        });

        static::deleted(function (Model $model) {
            try {
                if (auth()->check() && auth()->user()->organization_id) {
                    $model->logActivity('deleted', 'Deleted ' . class_basename($model));
                }
            } catch (\Exception $e) {
                Log::warning('Activity logging failed for deleted event', [
                    'model' => get_class($model),
                    'error' => $e->getMessage(),
                ]);
            }
        });
    }

    /**
     * Log an activity for this model.
     *
     * @param string $action The action being performed (created, updated, deleted)
     * @param string|null $description Human-readable description of the activity
     * @param array|null $properties Additional data to store with the log entry
     * @return ActivityLog
     */
    public function logActivity(string $action, ?string $description = null, ?array $properties = null): ActivityLog
    {
        return ActivityLog::log($action, $this, $description, $properties);
    }

    /**
     * Get all activity logs for this model.
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphMany
     */
    public function activities()
    {
        return $this->morphMany(ActivityLog::class, 'subject')
            ->with('user')
            ->latest();
    }

    /**
     * Attributes that must never appear in activity-log properties.
     *
     * Combines an always-redacted default (anything that looks like a
     * secret on a User) with a per-model $dontLogAttributes property if
     * the model declares one.
     *
     * @return array<int, string>
     */
    public function getDontLogAttributes(): array
    {
        $defaults = [
            'password',
            'remember_token',
            'two_factor_secret',
            'two_factor_recovery_codes',
            'api_token',
        ];

        $override = property_exists($this, 'dontLogAttributes')
            ? (array) $this->dontLogAttributes
            : [];

        return array_values(array_unique(array_merge($defaults, $override)));
    }
}
