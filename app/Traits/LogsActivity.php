<?php

namespace App\Traits;

use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Model;

trait LogsActivity
{
    protected static function bootLogsActivity()
    {
        static::created(function (Model $model) {
            try {
                if (auth()->check() && auth()->user()->organization_id) {
                    $model->logActivity('created', 'Created ' . class_basename($model));
                }
            } catch (\Exception $e) {
                // Silently fail if logging fails
            }
        });

        static::updated(function (Model $model) {
            try {
                if (auth()->check() && auth()->user()->organization_id) {
                    $changes = $model->getChanges();
                    $original = array_intersect_key($model->getOriginal(), $changes);

                    // Remove timestamps from changes
                    unset($changes['updated_at'], $original['updated_at']);

                    if (!empty($changes)) {
                        $model->logActivity('updated', 'Updated ' . class_basename($model), [
                            'old' => $original,
                            'new' => $changes,
                        ]);
                    }
                }
            } catch (\Exception $e) {
                // Silently fail if logging fails
            }
        });

        static::deleted(function (Model $model) {
            try {
                if (auth()->check() && auth()->user()->organization_id) {
                    $model->logActivity('deleted', 'Deleted ' . class_basename($model));
                }
            } catch (\Exception $e) {
                // Silently fail if logging fails
            }
        });
    }

    /**
     * Log an activity for this model
     */
    public function logActivity(string $action, ?string $description = null, ?array $properties = null): ActivityLog
    {
        return ActivityLog::log($action, $this, $description, $properties);
    }

    /**
     * Get all activity logs for this model
     */
    public function activities()
    {
        return $this->morphMany(ActivityLog::class, 'subject')
            ->with('user')
            ->latest();
    }
}
