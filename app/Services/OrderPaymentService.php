<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Models\ActivityLog;
use App\Models\Order\Order;
use App\Models\Order\OrderPayment;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Single home for money moving against a sales order: recording payments,
 * refunding, and voiding either. Every surface (web, REST, MCP) goes through
 * here, so the guards below hold everywhere:
 *
 *  - the order row is locked (SELECT ... FOR UPDATE) and what has been paid is
 *    re-summed from the payment rows inside the transaction, so two payments
 *    racing each other cannot both pass the balance check against the same
 *    stale figure;
 *  - a payment larger than the balance due is rejected unless the caller
 *    explicitly allows an overpayment;
 *  - a cancelled order takes no new payments (refunds are still allowed);
 *  - a refund never exceeds what is currently paid, and voiding a payment may
 *    not leave refunds standing against money that was never received;
 *  - rows are never edited or deleted, only voided, so the history is an
 *    audit trail.
 *
 * The order's amount_paid and payment_status are re-derived and saved in the
 * same transaction. Plugin hooks (and so webhooks) fire after commit.
 */
final class OrderPaymentService
{
    /**
     * Record a payment received against an order.
     *
     * @param  array{amount: mixed, method: string, reference?: string|null, paid_at?: mixed, notes?: string|null, allow_overpayment?: bool}  $data
     *
     * @throws ValidationException
     */
    public function record(Order $order, User $actor, array $data): OrderPayment
    {
        [$payment, $locked] = DB::transaction(function () use ($order, $actor, $data) {
            $locked = $this->lock($order);
            $amount = $this->amount($data['amount'] ?? null);

            if ($locked->status === OrderStatus::CANCELLED) {
                throw ValidationException::withMessages([
                    'order' => 'Payments cannot be recorded against a cancelled order.',
                ]);
            }

            // An untracked order (from before payment tracking) reports no
            // balance, but its first payment is still held to the total.
            $balance = $locked->unpaidAmount();
            if (Money::compare($amount, $balance) > 0 && ! ($data['allow_overpayment'] ?? false)) {
                throw ValidationException::withMessages([
                    'amount' => "This payment of {$amount} is more than the {$balance} balance due. Allow an overpayment to record it anyway.",
                ]);
            }

            $payment = $this->insert($locked, $actor, PaymentType::PAYMENT, $amount, $data);
            $this->settle($locked);

            $this->log($locked, $actor, 'payment_recorded', "Recorded payment of {$amount} ({$payment->method->label()})", $payment);

            return [$payment, $locked];
        });

        do_action('payment_recorded', $payment, $locked, $actor);

        return $payment;
    }

    /**
     * Record a refund given back to the customer.
     *
     * @param  array{amount: mixed, method: string, reference?: string|null, paid_at?: mixed, notes?: string|null}  $data
     *
     * @throws ValidationException
     */
    public function refund(Order $order, User $actor, array $data): OrderPayment
    {
        [$refund, $locked] = DB::transaction(function () use ($order, $actor, $data) {
            $locked = $this->lock($order);
            $amount = $this->amount($data['amount'] ?? null);

            if (Money::compare($amount, $locked->amount_paid) > 0) {
                throw ValidationException::withMessages([
                    'amount' => "A refund cannot exceed the {$locked->amount_paid} paid on this order.",
                ]);
            }

            $refund = $this->insert($locked, $actor, PaymentType::REFUND, $amount, $data);
            $this->settle($locked);

            $this->log($locked, $actor, 'payment_refunded', "Refunded {$amount} ({$refund->method->label()})", $refund);

            return [$refund, $locked];
        });

        do_action('payment_recorded', $refund, $locked, $actor);

        return $refund;
    }

    /**
     * Void a payment or refund. The row stays, marked voided, and stops
     * counting towards what was paid.
     *
     * @throws ValidationException
     */
    public function void(OrderPayment $payment, User $actor, ?string $reason): OrderPayment
    {
        [$voided, $locked] = DB::transaction(function () use ($payment, $actor, $reason) {
            $locked = $this->lock($payment->order()->withTrashed()->withoutGlobalScopes()->firstOrFail());
            $row = OrderPayment::withoutGlobalScopes()->whereKey($payment->getKey())->lockForUpdate()->firstOrFail();

            if ($row->isVoided()) {
                throw ValidationException::withMessages([
                    'payment' => 'This payment has already been voided.',
                ]);
            }

            if (! $row->isRefund() && Money::compare($row->amount, $locked->amount_paid) > 0) {
                throw ValidationException::withMessages([
                    'payment' => 'Voiding this payment would leave refunds larger than what was paid. Void the refunds first.',
                ]);
            }

            $row->forceFill([
                'voided_at' => now(),
                'voided_by' => $actor->id,
                'void_reason' => $reason,
            ])->save();

            $this->settle($locked);

            $label = $row->isRefund() ? 'refund' : 'payment';
            $this->log($locked, $actor, 'payment_voided', "Voided {$label} of {$row->amount}", $row, ['reason' => $reason]);

            return [$row, $locked];
        });

        do_action('payment_voided', $voided, $locked, $actor);

        return $voided;
    }

    public const PRE_TRACKING_REFERENCE = 'Marked paid (pre-tracking)';

    /**
     * Bring untracked orders (placed before payment tracking) dated before
     * $before into tracking as paid. Each gets a payment of method `other`
     * for its full total, dated on the order date, so amounts reconcile
     * with the payment rows. Zero-total orders need no payment and become
     * paid; cancelled orders are left untracked (they owe nothing anyway).
     *
     * Plugin hooks and webhooks are not fired: this settles history in bulk,
     * it does not receive money. Each order gets an activity-log entry.
     *
     * @return int How many orders were marked paid
     */
    public function markPreTrackingOrdersPaid(int $organizationId, User $actor, Carbon $before): int
    {
        $marked = 0;

        Order::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->where('payment_status', PaymentStatus::UNTRACKED->value)
            ->where('status', '!=', OrderStatus::CANCELLED->value)
            ->where('order_date', '<', $before->copy()->startOfDay())
            ->select('id')
            ->chunkById(200, function ($orders) use ($actor, &$marked) {
                foreach ($orders as $row) {
                    DB::transaction(function () use ($row, $actor, &$marked) {
                        $locked = Order::withoutGlobalScopes()->whereKey($row->id)->lockForUpdate()->first();

                        // Re-checked under the lock: a payment may have been
                        // recorded since the list was read.
                        if ($locked === null || $locked->payment_status !== PaymentStatus::UNTRACKED
                            || $locked->payments()->withoutGlobalScopes()->exists()) {
                            return;
                        }

                        $amount = Money::of($locked->total);
                        $payment = null;

                        if (Money::compare($amount, 0) > 0) {
                            $payment = $this->insert($locked, $actor, PaymentType::PAYMENT, $amount, [
                                'method' => PaymentMethod::OTHER->value,
                                'reference' => self::PRE_TRACKING_REFERENCE,
                                'paid_at' => $locked->order_date ?? $locked->created_at ?? now(),
                            ]);
                        }

                        // Out of the untracked state so the derivation applies.
                        $locked->payment_status = PaymentStatus::UNPAID;
                        $this->settle($locked);

                        ActivityLog::create([
                            'organization_id' => $locked->organization_id,
                            'user_id' => $actor->id,
                            'subject_type' => Order::class,
                            'subject_id' => $locked->id,
                            'action' => 'pre_tracking_marked_paid',
                            'description' => "Marked order {$locked->order_number} paid (placed before payment tracking)",
                            'properties' => [
                                'payment_id' => $payment?->id,
                                'amount' => $amount,
                                'payment_status' => $locked->payment_status->value,
                            ],
                            'ip_address' => request()?->ip(),
                            'user_agent' => request()?->userAgent(),
                        ]);

                        $marked++;
                    });
                }
            });

        return $marked;
    }

    /**
     * Lock the order row and re-derive what has been paid from the payment
     * rows, ignoring whatever the caller's (possibly stale) model says.
     */
    private function lock(Order $order): Order
    {
        $locked = Order::withoutGlobalScopes()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
        $locked->syncPaymentState();

        return $locked;
    }

    /**
     * Re-derive the order's payment state after a change and save it.
     */
    private function settle(Order $locked): void
    {
        $locked->syncPaymentState();
        $locked->saveQuietly();
    }

    /**
     * @throws ValidationException
     */
    private function amount(mixed $value): string
    {
        if (! is_numeric($value) || (float) $value <= 0) {
            throw ValidationException::withMessages([
                'amount' => 'The amount must be greater than zero.',
            ]);
        }

        return Money::of(number_format((float) $value, 2, '.', ''));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function insert(Order $order, User $actor, PaymentType $type, string $amount, array $data): OrderPayment
    {
        $method = PaymentMethod::tryFrom((string) ($data['method'] ?? ''));
        if ($method === null) {
            throw ValidationException::withMessages([
                'method' => 'The method must be one of: '.implode(', ', PaymentMethod::values()).'.',
            ]);
        }

        $payment = new OrderPayment([
            'organization_id' => $order->organization_id,
            'order_id' => $order->id,
            'user_id' => $actor->id,
            'type' => $type,
            'amount' => $amount,
            'method' => $method,
            'reference' => $data['reference'] ?? null,
            'paid_at' => $this->paidAt($data['paid_at'] ?? null),
            'notes' => $data['notes'] ?? null,
        ]);
        $payment->save();

        return $payment;
    }

    /**
     * When the money moved. A bare date (what the payment form sends) is
     * stored at midday UTC rather than midnight, so it shows as the same
     * calendar day in any viewer's timezone instead of slipping to the day
     * before west of UTC.
     */
    private function paidAt(mixed $value): Carbon
    {
        if ($value === null || $value === '') {
            return now();
        }

        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            return Carbon::parse($value, 'UTC')->setTime(12, 0);
        }

        return Carbon::parse($value);
    }

    /**
     * Write an activity-log entry against the order. Written explicitly with
     * the acting user rather than through ActivityLog::log(), which reads
     * auth() and so records nothing for token-authenticated API/MCP calls
     * on some guards.
     *
     * @param  array<string, mixed>  $extra
     */
    private function log(Order $order, User $actor, string $action, string $description, OrderPayment $payment, array $extra = []): void
    {
        ActivityLog::create([
            'organization_id' => $order->organization_id,
            'user_id' => $actor->id,
            'subject_type' => Order::class,
            'subject_id' => $order->id,
            'action' => $action,
            'description' => "{$description} on order {$order->order_number}",
            'properties' => [
                'payment_id' => $payment->id,
                'type' => $payment->type->value,
                'amount' => (string) $payment->amount,
                'method' => $payment->method->value,
                'reference' => $payment->reference,
                'amount_paid' => (string) $order->amount_paid,
                'payment_status' => $order->payment_status->value,
            ] + $extra,
            'ip_address' => request()?->ip(),
            'user_agent' => request()?->userAgent(),
        ]);
    }
}
