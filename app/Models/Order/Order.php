<?php

declare(strict_types=1);

namespace App\Models\Order;

use App\Enums\DiscountType;
use App\Enums\OrderApprovalStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\InvalidStateException;
use App\Models\Auth\Organization;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Customer;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\Money;
use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Represents an order in the system.
 *
 * @property int $id
 * @property int $organization_id
 * @property int|null $created_by
 * @property string $order_number
 * @property string|null $invoice_number
 * @property \Illuminate\Support\Carbon|null $invoice_issued_at
 * @property \Illuminate\Support\Carbon|null $invoice_sent_at
 * @property \Illuminate\Support\Carbon|null $invoice_queued_at
 * @property string|null $invoice_sent_to
 * @property string|null $source
 * @property string|null $external_id
 * @property string|null $external_reference
 * @property string|null $customer_name
 * @property string|null $customer_email
 * @property string|null $customer_address
 * @property string $status
 * @property string $approval_status
 * @property int|null $approved_by
 * @property Carbon|null $approved_at
 * @property string|null $approval_notes
 * @property bool $stock_committed Whether creating the order took its lines out of stock (false for historical imports)
 * @property string $subtotal
 * @property DiscountType|null $discount_type
 * @property string|null $discount_value
 * @property string $discount_amount Total discount: line discounts + order-level discount
 * @property string $tax
 * @property string $shipping
 * @property string $total
 * @property string $amount_paid Net of non-voided payments minus refunds
 * @property PaymentStatus $payment_status
 * @property string|null $currency
 * @property Carbon|null $order_date
 * @property Carbon|null $shipped_at
 * @property Carbon|null $delivered_at
 * @property string|null $notes
 * @property array|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Organization $organization
 * @property-read User|null $creator
 * @property-read User|null $approver
 * @property-read Collection|OrderItem[] $items
 */
class Order extends Model
{
    use BelongsToOrganization, HasFactory, LogsActivity, SoftDeletes;

    public const APPROVAL_STATUS_PENDING = 'pending';

    public const APPROVAL_STATUS_APPROVED = 'approved';

    public const APPROVAL_STATUS_REJECTED = 'rejected';

    private const ORDER_NUMBER_PREFIX = 'ORD-';

    private const ORDER_NUMBER_PAD_LENGTH = 4;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'organization_id',
        'warehouse_id',
        'customer_id',
        'created_by',
        'order_number',
        'invoice_number',
        'invoice_issued_at',
        'invoice_sent_at',
        'invoice_sent_to',
        'invoice_queued_at',
        'source',
        'external_id',
        'external_reference',
        'customer_name',
        'customer_email',
        'customer_address',
        'status',
        'approval_status',
        'approved_by',
        'approved_at',
        'approval_notes',
        'subtotal',
        'discount_type',
        'discount_value',
        'discount_amount',
        'tax',
        'shipping',
        'total',
        'amount_paid',
        'payment_status',
        'currency',
        'order_date',
        'shipped_at',
        'delivered_at',
        'notes',
        'metadata',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'approval_status' => OrderApprovalStatus::class,
            'stock_committed' => 'boolean',
            'subtotal' => 'decimal:2',
            'discount_type' => DiscountType::class,
            'discount_value' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'tax' => 'decimal:2',
            'shipping' => 'decimal:2',
            'total' => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'payment_status' => PaymentStatus::class,
            'order_date' => 'datetime',
            'shipped_at' => 'datetime',
            'delivered_at' => 'datetime',
            'approved_at' => 'datetime',
            'invoice_issued_at' => 'datetime',
            'invoice_sent_at' => 'datetime',
            'invoice_queued_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /**
     * Get the organization that owns the order.
     *
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * Get the user who created the order.
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the user who approved/rejected the order.
     *
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Get the items for the order.
     *
     * @return HasMany<OrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * Payments and refunds recorded against the order, voided ones included.
     *
     * @return HasMany<OrderPayment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(OrderPayment::class);
    }

    /**
     * Recompute amount_paid and payment_status from the non-voided payment
     * rows (without saving). Summed in PHP with Money rather than SQL SUM,
     * which returns a float on some drivers and would drift.
     *
     * Callers that write must hold the order's row lock, so a concurrent
     * payment cannot slip in between the sum and the save.
     */
    public function syncPaymentState(): void
    {
        // An order from before payment tracking stays untracked until the
        // first payment row (voided or not) is recorded against it.
        if ($this->payment_status === PaymentStatus::UNTRACKED
            && ! $this->payments()->withoutGlobalScopes()->exists()) {
            $this->amount_paid = '0.00';

            return;
        }

        $paid = '0.00';
        $refunded = '0.00';

        foreach ($this->payments()->withoutGlobalScopes()->active()->get(['type', 'amount']) as $payment) {
            if ($payment->isRefund()) {
                $refunded = Money::add($refunded, $payment->amount);
            } else {
                $paid = Money::add($paid, $payment->amount);
            }
        }

        $this->amount_paid = Money::subtract($paid, $refunded);
        $this->payment_status = PaymentStatus::derive(Money::of($this->total), $this->amount_paid, $refunded);
    }

    /**
     * What the customer still owes: total minus what has been paid, never
     * below zero (an overpaid order owes nothing; its status says overpaid).
     * An untracked order (from before payment tracking) owes nothing either:
     * nothing is known about its payment, so it is never reported as owed.
     */
    public function balanceDue(): string
    {
        if (! $this->isPaymentTracked()) {
            return '0.00';
        }

        return $this->unpaidAmount();
    }

    /**
     * Total minus what has been paid, never below zero, whether or not the
     * order's payments are tracked. What a first payment is checked against.
     */
    public function unpaidAmount(): string
    {
        return Money::max(Money::subtract($this->total, $this->amount_paid ?? '0'), 0);
    }

    /**
     * False for an order that predates payment tracking and has had no
     * payment recorded since.
     */
    public function isPaymentTracked(): bool
    {
        return $this->payment_status !== PaymentStatus::UNTRACKED;
    }

    /**
     * The sum of the line discounts. The order-level discount is the rest of
     * discount_amount.
     */
    public function lineDiscountTotal(): string
    {
        return Money::add(...$this->items->pluck('discount_amount')->all());
    }

    /**
     * The order-level discount alone (discount_amount minus the line
     * discounts).
     */
    public function orderDiscountAmount(): string
    {
        return Money::subtract($this->discount_amount, $this->lineDiscountTotal());
    }

    /**
     * Get the return orders for this order.
     *
     * @return HasMany<ReturnOrder, $this>
     */
    public function returnOrders(): HasMany
    {
        return $this->hasMany(ReturnOrder::class);
    }

    /**
     * Get the shipments sent for this order.
     *
     * @return HasMany<\App\Models\Shipping\Shipment, $this>
     */
    public function shipments(): HasMany
    {
        return $this->hasMany(\App\Models\Shipping\Shipment::class);
    }

    /**
     * Scope a query to only include orders from a specific organization.
     *
     * @param  Builder<static>  $query
     * @param  int  $organizationId
     * @return Builder<static>
     */
    public function scopeForOrganization($query, $organizationId)
    {
        return $query->where('organization_id', $organizationId);
    }

    /**
     * Scope a query to filter by status.
     *
     * @param  Builder<static>  $query
     * @param  string  $status
     * @return Builder<static>
     */
    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope a query to filter by source.
     *
     * @param  Builder<static>  $query
     * @param  string  $source
     * @return Builder<static>
     */
    public function scopeBySource($query, $source)
    {
        return $query->where('source', $source);
    }

    /**
     * Backstop for every surface (web, REST, GraphQL, MCP, imports, plugins):
     * an order waiting for approval never saves as shipped or delivered.
     * Surfaces check approvalBlocks() first to return their own error shape.
     */
    protected static function booted(): void
    {
        static::saving(function (Order $order) {
            if ($order->isDirty('status') && $order->approvalBlocks($order->status)) {
                throw new InvalidStateException(self::APPROVAL_PENDING_MESSAGE, 'approval_pending');
            }
        });
    }

    /**
     * Scope a query to filter by approval status.
     *
     * @param  Builder<static>  $query
     * @param  string  $status
     * @return Builder<static>
     */
    public function scopeByApprovalStatus($query, $status)
    {
        return $query->where('approval_status', $status);
    }

    /**
     * Scope a query to only include orders pending approval.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeNeedsApproval($query)
    {
        return $query->where('approval_status', 'pending')
            ->where('status', '!=', OrderStatus::CANCELLED->value);
    }

    /**
     * Whether the latest invoice email is still waiting in the queue: queued
     * and not delivered since (invoice_sent_at is stamped on delivery).
     */
    public function invoiceEmailIsQueued(): bool
    {
        return $this->invoice_queued_at !== null
            && ($this->invoice_sent_at === null || $this->invoice_sent_at->lessThan($this->invoice_queued_at));
    }

    /**
     * Check if the order is waiting for an approval decision. A cancelled
     * order is not: cancelling already released its stock, so approving or
     * rejecting it afterwards has nothing left to decide (and a reject would
     * restock it a second time).
     */
    public function isPendingApproval(): bool
    {
        return $this->approval_status === OrderApprovalStatus::PENDING
            && $this->status !== OrderStatus::CANCELLED;
    }

    /**
     * Statuses an order cannot reach while it waits for approval: the goods
     * would leave before anyone approved the sale. Processing is allowed.
     */
    public const STATUSES_BLOCKED_BY_APPROVAL = [OrderStatus::SHIPPED, OrderStatus::DELIVERED];

    public const APPROVAL_PENDING_MESSAGE = 'This order is waiting for approval. Approve it before shipping or delivering it.';

    /**
     * Whether moving this order to $status is blocked because it is still
     * waiting for an approval decision.
     */
    public function approvalBlocks(OrderStatus|string|null $status): bool
    {
        if ($status === null || ! $this->isPendingApproval()) {
            return false;
        }

        $status = $status instanceof OrderStatus ? $status : OrderStatus::tryFrom($status);

        return in_array($status, self::STATUSES_BLOCKED_BY_APPROVAL, true);
    }

    /**
     * Check if the order is approved.
     */
    public function isApproved(): bool
    {
        return $this->approval_status === OrderApprovalStatus::APPROVED;
    }

    /**
     * Check if the order is rejected.
     */
    public function isRejected(): bool
    {
        return $this->approval_status === OrderApprovalStatus::REJECTED;
    }

    /**
     * Generate a unique order number scoped by organization.
     */
    public static function generateOrderNumber(?int $organizationId = null): string
    {
        $prefix = 'ORD-';
        $date = now()->format('Ymd');

        // Get the last order number for today, scoped by organization. Include
        // soft-deleted rows: the unique index still counts them, so ignoring a
        // trashed highest-of-the-day number would regenerate a colliding value
        // that no retry can escape.
        $query = static::withTrashed()->where('order_number', 'like', $prefix.$date.'%');
        if ($organizationId !== null) {
            $query->where('organization_id', $organizationId);
        }
        $lastOrder = $query->orderBy('order_number', 'desc')
            ->first();

        if ($lastOrder) {
            $lastNumber = (int) substr($lastOrder->order_number, -4);
            $newNumber = str_pad((string) ($lastNumber + 1), 4, '0', STR_PAD_LEFT);
        } else {
            $newNumber = '0001';
        }

        return $prefix.$date.'-'.$newNumber;
    }
}
