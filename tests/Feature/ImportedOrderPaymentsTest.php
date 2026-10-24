<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PaymentStatus;
use App\Models\ActivityLog;
use App\Models\Auth\Organization;
use App\Models\Order\Order;
use App\Models\User;
use App\Services\OrderPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class ImportedOrderPaymentsTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actor = User::factory()->create(['organization_id' => Organization::factory()->create()->id]);
        $this->order = Order::create(['organization_id' => $this->actor->organization_id,
            'order_number' => 'REMOTE-1', 'status' => 'cancelled', 'order_date' => '2026-09-01',
            'customer_name' => 'Customer', 'currency' => 'USD', 'subtotal' => '100', 'total' => '100']);
    }

    private function data(array $overrides = []): array
    {
        return array_replace(['source' => 'example', 'type' => 'payment', 'amount' => '100.00', 'method' => 'other',
            'reference' => 'example:txn-1', 'paid_at' => '2026-09-01T12:00:00Z'], $overrides);
    }

    public function test_cancelled_order_history_records_receipts_refunds_and_an_audit_trail(): void
    {
        $service = app(OrderPaymentService::class);
        $receipt = $service->recordImported($this->order, $this->actor, $this->data());
        $refund = $service->recordImported($this->order, $this->actor, $this->data(['type' => 'refund', 'reference' => 'example:txn-2']));
        $this->assertSame('100.00', $receipt->amount);
        $this->assertSame('refund', $refund->type->value);
        $this->assertSame('0.00', $this->order->fresh()->amount_paid);
        $this->assertSame(PaymentStatus::REFUNDED, $this->order->fresh()->payment_status);
        $this->assertSame('cancelled', $this->order->fresh()->status->value);
        $logs = ActivityLog::where('action', 'payment_imported')->orderBy('id')->get();
        $this->assertCount(2, $logs);
        $this->assertSame('example', $logs[0]->properties['source']);
        $this->assertSame($this->actor->id, $logs[0]->user_id);
    }

    public function test_replays_are_idempotent_and_manual_voids_stay_voided(): void
    {
        $service = app(OrderPaymentService::class);
        $payment = $service->recordImported($this->order, $this->actor, $this->data());
        $this->assertSame($payment->id, $service->recordImported($this->order, $this->actor, $this->data())->id);
        $service->void($payment, $this->actor, 'Reviewed correction');
        $replayed = $service->recordImported($this->order, $this->actor, $this->data());
        $this->assertTrue($replayed->isVoided());
        $this->assertSame('0.00', $this->order->fresh()->amount_paid);
        $this->assertSame(1, $this->order->payments()->count());
    }

    public function test_conflicting_references_and_excess_refunds_fail_without_changing_the_ledger(): void
    {
        $service = app(OrderPaymentService::class);
        $service->recordImported($this->order, $this->actor, $this->data());
        foreach ([['amount' => '90.00'], ['type' => 'refund'], ['method' => 'cash'], ['type' => 'refund', 'reference' => 'example:too-much', 'amount' => '100.01']] as $change) {
            try {
                $service->recordImported($this->order, $this->actor, $this->data($change));
                $this->fail('Expected validation failure.');
            } catch (ValidationException) {
                $this->assertSame(1, $this->order->payments()->count());
                $this->assertSame('100.00', $this->order->fresh()->amount_paid);
            }
        }
    }

    public function test_actual_overpayments_are_preserved_and_references_are_case_sensitive(): void
    {
        $service = app(OrderPaymentService::class);
        $service->recordImported($this->order, $this->actor, $this->data(['reference' => 'example:Txn', 'amount' => '150.00']));
        $service->recordImported($this->order, $this->actor, $this->data(['reference' => 'example:txn', 'amount' => '0.01']));
        $this->assertSame('150.01', $this->order->fresh()->amount_paid);
        $this->assertSame(PaymentStatus::OVERPAID, $this->order->fresh()->payment_status);
        $this->assertSame(2, $this->order->payments()->count());
    }

    public function test_history_is_silent_and_explicit_announcements_fire_only_once(): void
    {
        $announcements = 0;
        add_action('payment_recorded', function () use (&$announcements) {
            $announcements++;
        });
        $service = app(OrderPaymentService::class);
        $service->recordImported($this->order, $this->actor, $this->data());
        $this->assertSame(0, $announcements);
        $data = $this->data(['reference' => 'example:txn-new', 'amount' => '1']);
        $service->recordImported($this->order, $this->actor, $data, announce: true);
        $service->recordImported($this->order, $this->actor, $data, announce: true);
        $this->assertSame(1, $announcements);
    }

    public function test_cross_organization_actors_and_invalid_amounts_are_rejected(): void
    {
        $other = User::factory()->create(['organization_id' => Organization::factory()->create()->id]);
        try {
            app(OrderPaymentService::class)->recordImported($this->order, $other, $this->data());
            $this->fail('Expected organization refusal.');
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        foreach (['-1', '0', '0.001', 'not-a-number'] as $amount) {
            try {
                app(OrderPaymentService::class)->recordImported($this->order, $this->actor, $this->data(['amount' => $amount]));
                $this->fail('Expected amount refusal.');
            } catch (ValidationException) {
                $this->assertSame(0, $this->order->payments()->count());
            }
        }
    }

    public function test_rolled_back_imports_never_announce_or_leave_a_payment(): void
    {
        $announced = 0;
        add_action('payment_recorded', function () use (&$announced) {
            $announced++;
        });
        try {
            DB::transaction(function () {
                app(OrderPaymentService::class)->recordImported($this->order, $this->actor, $this->data(), announce: true);
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException $exception) {
            $this->assertSame('rollback', $exception->getMessage());
        }
        $this->assertSame(0, $announced);
        $this->assertSame(0, $this->order->payments()->count());
    }
}
