<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PaymentStatus;
use App\Jobs\WebhookDeliveryJob;
use App\Models\ActivityLog;
use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Order\Order;
use App\Models\Order\OrderPayment;
use App\Models\System\SystemSetting;
use App\Models\User;
use App\Models\Webhook;
use App\Services\OrderPaymentService;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Recording, refunding and voiding payments against an order, and the order's
 * derived amount_paid / balance_due / payment_status.
 */
class OrderPaymentServiceTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $organization;

    protected User $admin;

    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        SystemSetting::set('installed', true, 'boolean');

        $this->organization = Organization::create([
            'name' => 'Pay Org', 'email' => 'pay@org.com', 'currency' => 'USD', 'timezone' => 'UTC',
        ]);
        $this->product = Product::create([
            'organization_id' => $this->organization->id, 'sku' => 'PAY-1', 'name' => 'Thing',
            'price' => 25.00, 'currency' => 'USD', 'stock' => 100, 'min_stock' => 0, 'is_active' => true,
        ]);
        $this->admin = User::create([
            'name' => 'Admin', 'email' => 'admin@pay.test', 'password' => bcrypt('password'),
            'organization_id' => $this->organization->id, 'role' => 'admin',
        ]);
    }

    /** An order with a 100.00 total. */
    private function order(array $overrides = []): Order
    {
        return app(OrderService::class)->create(array_merge([
            'customer_name' => 'Acme', 'status' => 'pending', 'order_date' => now()->toDateString(),
            'items' => [['product_id' => $this->product->id, 'quantity' => 4, 'unit_price' => 25.00]],
        ], $overrides), $this->admin);
    }

    private function payments(): OrderPaymentService
    {
        return app(OrderPaymentService::class);
    }

    private function pay(Order $order, string|float $amount, array $extra = []): OrderPayment
    {
        return $this->payments()->record($order, $this->admin, array_merge(['amount' => $amount, 'method' => 'card'], $extra));
    }

    private function assertValidationError(string $key, callable $callback): void
    {
        try {
            $callback();
            $this->fail("Expected a validation error on {$key}");
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($key, $e->errors(), json_encode($e->errors()));
        }
    }

    public function test_a_new_order_is_unpaid_with_the_full_balance_due(): void
    {
        $order = $this->order()->fresh();

        $this->assertSame(PaymentStatus::UNPAID, $order->payment_status);
        $this->assertSame('0.00', (string) $order->amount_paid);
        $this->assertSame('100.00', $order->balanceDue());
    }

    public function test_partial_then_full_payment(): void
    {
        $order = $this->order();

        $payment = $this->pay($order, '40.00', ['reference' => 'TX-1', 'notes' => 'deposit']);

        $this->assertSame('40.00', (string) $payment->amount);
        $this->assertSame('card', $payment->method->value);
        $this->assertSame($this->admin->id, $payment->user_id);
        $this->assertSame($this->organization->id, $payment->organization_id);
        $this->assertNotNull($payment->paid_at);

        $order->refresh();
        $this->assertSame(PaymentStatus::PARTIAL, $order->payment_status);
        $this->assertSame('40.00', (string) $order->amount_paid);
        $this->assertSame('60.00', $order->balanceDue());

        $this->pay($order, '60.00', ['method' => 'cash']);

        $order->refresh();
        $this->assertSame(PaymentStatus::PAID, $order->payment_status);
        $this->assertSame('0.00', $order->balanceDue());
    }

    public function test_a_date_only_paid_at_is_stored_at_midday_so_it_keeps_its_calendar_day(): void
    {
        $payment = $this->pay($this->order(), '10.00', ['paid_at' => '2026-03-05']);

        $this->assertSame('2026-03-05 12:00:00', $payment->fresh()->paid_at->utc()->format('Y-m-d H:i:s'));
    }

    public function test_an_overpayment_is_rejected_by_default(): void
    {
        $order = $this->order();
        $this->pay($order, '90.00');

        $this->assertValidationError('amount', fn () => $this->pay($order, '10.01'));

        $this->assertSame(1, OrderPayment::count());
        $this->assertSame('90.00', (string) $order->fresh()->amount_paid);
    }

    public function test_an_overpayment_is_allowed_with_the_explicit_flag(): void
    {
        $order = $this->order();

        $this->pay($order, '120.00', ['allow_overpayment' => true]);

        $order->refresh();
        $this->assertSame(PaymentStatus::OVERPAID, $order->payment_status);
        $this->assertSame('120.00', (string) $order->amount_paid);
        $this->assertSame('0.00', $order->balanceDue());
    }

    public function test_the_balance_is_recomputed_under_the_lock_not_read_from_a_stale_model(): void
    {
        $order = $this->order();
        $stale = Order::findOrFail($order->id); // loaded while nothing was paid

        // Another request pays 70 in the meantime.
        $this->pay($order, '70.00');

        // The stale copy still says 0 paid / 100 due; a 50 payment would look
        // fine against it. The service must re-read under the row lock and see
        // only 30 left.
        $this->assertSame('0.00', (string) $stale->amount_paid);
        $this->assertValidationError('amount', fn () => $this->pay($stale, '50.00'));

        $this->pay($stale, '30.00');
        $this->assertSame(PaymentStatus::PAID, $order->fresh()->payment_status);
    }

    public function test_amount_must_be_positive(): void
    {
        $order = $this->order();

        $this->assertValidationError('amount', fn () => $this->pay($order, '0'));
        $this->assertValidationError('amount', fn () => $this->pay($order, '-5'));
    }

    public function test_paying_a_cancelled_order_is_rejected(): void
    {
        $order = $this->order();
        $this->actingAs($this->admin);
        app(OrderService::class)->cancel($order);

        $this->assertValidationError('order', fn () => $this->pay($order, '10.00'));
        $this->assertSame(0, OrderPayment::count());
    }

    public function test_refund_reduces_paid_and_cannot_exceed_it(): void
    {
        $order = $this->order();
        $this->pay($order, '100.00');

        $this->assertValidationError('amount', fn () => $this->payments()->refund($order, $this->admin, ['amount' => '100.01', 'method' => 'card']));

        $refund = $this->payments()->refund($order, $this->admin, ['amount' => '30.00', 'method' => 'card', 'reference' => 'RF-1']);
        $this->assertTrue($refund->isRefund());

        $order->refresh();
        $this->assertSame('70.00', (string) $order->amount_paid);
        $this->assertSame(PaymentStatus::PARTIAL, $order->payment_status);

        $this->payments()->refund($order, $this->admin, ['amount' => '70.00', 'method' => 'card']);
        $order->refresh();
        $this->assertSame('0.00', (string) $order->amount_paid);
        $this->assertSame(PaymentStatus::REFUNDED, $order->payment_status);
    }

    public function test_a_cancelled_order_can_still_be_refunded(): void
    {
        $order = $this->order();
        $this->pay($order, '50.00');
        $this->actingAs($this->admin);
        app(OrderService::class)->cancel($order);

        $this->payments()->refund($order, $this->admin, ['amount' => '50.00', 'method' => 'bank_transfer']);

        $this->assertSame(PaymentStatus::REFUNDED, $order->fresh()->payment_status);
    }

    public function test_voiding_a_payment_removes_it_from_the_balance(): void
    {
        $order = $this->order();
        $payment = $this->pay($order, '100.00');

        $voided = $this->payments()->void($payment, $this->admin, 'Card declined');

        $this->assertNotNull($voided->voided_at);
        $this->assertSame($this->admin->id, $voided->voided_by);
        $this->assertSame('Card declined', $voided->void_reason);

        $order->refresh();
        $this->assertSame('0.00', (string) $order->amount_paid);
        $this->assertSame(PaymentStatus::UNPAID, $order->payment_status);
        $this->assertSame(1, OrderPayment::count(), 'voiding keeps the row as an audit trail');
    }

    public function test_a_payment_cannot_be_voided_twice(): void
    {
        $order = $this->order();
        $payment = $this->pay($order, '10.00');
        $this->payments()->void($payment, $this->admin, null);

        $this->assertValidationError('payment', fn () => $this->payments()->void($payment->fresh(), $this->admin, null));
    }

    public function test_a_payment_cannot_be_voided_while_its_refund_stands(): void
    {
        $order = $this->order();
        $payment = $this->pay($order, '100.00');
        $refund = $this->payments()->refund($order, $this->admin, ['amount' => '40.00', 'method' => 'card']);

        // Voiding the payment would leave a 40.00 refund against nothing paid.
        $this->assertValidationError('payment', fn () => $this->payments()->void($payment, $this->admin, null));

        // Void the refund first, then the payment.
        $this->payments()->void($refund, $this->admin, null);
        $this->payments()->void($payment->fresh(), $this->admin, null);
        $this->assertSame('0.00', (string) $order->fresh()->amount_paid);
    }

    public function test_editing_an_order_total_below_the_amount_paid_is_rejected(): void
    {
        $order = $this->order();
        $this->pay($order, '80.00');

        $this->actingAs($this->admin)->from(route('orders.edit', $order))->put(route('orders.update', $order), [
            'customer_name' => 'Acme',
            'status' => 'pending',
            'order_date' => now()->toDateString(),
            'items' => [['product_id' => $this->product->id, 'quantity' => 4, 'unit_price' => 25]],
            'discount_type' => 'fixed',
            'discount_value' => 25, // total would drop to 75.00, below the 80.00 paid
        ])->assertSessionHasErrors('total');

        $order->refresh();
        $this->assertSame('100.00', (string) $order->total);
        $this->assertSame('0.00', (string) $order->discount_amount);
        $this->assertSame(96, (int) $this->product->fresh()->stock, 'the rejected edit rolled back its stock moves too');
    }

    public function test_an_order_discount_via_the_api_cannot_cut_below_the_amount_paid(): void
    {
        $order = $this->order();
        $this->pay($order, '80.00');

        $this->assertValidationError('total', fn () => app(OrderService::class)->changeOrderDiscount($order, 'percent', 25));

        // Down to exactly what was paid is fine, and the order becomes paid.
        $order = app(OrderService::class)->changeOrderDiscount($order, 'percent', 20);
        $this->assertSame('80.00', (string) $order->total);
        $this->assertSame(PaymentStatus::PAID, $order->fresh()->payment_status);
    }

    public function test_raising_the_total_moves_a_paid_order_back_to_partial(): void
    {
        $order = $this->order(['discount_type' => 'fixed', 'discount_value' => 20]);
        $this->pay($order, '80.00');
        $this->assertSame(PaymentStatus::PAID, $order->fresh()->payment_status);

        $order = app(OrderService::class)->changeOrderDiscount($order, null, null);

        $this->assertSame(PaymentStatus::PARTIAL, $order->fresh()->payment_status);
        $this->assertSame('20.00', $order->fresh()->balanceDue());
    }

    public function test_activity_log_entries_are_written(): void
    {
        $order = $this->order();
        $payment = $this->pay($order, '25.00', ['reference' => 'CHQ-9', 'method' => 'cheque']);
        $this->payments()->refund($order, $this->admin, ['amount' => '5.00', 'method' => 'cheque']);
        $refund = OrderPayment::where('type', 'refund')->firstOrFail();
        $this->payments()->void($refund, $this->admin, 'bounced');

        $actions = ActivityLog::where('subject_type', Order::class)->where('subject_id', $order->id)
            ->whereIn('action', ['payment_recorded', 'payment_refunded', 'payment_voided'])
            ->orderBy('id')->get();

        $this->assertSame(['payment_recorded', 'payment_refunded', 'payment_voided'], $actions->pluck('action')->all());
        $this->assertSame($this->admin->id, $actions[0]->user_id);
        $this->assertSame('25.00', $actions[0]->properties['amount']);
        $this->assertSame('CHQ-9', $actions[0]->properties['reference']);
        $this->assertSame('bounced', $actions[2]->properties['reason']);
    }

    public function test_webhooks_fire_for_recorded_and_voided_payments(): void
    {
        foreach (['payment.recorded', 'payment.voided'] as $event) {
            Webhook::create([
                'organization_id' => $this->organization->id, 'name' => $event, 'url' => 'https://example.com/hook',
                'secret' => 'shh', 'events' => [$event], 'is_active' => true, 'created_by' => $this->admin->id,
            ]);
        }
        $order = $this->order();
        Queue::fake();

        $payment = $this->pay($order, '10.00');
        $this->payments()->void($payment, $this->admin, 'mistake');

        $deliveries = \App\Models\WebhookDelivery::orderBy('id')->get();
        $this->assertSame(['payment.recorded', 'payment.voided'], $deliveries->pluck('event')->all());
        $this->assertSame('10.00', $deliveries[0]->payload['data']['payment']['amount']);
        $this->assertSame($order->order_number, $deliveries[0]->payload['data']['order']['order_number']);
        $this->assertSame('partial', $deliveries[0]->payload['data']['order']['payment_status']);
        Queue::assertPushed(WebhookDeliveryJob::class, 2);
    }

    public function test_payment_events_are_advertised(): void
    {
        $this->assertContains('payment.recorded', \App\Services\WebhookService::availableEvents());
        $this->assertContains('payment.voided', \App\Services\WebhookService::availableEvents());
    }
}
