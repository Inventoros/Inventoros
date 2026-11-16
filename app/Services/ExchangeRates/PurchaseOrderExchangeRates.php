<?php

declare(strict_types=1);

namespace App\Services\ExchangeRates;

use App\Enums\Permission;
use App\Http\Middleware\CheckApiPermission;
use App\Models\Auth\Organization;
use App\Models\Purchasing\PurchaseOrder;
use App\Models\Scopes\OrganizationScope;
use App\Models\User;
use App\Support\ExchangeMath;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Decides a purchase order's exchange rate snapshot.
 *
 * A PO in the organization's currency has none. A PO in another currency
 * takes the rate for its order date from the organization's provider (or
 * its configured fallback, labelled as such) while it is a draft. Someone
 * with override_purchase_order_exchange_rate may instead enter a rate
 * ("manual") or accept a stale provider rate ("confirm"); either is kept
 * through later edits until the currency changes or they ask for the
 * provider's rate again ("auto"). When no rate is available the PO is saved
 * without one ("no rate recorded"): a missing rate never blocks a draft.
 *
 * The snapshot locks when the PO leaves draft (PurchaseOrder model hook).
 */
final class PurchaseOrderExchangeRates
{
    public const MODE_AUTO = 'auto';

    public const MODE_MANUAL = 'manual';

    public const MODE_CONFIRM = 'confirm';

    public const MODES = [self::MODE_AUTO, self::MODE_MANUAL, self::MODE_CONFIRM];

    public function __construct(private readonly ExchangeRateService $rates) {}

    /**
     * Validation rules for the optional exchange rate input on every PO
     * create/update surface.
     *
     * @return array<string, array<int, string>>
     */
    public static function rules(): array
    {
        return [
            'exchange_rate_mode' => ['nullable', 'string', 'in:'.implode(',', self::MODES)],
            'exchange_rate' => ['nullable', 'required_if:exchange_rate_mode,'.self::MODE_MANUAL, 'numeric', 'gt:0', 'max:9999999999', 'decimal:0,10'],
        ];
    }

    /**
     * Whether $user may enter or confirm a PO's rate (role and, for API
     * tokens, the token's abilities).
     */
    public function canOverride(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        $ability = Permission::OVERRIDE_PURCHASE_ORDER_EXCHANGE_RATE->value;

        return $user->hasPermission($ability)
            && CheckApiPermission::tokenAllows($user->currentAccessToken())($ability);
    }

    /**
     * The snapshot attributes to save on a draft PO, or null to leave the
     * existing snapshot as it is.
     *
     * Call it before opening the transaction that saves the PO: it may ask
     * the provider for a rate.
     *
     * @param  array<string, mixed>  $input  exchange_rate_mode / exchange_rate from the request
     * @return array<string, mixed>|null
     *
     * @throws ValidationException when an override is not allowed or there is nothing to confirm
     */
    public function snapshotFor(int $organizationId, string $currency, mixed $orderDate, array $input, ?PurchaseOrder $existing, ?User $actor): ?array
    {
        $currency = strtoupper(trim($currency));
        $to = Organization::currencyFor($organizationId);
        // An update that leaves the order date out keeps the PO's own.
        $orderDate ??= $existing?->order_date?->toDateString();
        $mode = $input['exchange_rate_mode'] ?? null;
        $mode = is_string($mode) && $mode !== '' ? $mode : null;

        // A bare exchange_rate means "use this rate".
        if ($mode === null && isset($input['exchange_rate']) && $input['exchange_rate'] !== '') {
            $mode = self::MODE_MANUAL;
        }

        if ($currency === $to || $currency === '') {
            return $existing !== null && $existing->exchange_rate === null && $existing->exchange_rate_to === null
                ? null
                : self::empty();
        }

        if (in_array($mode, [self::MODE_MANUAL, self::MODE_CONFIRM], true) && ! $this->canOverride($actor)) {
            throw ValidationException::withMessages([
                'exchange_rate' => __('You do not have permission to set a purchase order exchange rate.'),
            ]);
        }

        $date = $this->rates->requestedDate($organizationId, is_string($orderDate) || $orderDate instanceof \DateTimeInterface ? CarbonImmutable::parse($orderDate) : null);

        if ($mode === self::MODE_MANUAL) {
            return $this->manual($currency, $to, (string) $input['exchange_rate'], $date, $actor);
        }

        $sameTerms = $existing !== null
            && $existing->exchange_rate !== null
            && strtoupper((string) $existing->exchange_rate_from) === $currency
            && strtoupper((string) $existing->exchange_rate_to) === $to;

        if ($mode === null && $sameTerms) {
            $newOrderDate = is_string($orderDate) || $orderDate instanceof \DateTimeInterface
                ? CarbonImmutable::parse($orderDate)->toDateString()
                : null;
            $sameDate = $newOrderDate === null || $existing->order_date?->toDateString() === $newOrderDate;

            // An entered or confirmed rate stays until the currency changes or
            // someone asks for the provider's rate; a provider rate stays
            // while the order date does.
            if (in_array($existing->exchange_rate_source, [PurchaseOrder::RATE_SOURCE_MANUAL, PurchaseOrder::RATE_SOURCE_CONFIRMED], true) || $sameDate) {
                return null;
            }
        }

        $quote = $this->rates->quote($organizationId, $currency, $date);

        if ($mode === self::MODE_CONFIRM) {
            if (! $quote->hasRate()) {
                throw ValidationException::withMessages([
                    'exchange_rate' => __('There is no exchange rate to confirm. Enter one instead.'),
                ]);
            }

            return $this->fromQuote($quote, $quote->isUsable() ? null : $actor);
        }

        if ($quote->isUsable() && $quote->hasRate()) {
            return $this->fromQuote($quote, null);
        }

        // No current rate: keep a provider rate the draft already has for
        // this currency rather than drop it; otherwise record none.
        return $sameTerms ? null : self::empty();
    }

    /**
     * Give a draft created elsewhere (quick reorder, automatic replenishment,
     * the MCP tool) its rate. Call it after the PO is saved and outside any
     * transaction; without an available rate the draft stays without one.
     *
     * @param  array<string, mixed>  $input
     */
    public function applyToDraft(PurchaseOrder $purchaseOrder, array $input = [], ?User $actor = null): PurchaseOrder
    {
        if ($purchaseOrder->status !== PurchaseOrder::STATUS_DRAFT || $purchaseOrder->exchangeRateIsLocked()) {
            return $purchaseOrder;
        }

        $snapshot = $this->snapshotFor(
            (int) $purchaseOrder->organization_id,
            (string) $purchaseOrder->currency,
            $purchaseOrder->order_date?->toDateString(),
            $input,
            $purchaseOrder,
            $actor,
        );

        if ($snapshot !== null) {
            $purchaseOrder->forceFill($snapshot)->save();
        }

        return $purchaseOrder;
    }

    /**
     * Record a rate on a PO that has left draft without one (an older PO,
     * or one sent while no rate was available). It locks immediately.
     *
     * @throws ValidationException when not allowed or the PO cannot take a rate
     */
    public function record(PurchaseOrder $purchaseOrder, User $actor, string $rate): PurchaseOrder
    {
        if (! $this->canOverride($actor)) {
            throw ValidationException::withMessages([
                'exchange_rate' => __('You do not have permission to set a purchase order exchange rate.'),
            ]);
        }

        return DB::transaction(function () use ($purchaseOrder, $actor, $rate) {
            $locked = PurchaseOrder::withoutGlobalScope(OrganizationScope::class)
                ->whereKey($purchaseOrder->getKey())
                ->where('organization_id', $actor->organization_id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->canRecordExchangeRate()) {
                throw ValidationException::withMessages([
                    'exchange_rate' => __('This purchase order already has an exchange rate, or does not need one.'),
                ]);
            }

            $to = $locked->organizationCurrency();
            $date = $this->rates->requestedDate((int) $locked->organization_id, $locked->order_date);

            $locked->forceFill($this->manual(strtoupper((string) $locked->currency), $to, $rate, $date, $actor));
            $locked->exchange_rate_locked_at = now();
            $locked->save();

            return $locked;
        });
    }

    /**
     * @return array<string, mixed>
     */
    public static function empty(): array
    {
        return array_fill_keys(PurchaseOrder::EXCHANGE_RATE_SNAPSHOT, null);
    }

    /**
     * @return array<string, mixed>
     */
    private function manual(string $from, string $to, string $rate, CarbonImmutable $date, ?User $actor): array
    {
        if (! ExchangeMath::isValidRate($rate)) {
            throw ValidationException::withMessages(['exchange_rate' => __('Enter an exchange rate greater than zero.')]);
        }

        return [
            'exchange_rate' => ExchangeMath::rate($rate),
            'exchange_rate_from' => $from,
            'exchange_rate_to' => $to,
            'exchange_rate_provider' => null,
            'exchange_rate_effective_date' => $date->toDateString(),
            'exchange_rate_retrieved_at' => now(),
            'exchange_rate_source' => PurchaseOrder::RATE_SOURCE_MANUAL,
            'exchange_rate_set_by' => $actor?->id,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fromQuote(RateQuote $quote, ?User $confirmedBy): array
    {
        return [
            'exchange_rate' => $quote->rate,
            'exchange_rate_from' => $quote->from,
            'exchange_rate_to' => $quote->to,
            'exchange_rate_provider' => $quote->provider,
            'exchange_rate_effective_date' => $quote->effectiveDate?->toDateString(),
            'exchange_rate_retrieved_at' => $quote->retrievedAt ?? now(),
            'exchange_rate_source' => match (true) {
                $confirmedBy !== null => PurchaseOrder::RATE_SOURCE_CONFIRMED,
                $quote->fallback => PurchaseOrder::RATE_SOURCE_FALLBACK,
                default => PurchaseOrder::RATE_SOURCE_PROVIDER,
            },
            'exchange_rate_set_by' => $confirmedBy?->id,
        ];
    }
}
