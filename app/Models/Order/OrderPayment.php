<?php

declare(strict_types=1);

namespace App\Models\Order;

use App\Enums\PaymentMethod;
use App\Enums\PaymentType;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A payment received against a sales order, or a refund given back.
 *
 * Rows are append-only: recorded through OrderPaymentService, corrected only
 * by voiding (never edited or deleted), so the payment history is an audit
 * trail. Amounts are always positive; `type` says which way the money moved.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $order_id
 * @property int|null $user_id
 * @property PaymentType $type
 * @property string $amount
 * @property PaymentMethod $method
 * @property string|null $reference
 * @property Carbon $paid_at
 * @property string|null $notes
 * @property Carbon|null $voided_at
 * @property int|null $voided_by
 * @property string|null $void_reason
 * @property-read Order $order
 * @property-read User|null $user
 * @property-read User|null $voider
 */
class OrderPayment extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'order_id',
        'user_id',
        'type',
        'amount',
        'method',
        'reference',
        'paid_at',
        'notes',
        'voided_at',
        'voided_by',
        'void_reason',
    ];

    protected function casts(): array
    {
        return [
            'type' => PaymentType::class,
            'amount' => 'decimal:2',
            'method' => PaymentMethod::class,
            'paid_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function voider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }

    public function isRefund(): bool
    {
        return $this->type === PaymentType::REFUND;
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive($query)
    {
        return $query->whereNull('voided_at');
    }
}
