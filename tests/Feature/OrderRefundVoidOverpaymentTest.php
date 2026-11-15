<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PaymentStatus;
use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Order\Order;
use App\Models\Order\OrderPayment;
use App\Models\System\SystemSetting;
use App\Models\User;
use App\Services\OrderPaymentService;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Voiding a refund puts its money back on the order. Like recording a
 * payment, it may not leave the order paid more than its total: an
 * overpayment is only ever recorded on purpose, as a payment with the
 * explicit allowance.
 */
final class OrderRefundVoidOverpaymentTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $admin;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        SystemSetting::set('installed', true, 'boolean');

        $this->organization = Organization::create([
            'name' => 'Void Org', 'email' => 'void@org.test', 'currency' => 'USD', 'timezone' => 'UTC',
        ]);
        $this->product = Product::create([
            'organization_id' => $this->organization->id, 'sku' => 'VOID-1', 'name' => 'Thing',
            'price' => 25.00, 'currency' => 'USD', 'stock' => 100, 'min_stock' => 0, 'is_active' => true,
        ]);
        $this->admin = User::create([
            'name' => 'Admin', 'email' => 'admin@void.test', 'password' => bcrypt('password'),
            'organization_id' => $this->organization->id, 'role' => 'admin',
        ]);
    }

    /** An order with a 100.00 total. */
    private function order(): Order
    {
        return app(OrderService::class)->create([
            'customer_name' => 'Acme', 'status' => 'pending', 'order_date' => now()->toDateString(),
            'items' => [['product_id' => $this->product->id, 'quantity' => 4, 'unit_price' => 25.00]],
        ], $this->admin);
    }

    private function payments(): OrderPaymentService
    {
        return app(OrderPaymentService::class);
    }

    /**
     * Paid in full, half refunded, then the total cut to 60.00 (allowed: it
     * is still above the 50.00 paid). Voiding the refund would make it 100.00
     * paid against a 60.00 total.
     *
     * @return array{0: Order, 1: OrderPayment}
     */
    private function refundedThenCutOrder(): array
    {
        $order = $this->order();
        $this->payments()->record($order, $this->admin, ['amount' => '100.00', 'method' => 'card']);
        $refund = $this->payments()->refund($order, $this->admin, ['amount' => '50.00', 'method' => 'card']);
        $order = app(OrderService::class)->changeOrderDiscount($order->fresh(), 'fixed', 40);
        $this->assertSame('60.00', (string) $order->fresh()->total);
        $this->assertSame('50.00', (string) $order->fresh()->amount_paid);

        return [$order, $refund];
    }

    public function test_voiding_a_refund_that_would_overpay_the_order_is_refused(): void
    {
        [$order, $refund] = $this->refundedThenCutOrder();

        try {
            $this->payments()->void($refund, $this->admin, 'mistake');
            $this->fail('Voiding the refund should be refused: it would overpay the order.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('payment', $e->errors());
        }

        $this->assertNull($refund->fresh()->voided_at);
        $fresh = $order->fresh();
        $this->assertSame('50.00', (string) $fresh->amount_paid);
        $this->assertNotSame(PaymentStatus::OVERPAID, $fresh->payment_status);
    }

    public function test_the_api_refuses_it_too(): void
    {
        [$order, $refund] = $this->refundedThenCutOrder();
        Sanctum::actingAs($this->admin, ['*']);

        $this->postJson("/api/v1/orders/{$order->id}/payments/{$refund->id}/void", ['reason' => 'mistake'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('payment');

        $this->assertNull($refund->fresh()->voided_at);
        $this->assertSame('50.00', (string) $order->fresh()->amount_paid);
    }

    public function test_the_web_refuses_it_too(): void
    {
        [$order, $refund] = $this->refundedThenCutOrder();

        $this->actingAs($this->admin)
            ->from(route('orders.show', $order))
            ->post(route('orders.payments.void', [$order, $refund]), ['reason' => 'mistake'])
            ->assertSessionHasErrors('payment');

        $this->assertNull($refund->fresh()->voided_at);
    }

    public function test_voiding_a_refund_up_to_the_total_is_allowed(): void
    {
        $order = $this->order();
        $this->payments()->record($order, $this->admin, ['amount' => '100.00', 'method' => 'card']);
        $refund = $this->payments()->refund($order, $this->admin, ['amount' => '30.00', 'method' => 'card']);

        $this->payments()->void($refund, $this->admin, 'issued by mistake');

        $fresh = $order->fresh();
        $this->assertSame('100.00', (string) $fresh->amount_paid);
        $this->assertSame(PaymentStatus::PAID, $fresh->payment_status);
        $this->assertNotNull($refund->fresh()->voided_at);
    }

    /**
     * Deliberately overpaid (120.00 against 100.00, recorded with the
     * allowance), then a 20.00 refund recorded by mistake.
     *
     * @return array{0: Order, 1: OrderPayment}
     */
    private function overpaidOrderWithMistakenRefund(): array
    {
        $order = $this->order();
        $this->payments()->record($order, $this->admin, ['amount' => '120.00', 'method' => 'card', 'allow_overpayment' => true]);
        $refund = $this->payments()->refund($order, $this->admin, ['amount' => '20.00', 'method' => 'card']);
        $this->assertSame('100.00', (string) $order->fresh()->amount_paid);

        return [$order, $refund];
    }

    public function test_voiding_a_refund_on_a_deliberately_overpaid_order_needs_the_explicit_allowance(): void
    {
        [$order, $refund] = $this->overpaidOrderWithMistakenRefund();

        try {
            $this->payments()->void($refund, $this->admin, 'recorded by mistake');
            $this->fail('Voiding the refund should need the overpayment allowance.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('overpayment', $e->errors()['payment'][0]);
        }
        $this->assertNull($refund->fresh()->voided_at);

        $this->payments()->void($refund->fresh(), $this->admin, 'recorded by mistake', allowOverpayment: true);

        $fresh = $order->fresh();
        $this->assertNotNull($refund->fresh()->voided_at);
        $this->assertSame('120.00', (string) $fresh->amount_paid);
        $this->assertSame(PaymentStatus::OVERPAID, $fresh->payment_status);
    }

    public function test_the_api_takes_the_allowance_on_void(): void
    {
        [$order, $refund] = $this->overpaidOrderWithMistakenRefund();
        Sanctum::actingAs($this->admin, ['*']);

        $this->postJson("/api/v1/orders/{$order->id}/payments/{$refund->id}/void", ['reason' => 'mistake'])
            ->assertStatus(422);
        $this->postJson("/api/v1/orders/{$order->id}/payments/{$refund->id}/void", ['reason' => 'mistake', 'allow_overpayment' => true])
            ->assertOk();

        $this->assertNotNull($refund->fresh()->voided_at);
        $this->assertSame('120.00', (string) $order->fresh()->amount_paid);
    }

    public function test_the_web_takes_the_allowance_on_void(): void
    {
        [$order, $refund] = $this->overpaidOrderWithMistakenRefund();

        $this->actingAs($this->admin)
            ->from(route('orders.show', $order))
            ->post(route('orders.payments.void', [$order, $refund]), ['reason' => 'mistake'])
            ->assertSessionHasErrors('payment');

        $this->actingAs($this->admin)
            ->post(route('orders.payments.void', [$order, $refund]), ['reason' => 'mistake', 'allow_overpayment' => '1'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('orders.show', $order));

        $this->assertNotNull($refund->fresh()->voided_at);
    }

    public function test_voiding_payments_is_unaffected(): void
    {
        [$order] = $this->refundedThenCutOrder();
        $extra = $this->payments()->record($order->fresh(), $this->admin, ['amount' => '10.00', 'method' => 'cash']);

        $this->payments()->void($extra, $this->admin, null);

        $this->assertSame('50.00', (string) $order->fresh()->amount_paid);
    }
}
