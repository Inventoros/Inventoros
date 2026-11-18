<?php

declare(strict_types=1);

namespace App\Models\Purchasing;

use App\Exceptions\InvalidStateException;
use App\Models\Auth\Organization;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Inventory\StockAdjustment;
use App\Models\Inventory\Supplier;
use App\Models\Scopes\OrganizationScope;
use App\Models\User;
use App\Services\ExchangeRates\ExchangeRateProviderRegistry;
use App\Support\ApprovalSettings;
use App\Support\ExchangeMath;
use App\Support\LineTax;
use App\Support\Money;
use App\Support\Search;
use App\Support\SequenceNumber;
use App\Support\SequenceNumberRetry;
use App\Traits\LogsActivity;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Represents a purchase order in the system.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $supplier_id
 * @property int|null $created_by
 * @property string $po_number
 * @property string $status
 * @property Carbon|null $order_date
 * @property Carbon|null $expected_date
 * @property Carbon|null $received_date
 * @property Carbon|null $sent_at
 * @property Carbon|null $queued_at
 * @property string|null $sent_to
 * @property string|null $approval_status
 * @property int|null $approval_requested_by
 * @property Carbon|null $approval_requested_at
 * @property int|null $approved_by
 * @property Carbon|null $approved_at
 * @property string|null $approval_notes
 * @property string $subtotal
 * @property string $tax
 * @property string $shipping
 * @property string $total
 * @property string|null $currency
 * @property string|null $exchange_rate 1 exchange_rate_from = this many exchange_rate_to
 * @property string|null $exchange_rate_from The PO currency the rate converts from
 * @property string|null $exchange_rate_to The organization currency it converts to
 * @property string|null $exchange_rate_provider
 * @property Carbon|null $exchange_rate_effective_date
 * @property Carbon|null $exchange_rate_retrieved_at
 * @property string|null $exchange_rate_source provider, fallback, confirmed or manual
 * @property int|null $exchange_rate_set_by
 * @property Carbon|null $exchange_rate_locked_at
 * @property string|null $converted_subtotal
 * @property string|null $converted_tax
 * @property string|null $converted_shipping
 * @property string|null $converted_total
 * @property string|null $notes
 * @property array|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read string $status_color
 * @property-read string $status_label
 * @property-read Organization $organization
 * @property-read Supplier $supplier
 * @property-read User|null $creator
 * @property-read User|null $approver
 * @property-read User|null $approvalRequester
 * @property-read User|null $exchangeRateSetter
 * @property-read Collection|PurchaseOrderItem[] $items
 * @property-read Collection|StockAdjustment[] $stockAdjustments
 */
class PurchaseOrder extends Model
{
    use BelongsToOrganization, LogsActivity, SoftDeletes;

    protected $fillable = [
        'organization_id',
        'supplier_id',
        'created_by',
        'po_number',
        'status',
        'order_date',
        'expected_date',
        'received_date',
        'sent_at',
        'sent_to',
        'queued_at',
        'approval_status',
        'approval_requested_by',
        'approval_requested_at',
        'approved_by',
        'approved_at',
        'approval_notes',
        'subtotal',
        'tax',
        'shipping',
        'total',
        'currency',
        'notes',
        'metadata',
    ];

    protected $casts = [
        'order_date' => 'date',
        'expected_date' => 'date',
        'received_date' => 'date',
        'sent_at' => 'datetime',
        'queued_at' => 'datetime',
        'approval_requested_at' => 'datetime',
        'approved_at' => 'datetime',
        'subtotal' => 'decimal:2',
        'tax' => 'decimal:2',
        'shipping' => 'decimal:2',
        'total' => 'decimal:2',
        'exchange_rate' => 'decimal:10',
        'exchange_rate_effective_date' => 'date',
        'exchange_rate_retrieved_at' => 'datetime',
        'exchange_rate_locked_at' => 'datetime',
        'converted_subtotal' => 'decimal:2',
        'converted_tax' => 'decimal:2',
        'converted_shipping' => 'decimal:2',
        'converted_total' => 'decimal:2',
        'metadata' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * Status constants
     */
    public const STATUS_DRAFT = 'draft';

    public const STATUS_SENT = 'sent';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_RECEIVED = 'received';

    public const STATUS_CANCELLED = 'cancelled';

    /**
     * Approval states. NULL means no approval was asked for.
     */
    public const APPROVAL_PENDING = 'pending';

    public const APPROVAL_APPROVED = 'approved';

    public const APPROVAL_REJECTED = 'rejected';

    /**
     * Fields that change what was approved. Editing any of them on an
     * approved draft sends it back for approval.
     *
     * @var array<int, string>
     */
    public const APPROVED_TERMS = [
        'supplier_id', 'subtotal', 'tax', 'shipping', 'total', 'currency',
        'exchange_rate', 'converted_total',
    ];

    /**
     * Where a PO's exchange rate came from.
     */
    public const RATE_SOURCE_PROVIDER = 'provider';

    public const RATE_SOURCE_FALLBACK = 'fallback';

    public const RATE_SOURCE_CONFIRMED = 'confirmed';

    public const RATE_SOURCE_MANUAL = 'manual';

    /**
     * The exchange rate snapshot. Locked (unchangeable) once the PO leaves
     * draft; the converted totals follow the rate and the PO totals.
     *
     * @var array<int, string>
     */
    public const EXCHANGE_RATE_SNAPSHOT = [
        'exchange_rate', 'exchange_rate_from', 'exchange_rate_to', 'exchange_rate_provider',
        'exchange_rate_effective_date', 'exchange_rate_retrieved_at', 'exchange_rate_source',
        'exchange_rate_set_by', 'converted_subtotal', 'converted_tax', 'converted_shipping', 'converted_total',
    ];

    protected static function booted(): void
    {
        static::saving(function (PurchaseOrder $po): void {
            if ($po->getOriginal('exchange_rate_locked_at') !== null) {
                // A locked snapshot is history: later rate updates, edits or
                // code paths may never rewrite it.
                if ($po->isDirty([...self::EXCHANGE_RATE_SNAPSHOT, 'exchange_rate_locked_at'])) {
                    throw new LogicException("The exchange rate of purchase order {$po->po_number} is locked and cannot change.");
                }

                return;
            }

            $po->syncConvertedTotals();
        });

        static::updating(function (PurchaseOrder $po): void {
            // Leaving draft (emailed to the supplier, or marked as sent)
            // finalizes the PO: its exchange rate, when it has one, is
            // locked from here on, whichever path made the change.
            if ($po->isDirty('status')
                && $po->getOriginal('status') === self::STATUS_DRAFT
                && ! in_array($po->status, [self::STATUS_DRAFT, self::STATUS_CANCELLED], true)
                && $po->exchange_rate !== null
                && $po->exchange_rate_locked_at === null) {
                $po->exchange_rate_locked_at = now();
            }
        });

        static::updating(function (PurchaseOrder $po): void {
            if ($po->status === self::STATUS_DRAFT
                && $po->getOriginal('approval_status') === self::APPROVAL_APPROVED
                && ! $po->isDirty('approval_status')
                && $po->isDirty(self::APPROVED_TERMS)) {
                $po->clearApproval();
            }
        });
    }

    /**
     * Drop a previous approval decision so the PO has to be approved again.
     */
    public function clearApproval(): void
    {
        $this->approval_status = null;
        $this->approved_by = null;
        $this->approved_at = null;
    }

    /**
     * Get the organization that owns the purchase order.
     *
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Get the supplier for this purchase order.
     *
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * Get the user who created this purchase order.
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the user who approved or rejected this purchase order.
     *
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Get the user who submitted this purchase order for approval.
     *
     * @return BelongsTo<User, $this>
     */
    public function approvalRequester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approval_requested_by');
    }

    /**
     * The user who entered or confirmed this PO's exchange rate.
     *
     * @return BelongsTo<User, $this>
     */
    public function exchangeRateSetter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'exchange_rate_set_by');
    }

    /**
     * The organization currency this PO is valued in: the snapshot's target
     * currency, else the organization's current currency.
     */
    public function organizationCurrency(): string
    {
        if ($this->exchange_rate_to !== null && $this->exchange_rate_to !== '') {
            return strtoupper($this->exchange_rate_to);
        }

        $organization = $this->relationLoaded('organization') ? $this->organization : null;

        return $organization instanceof Organization
            ? $organization->baseCurrency()
            : Organization::currencyFor($this->organization_id);
    }

    /**
     * Whether the PO is in a currency other than the organization's.
     */
    public function isForeignCurrency(): bool
    {
        $currency = strtoupper(trim((string) $this->currency));

        return $currency !== '' && $currency !== $this->organizationCurrency();
    }

    public function hasExchangeRate(): bool
    {
        return $this->exchange_rate !== null;
    }

    /**
     * Whether the exchange rate snapshot can no longer change.
     */
    public function exchangeRateIsLocked(): bool
    {
        return $this->exchange_rate_locked_at !== null;
    }

    /**
     * A foreign-currency PO without a rate (an older PO, or one saved while
     * no rate was available) can have one recorded once, while it is with
     * the supplier, by someone allowed to override rates.
     */
    public function canRecordExchangeRate(): bool
    {
        return $this->isForeignCurrency()
            && ! $this->hasExchangeRate()
            && in_array($this->status, [self::STATUS_SENT, self::STATUS_PARTIAL], true);
    }

    /**
     * $amount (in the PO currency) in the organization's currency: as is for
     * a PO in that currency, at the snapshot rate otherwise, null when no
     * rate was recorded (never an invented one).
     */
    public function toOrganizationCurrency(int|float|string|null $amount): ?string
    {
        if (! $this->isForeignCurrency()) {
            return Money::of($amount);
        }

        if (! $this->hasExchangeRate()) {
            return null;
        }

        return ExchangeMath::convert($amount, (string) $this->exchange_rate, $this->organizationCurrency());
    }

    /**
     * The PO total in the organization's currency, or null when it is in a
     * foreign currency with no rate recorded.
     */
    public function organizationCurrencyTotal(): ?string
    {
        if (! $this->isForeignCurrency()) {
            return Money::of($this->total);
        }

        return $this->converted_total !== null ? Money::of($this->converted_total) : null;
    }

    /**
     * Recompute the converted totals from the rate and the PO totals.
     */
    public function syncConvertedTotals(): void
    {
        if ($this->exchange_rate === null) {
            $this->converted_subtotal = null;
            $this->converted_tax = null;
            $this->converted_shipping = null;
            $this->converted_total = null;

            return;
        }

        $to = $this->organizationCurrency();
        $rate = (string) $this->exchange_rate;

        $this->converted_subtotal = ExchangeMath::convert($this->subtotal, $rate, $to);
        $this->converted_tax = ExchangeMath::convert($this->tax, $rate, $to);
        $this->converted_shipping = ExchangeMath::convert($this->shipping, $rate, $to);
        // The total is converted as a whole (not summed from the converted
        // parts) so it is exactly the rate applied to the supplier's total.
        $this->converted_total = ExchangeMath::convert($this->total, $rate, $to);
    }

    /**
     * The exchange rate information shown on the PO page and returned by
     * the REST, GraphQL and MCP surfaces.
     *
     * @return array<string, mixed>
     */
    public function exchangeRateSummary(): array
    {
        $foreign = $this->isForeignCurrency();
        $registry = app(ExchangeRateProviderRegistry::class);

        return [
            'organization_currency' => $this->organizationCurrency(),
            'is_foreign_currency' => $foreign,
            'status' => ! $foreign ? 'same_currency' : ($this->hasExchangeRate() ? 'recorded' : 'not_recorded'),
            'rate' => $this->hasExchangeRate() ? ExchangeMath::rate((string) $this->exchange_rate) : null,
            'from' => $this->exchange_rate_from,
            'to' => $this->exchange_rate_to,
            'provider' => $this->exchange_rate_provider,
            'provider_name' => $registry->nameFor($this->exchange_rate_provider),
            'provider_available' => $this->exchange_rate_provider === null || $registry->has($this->exchange_rate_provider),
            'effective_date' => $this->exchange_rate_effective_date?->format('Y-m-d'),
            'retrieved_at' => $this->exchange_rate_retrieved_at?->toIso8601String(),
            'source' => $this->exchange_rate_source,
            'is_manual' => in_array($this->exchange_rate_source, [self::RATE_SOURCE_MANUAL, self::RATE_SOURCE_CONFIRMED], true),
            'set_by' => $this->exchange_rate_set_by,
            'set_by_name' => $this->exchange_rate_set_by !== null ? $this->exchangeRateSetter?->name : null,
            'locked' => $this->exchangeRateIsLocked(),
            'locked_at' => $this->exchange_rate_locked_at?->toIso8601String(),
            'converted' => [
                'subtotal' => $foreign ? $this->converted_subtotal : Money::of($this->subtotal),
                'tax' => $foreign ? $this->converted_tax : Money::of($this->tax),
                'shipping' => $foreign ? $this->converted_shipping : Money::of($this->shipping),
                'total' => $this->organizationCurrencyTotal(),
            ],
        ];
    }

    /**
     * Whether any line was taxed by rate (rather than by an amount).
     */
    public function hasLineTaxRates(): bool
    {
        return $this->items->contains(fn (PurchaseOrderItem $item) => $item->tax_rate !== null);
    }

    /**
     * The part of the PO's tax entered for the whole PO: the stored tax less
     * the line taxes it already includes. The edit form starts its PO-level
     * tax field from this, never from `tax`, so line tax is not counted twice.
     */
    public function documentLevelTax(): string
    {
        $lineTax = $this->relationLoaded('items')
            ? $this->items->pluck('tax')->all()
            : $this->items()->pluck('tax')->all();

        return Money::max(Money::subtract($this->tax, Money::add(...$lineTax)), 0);
    }

    /**
     * The PO's tax per rate: taxable amount and tax for each rate its lines
     * were taxed at (highest first), then one entry with a null rate for any
     * tax entered as an amount (on lines, or for the whole PO). Empty when
     * the PO has no tax.
     *
     * Each entry also carries the amounts in the organization's currency
     * (`converted_taxable`, `converted_tax`): each converted on its own at
     * the PO's rate, as is for a PO in that currency, null when the PO is in
     * a foreign currency with no rate recorded. Converted one by one they
     * can differ from `converted_tax` by a rounding unit.
     *
     * @return array<int, array{rate: string|null, taxable: string, tax: string, converted_taxable: string|null, converted_tax: string|null}>
     */
    public function taxBreakdown(): array
    {
        // The stored line taxes: they are what the PO charges.
        $lines = $this->items->sortBy('id')->mapWithKeys(fn (PurchaseOrderItem $item) => [$item->id => [
            'taxable' => Money::of($item->subtotal),
            'tax' => Money::of($item->tax),
            'tax_rate' => LineTax::rate($item->tax_rate),
        ]])->all();

        $summary = LineTax::summarize($lines);
        $ratedTaxable = Money::add(...array_column($summary, 'taxable'));
        $otherTax = Money::max(Money::subtract($this->tax, Money::add(...array_column($summary, 'tax'))), 0);

        if (! Money::isZero($otherTax)) {
            $summary[] = [
                'rate' => null,
                'taxable' => Money::max(Money::subtract($this->subtotal, $ratedTaxable), 0),
                'tax' => $otherTax,
            ];
        }

        return array_map(fn (array $entry) => $entry + [
            'converted_taxable' => $this->toOrganizationCurrency($entry['taxable']),
            'converted_tax' => $this->toOrganizationCurrency($entry['tax']),
        ], $summary);
    }

    /**
     * Get the items for this purchase order.
     *
     * @return HasMany<PurchaseOrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    /**
     * Get the stock adjustments for this purchase order.
     *
     * @return MorphMany<StockAdjustment, $this>
     */
    public function stockAdjustments(): MorphMany
    {
        return $this->morphMany(StockAdjustment::class, 'reference');
    }

    /**
     * Scope to filter by organization.
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
     * Scope to filter by status.
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
     * Scope to filter by supplier.
     *
     * @param  Builder<static>  $query
     * @param  int  $supplierId
     * @return Builder<static>
     */
    public function scopeBySupplier($query, $supplierId)
    {
        return $query->where('supplier_id', $supplierId);
    }

    /**
     * Scope for search.
     *
     * @param  Builder<static>  $query
     * @param  string  $search
     * @return Builder<static>
     */
    public function scopeSearch($query, $search)
    {
        return $query->where(function ($q) use ($search) {
            Search::apply($q, ['po_number'], $search)
                ->orWhereHas('supplier', function ($q) use ($search) {
                    Search::apply($q, ['name'], $search);
                });
        });
    }

    /**
     * Generate a unique PO number.
     */
    public static function generatePONumber(int $organizationId): string
    {
        return SequenceNumber::next(static::class, 'po_number', 'PO-'.now()->format('Ymd').'-', $organizationId);
    }

    /**
     * Create a purchase order (and whatever the callback builds alongside it)
     * with a race-safe PO number.
     *
     * generatePONumber() computes the next number as MAX(po_number) + 1 — a
     * non-atomic read-modify-write. Two concurrent creates in the same tenant
     * on the same day can read the same MAX and land on the same number; the
     * loser then hits the per-org UNIQUE constraint and 500s. Mirroring
     * OrderService, the number generation and the insert run together inside a
     * transaction wrapped in SequenceNumberRetry, so a collision rolls the
     * attempt back and re-runs with a freshly read number.
     *
     * The retry wraps the transaction (not the reverse) so every attempt runs
     * in its own clean transaction — required on Postgres, where a constraint
     * violation aborts the surrounding transaction. Keep side effects that must
     * not repeat (counters, console output, notifications) OUTSIDE this call,
     * acting on the returned order, since the callback re-runs on collision.
     *
     * @param  Closure(string $poNumber): PurchaseOrder  $make  Receives the
     *                                                          generated number and returns the persisted order, creating its
     *                                                          items and totals inside the same transaction.
     */
    public static function createWithNumber(int $organizationId, Closure $make, int $transactionAttempts = 1): self
    {
        return SequenceNumberRetry::create(
            fn () => DB::transaction(fn () => $make(self::generatePONumber($organizationId)), $transactionAttempts)
        );
    }

    /**
     * Calculate and update totals from items.
     */
    public function calculateTotals(): void
    {
        $this->subtotal = $this->items->sum('subtotal');
        $this->tax = $this->items->sum('tax');
        $this->total = $this->subtotal + $this->tax + ($this->shipping ?? 0);
        $this->save();
    }

    /**
     * Check if the PO can be edited.
     */
    public function canBeEdited(): bool
    {
        // A draft awaiting an approval decision is frozen so the approver
        // decides on what they see.
        return $this->status === self::STATUS_DRAFT
            && $this->approval_status !== self::APPROVAL_PENDING;
    }

    /**
     * Whether the organization's approval rules apply to this PO's total.
     */
    public function needsApproval(): bool
    {
        $organization = $this->relationLoaded('organization') ? $this->organization : $this->organization_id;

        // Judged in the organization's currency, the currency the threshold
        // is set in. A foreign-currency PO with no rate recorded cannot be
        // shown to be under the threshold, so it needs approval. Nor can one
        // whose rate was typed in by hand: whoever raises the PO could
        // otherwise enter a tiny rate to keep a large order under it.
        $total = $this->isForeignCurrency() && $this->exchange_rate_source === self::RATE_SOURCE_MANUAL
            ? null
            : $this->organizationCurrencyTotal();

        return ApprovalSettings::forOrganization($organization)
            ->purchaseOrderNeedsApproval($total === null ? null : (float) $total);
    }

    /**
     * A draft that needs approval and does not have it yet.
     */
    public function awaitsApproval(): bool
    {
        return $this->status === self::STATUS_DRAFT
            && $this->approval_status !== self::APPROVAL_APPROVED
            && $this->needsApproval();
    }

    /**
     * Check if the PO can be submitted for approval.
     */
    public function canBeSubmittedForApproval(): bool
    {
        return $this->status === self::STATUS_DRAFT
            && in_array($this->approval_status, [null, self::APPROVAL_REJECTED], true)
            && $this->needsApproval()
            && $this->items()->count() > 0;
    }

    /**
     * Whether the latest email to the supplier is still waiting in the queue:
     * it was queued and has not been delivered since (sent_at is stamped on
     * delivery, so a resend reads as queued until it goes out).
     */
    public function emailIsQueued(): bool
    {
        return $this->queued_at !== null
            && ($this->sent_at === null || $this->sent_at->lessThan($this->queued_at));
    }

    /**
     * Check if the PO can be sent.
     */
    public function canBeSent(): bool
    {
        // A draft with items is sent for the first time; a PO already with the
        // supplier (sent, or partly received) may be re-sent.
        if (in_array($this->status, [self::STATUS_SENT, self::STATUS_PARTIAL], true)) {
            return true;
        }

        return $this->status === self::STATUS_DRAFT
            && $this->items()->count() > 0
            && ! $this->awaitsApproval();
    }

    /**
     * Check if the PO can receive items.
     */
    public function canReceiveItems(): bool
    {
        return in_array($this->status, [self::STATUS_SENT, self::STATUS_PARTIAL]);
    }

    /**
     * Check if the PO can be cancelled.
     */
    public function canBeCancelled(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_SENT]);
    }

    /**
     * Check if all items have been fully received.
     */
    public function isFullyReceived(): bool
    {
        return $this->items->every(fn ($item) => $item->isFullyReceived());
    }

    /**
     * Check if any items have been partially received.
     */
    public function isPartiallyReceived(): bool
    {
        return $this->items->some(fn ($item) => $item->quantity_received > 0)
            && ! $this->isFullyReceived();
    }

    /**
     * Update status based on receiving state.
     */
    public function updateReceivingStatus(): void
    {
        if ($this->isFullyReceived()) {
            $this->status = self::STATUS_RECEIVED;
            $this->received_date = now();
        } elseif ($this->isPartiallyReceived()) {
            $this->status = self::STATUS_PARTIAL;
        }
        $this->save();
    }

    /**
     * Set the status to sent, with no checks. User actions go through
     * PurchaseOrderEmailService (send() or markSent()), which enforce the
     * approval gate and lock the row.
     */
    public function markAsSent(): void
    {
        $this->status = self::STATUS_SENT;
        $this->save();
    }

    /**
     * Cancel the purchase order.
     *
     * The row is re-read under a lock and re-checked, so a cancel decided on
     * a stale copy cannot overwrite a receipt that committed in between
     * (receiving takes the same lock).
     *
     * @throws InvalidStateException cannot_cancel when the PO is no longer cancellable
     */
    public function cancel(): void
    {
        DB::transaction(function (): void {
            $locked = static::withoutGlobalScope(OrganizationScope::class)
                ->whereKey($this->getKey())
                ->where('organization_id', $this->organization_id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->canBeCancelled()) {
                $this->setRawAttributes($locked->getAttributes(), true);

                throw new InvalidStateException('This purchase order cannot be cancelled', 'cannot_cancel');
            }

            $locked->status = self::STATUS_CANCELLED;
            $locked->save();
            $this->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /**
     * Get status badge color.
     */
    public function getStatusColorAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_DRAFT => 'gray',
            self::STATUS_SENT => 'blue',
            self::STATUS_PARTIAL => 'yellow',
            self::STATUS_RECEIVED => 'green',
            self::STATUS_CANCELLED => 'red',
            default => 'gray',
        };
    }

    /**
     * Get status label.
     */
    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_DRAFT => 'Draft',
            self::STATUS_SENT => 'Sent',
            self::STATUS_PARTIAL => 'Partial',
            self::STATUS_RECEIVED => 'Received',
            self::STATUS_CANCELLED => 'Cancelled',
            default => ucfirst($this->status),
        };
    }
}
