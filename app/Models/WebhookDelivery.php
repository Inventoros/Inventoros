<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * WebhookDelivery model for tracking webhook delivery attempts.
 *
 * @property int $id
 * @property int $webhook_id
 * @property string $event
 * @property array $payload
 * @property int|null $response_status
 * @property string|null $response_body
 * @property int $attempts
 * @property \Illuminate\Support\Carbon|null $next_retry_at
 * @property string $status
 * @property \Illuminate\Support\Carbon|null $completed_at
 * @property \Illuminate\Support\Carbon $created_at
 */
class WebhookDelivery extends Model
{
    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'webhook_id',
        'event',
        'payload',
        'response_status',
        'response_body',
        'attempts',
        'next_retry_at',
        'status',
        'completed_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'next_retry_at' => 'datetime',
            'completed_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Boot the model.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (WebhookDelivery $delivery) {
            $delivery->created_at = $delivery->created_at ?? now();
        });
    }

    /**
     * Get the webhook that owns this delivery.
     */
    public function webhook(): BelongsTo
    {
        return $this->belongsTo(Webhook::class);
    }

    /**
     * Scope a query to only include pending deliveries.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope a query to only include failed deliveries.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    /**
     * Scope a query to only include successful deliveries.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeSuccessful($query)
    {
        return $query->where('status', 'success');
    }

    /**
     * Scope a query to only include deliveries ready for retry.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeReadyForRetry($query)
    {
        return $query->pending()
            ->where(function ($q) {
                $q->whereNull('next_retry_at')
                    ->orWhere('next_retry_at', '<=', now());
            });
    }

    /**
     * Check if the delivery was successful.
     *
     * @return bool
     */
    public function isSuccessful(): bool
    {
        return $this->status === 'success';
    }

    /**
     * Check if the delivery failed.
     *
     * @return bool
     */
    public function hasFailed(): bool
    {
        return $this->status === 'failed';
    }

    /**
     * Check if the delivery is pending.
     *
     * @return bool
     */
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }
}
