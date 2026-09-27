<?php

declare(strict_types=1);

namespace App\Services\Documents;

use App\Models\Order\Order;
use App\Models\Scopes\OrganizationScope;
use App\Support\SequenceNumberRetry;
use Illuminate\Support\Facades\DB;

/**
 * Assigns order invoice numbers: INV-000001, INV-000002, ... one continuous
 * sequence per organization, allocated the first time an order's invoice is
 * generated and kept for good after that.
 *
 * Allocation mirrors order/PO numbering: next() reads MAX + 1 (including
 * soft-deleted orders, since the unique index still counts them) and the
 * write runs inside SequenceNumberRetry, so two orders invoiced at the same
 * moment that land on the same number retry with a fresh read instead of
 * failing on the per-org unique index. The write is conditional on the order
 * still having no number, so two requests invoicing the SAME order can never
 * hand it two different numbers.
 */
class OrderInvoiceNumberService
{
    public const PREFIX = 'INV-';

    public const PAD = 6;

    /**
     * Return the order's invoice number, allocating one if it has none yet.
     */
    public function ensureAssigned(Order $order): string
    {
        if (filled($order->invoice_number)) {
            return $order->invoice_number;
        }

        $organizationId = (int) $order->organization_id;

        SequenceNumberRetry::create(fn () => DB::transaction(fn () => $this->query()
            ->whereKey($order->getKey())
            ->whereNull('invoice_number')
            ->update([
                'invoice_number' => $this->next($organizationId),
                'invoice_issued_at' => now(),
            ])));

        // Whether this call allocated it or a concurrent one got there first,
        // the row now holds the order's one and only invoice number.
        $stored = $this->query()->whereKey($order->getKey())->first(['invoice_number', 'invoice_issued_at']);

        $order->forceFill([
            'invoice_number' => $stored->invoice_number,
            'invoice_issued_at' => $stored->invoice_issued_at,
        ])->syncOriginalAttributes(['invoice_number', 'invoice_issued_at']);

        return $order->invoice_number;
    }

    /**
     * The next unused invoice number for an organization.
     */
    public function next(int $organizationId): string
    {
        // Longest first, then highest: keeps the order right if a tenant ever
        // outgrows the zero padding (INV-1000000 must beat INV-999999).
        $last = $this->query()
            ->where('organization_id', $organizationId)
            ->where('invoice_number', 'like', self::PREFIX.'%')
            ->orderByRaw('LENGTH(invoice_number) DESC')
            ->orderByDesc('invoice_number')
            ->value('invoice_number');

        $next = $last ? ((int) substr($last, strlen(self::PREFIX))) + 1 : 1;

        return self::PREFIX.str_pad((string) $next, self::PAD, '0', STR_PAD_LEFT);
    }

    /**
     * Orders across the tenant boundary and including soft-deleted rows: the
     * caller always constrains by organization id explicitly, and this also
     * runs in queue workers with no authenticated user.
     */
    private function query()
    {
        return Order::withTrashed()->withoutGlobalScope(OrganizationScope::class);
    }
}
