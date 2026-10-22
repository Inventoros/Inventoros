<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Order\Order;
use App\Models\Order\ReturnOrder;
use App\Models\Order\ReturnOrderItem;
use App\Services\ReturnOrderService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Portal\BuildsPortalFixtures;
use Tests\TestCase;

final class ReturnRefundRoundingTest extends TestCase
{
    use BuildsPortalFixtures, RefreshDatabase;

    private function order(int $quantity = 3, string $paid = '2.00'): Order
    {
        $this->markInstalled();
        $org = $this->makeOrganization('Refund rounding');
        $customer = $this->makeCustomer($org, 'Buyer');
        $order = $this->makeOrder($customer, 'ROUND-ORDER', OrderStatus::DELIVERED, [
            ['product' => $this->makeProduct($org, 'ROUND-SKU'), 'quantity' => $quantity, 'unit_price' => 1],
        ]);
        $order->forceFill(['subtotal' => $quantity, 'discount_amount' => Money::subtract($quantity, $paid), 'total' => $paid])->save();

        return $order->fresh()->load('items');
    }

    private function createReturn(Order $order, array $lines): ReturnOrder
    {
        return app(ReturnOrderService::class)->create($order->organization_id, null, [
            'order_id' => $order->id, 'type' => 'return', 'reason' => 'Refund rounding',
            'items' => array_map(fn ($line) => ['order_item_id' => $line[0]->id, 'quantity' => $line[1], 'condition' => 'new', 'restock' => false], $lines),
        ]);
    }

    public function test_order_discount_is_allocated_without_creating_an_extra_cent(): void
    {
        $order = $this->order(1, '1.00');
        $product = $this->makeProduct($order->organization, 'SECOND-SKU');
        $order->items()->create(['product_id' => $product->id, 'product_name' => $product->name, 'sku' => $product->sku,
            'quantity' => 1, 'unit_price' => 1, 'subtotal' => 1, 'total' => 1, 'tax' => 0]);
        $order->forceFill(['subtotal' => 2, 'discount_amount' => '0.01', 'total' => '1.99'])->save();
        $order->load('items');
        $service = app(ReturnOrderService::class);
        $nets = $service->paidLineNets($order);
        $this->assertSame('1.99', Money::add(...array_values($nets)));
        $order->setRelation('items', $order->items->reverse());
        $this->assertSame($nets, $service->paidLineNets($order));
        $return = $this->createReturn($order, $order->items->map(fn ($line) => [$line, 1])->all());
        $this->assertSame('1.99', $return->refund_amount);
    }

    public function test_partial_refunds_use_cumulative_entitlement_and_record_line_amounts(): void
    {
        $order = $this->order();
        $line = $order->items->first();
        $refunds = [];
        foreach (range(1, 3) as $attempt) {
            $return = $this->createReturn($order, [[$line, 1]]);
            $refunds[] = $return->refund_amount;
            $this->assertSame($return->refund_amount, $return->items()->sole()->refund_amount);
        }
        $this->assertSame(['0.67', '0.66', '0.67'], $refunds);
        $this->assertSame('2.00', Money::add(...$refunds));
    }

    public function test_repeated_line_rows_do_not_each_claim_the_same_rounding_cent(): void
    {
        $order = $this->order();
        $line = $order->items->first();
        $return = $this->createReturn($order, [[$line, 1], [$line, 1]]);
        $this->assertSame('1.33', $return->refund_amount);
        $this->assertSame(['0.67', '0.66'], $return->items()->orderBy('id')->pluck('refund_amount')->all());
    }

    public static function rejectedPositions(): array
    {
        return [[0], [1]];
    }

    #[DataProvider('rejectedPositions')]
    public function test_rejecting_an_earlier_return_releases_its_actual_cents(int $position): void
    {
        $order = $this->order();
        $line = $order->items->first();
        $returns = [$this->createReturn($order, [[$line, 1]]), $this->createReturn($order, [[$line, 1]])];
        $admin = $this->makeStaff($order->organization, 'reject@round.test');
        app(ReturnOrderService::class)->reject($returns[$position], $admin, 'Request withdrawn');
        $this->createReturn($order, [[$line, 1]]);
        $this->createReturn($order, [[$line, 1]]);
        $this->assertSame('2.00', Money::add(...ReturnOrder::where('status', '!=', 'rejected')->pluck('refund_amount')->all()));
        $this->assertSame(3, (int) app(ReturnOrderService::class)->returnedQuantities($order)->sum());
    }

    public function test_old_partial_refunds_are_counted_by_their_recorded_header_amount(): void
    {
        $order = $this->order();
        $line = $order->items->first();
        foreach (range(1, 2) as $attempt) {
            $old = ReturnOrder::create(['organization_id' => $order->organization_id, 'order_id' => $order->id,
                'return_number' => ReturnOrder::generateReturnNumber($order->organization_id), 'type' => 'return',
                'status' => 'pending', 'reason' => 'Legacy rounding', 'refund_amount' => '0.67']);
            $old->items()->create(['order_item_id' => $line->id, 'product_id' => $line->product_id,
                'quantity' => 1, 'condition' => 'new', 'restock' => false]);
        }
        $return = $this->createReturn($order, [[$line, 1]]);
        $this->assertSame('0.66', $return->refund_amount);
        $this->assertSame('2.00', Money::add(...ReturnOrder::pluck('refund_amount')->all()));
        $this->assertSame(2, ReturnOrderItem::whereNull('refund_amount')->count());
    }

    public function test_order_balance_caps_a_new_refund_even_if_legacy_line_amounts_disagree(): void
    {
        $order = $this->order();
        $line = $order->items->first();
        $old = ReturnOrder::create(['organization_id' => $order->organization_id, 'order_id' => $order->id,
            'return_number' => ReturnOrder::generateReturnNumber($order->organization_id), 'type' => 'return',
            'status' => 'pending', 'reason' => 'Old edited record', 'refund_amount' => '1.80']);
        $old->items()->create(['order_item_id' => $line->id, 'product_id' => $line->product_id,
            'quantity' => 1, 'condition' => 'new', 'restock' => false, 'refund_amount' => '0.67']);
        $return = $this->createReturn($order, [[$line, 1]]);
        $this->assertSame('0.20', $return->refund_amount);
        $this->assertSame('0.20', $return->items()->sole()->refund_amount);
        $this->assertSame('1.80', $old->fresh()->refund_amount);
    }

    public static function amountsAndQuantities(): array
    {
        return [[3, '2.00'], [7, '1.00'], [8, '0.01'], [2, '0.01'], [3, '0.00']];
    }

    #[DataProvider('amountsAndQuantities')]
    public function test_returning_all_units_refunds_exactly_the_paid_goods_amount(int $quantity, string $paid): void
    {
        $order = $this->order($quantity, $paid);
        $line = $order->items->first();
        $total = '0.00';
        foreach (range(1, $quantity) as $index) {
            $return = $this->createReturn($order, [[$line, 1]]);
            $this->assertGreaterThanOrEqual(0, Money::compare($return->refund_amount, '0'));
            $total = Money::add($total, $return->refund_amount);
            $this->assertLessThanOrEqual(0, Money::compare($total, $paid));
        }
        $this->assertSame($paid, $total);
    }

    public function test_legacy_recorded_cents_are_not_lost_when_unit_weights_are_below_one_cent(): void
    {
        $order = $this->order(8, '0.01');
        $line = $order->items->first();
        $old = ReturnOrder::create(['organization_id' => $order->organization_id, 'order_id' => $order->id,
            'return_number' => ReturnOrder::generateReturnNumber($order->organization_id), 'type' => 'return',
            'status' => 'pending', 'reason' => 'Legacy rounding', 'refund_amount' => '0.01']);
        $old->items()->create(['order_item_id' => $line->id, 'product_id' => $line->product_id,
            'quantity' => 1, 'condition' => 'new', 'restock' => false]);
        $service = app(ReturnOrderService::class);
        $this->assertSame('0.01', $service->refundedLineAmounts($order, $service->paidLineNets($order))[$line->id]);
        $this->assertSame('0.00', $this->createReturn($order, [[$line, 7]])->refund_amount);
    }

    public function test_service_rejects_negative_quantities_before_reserving_refunds(): void
    {
        $order = $this->order();
        try {
            $this->createReturn($order, [[$order->items->first(), -1]]);
            $this->fail('A negative return quantity was accepted.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('items.0.quantity', $e->errors());
        }
        $this->assertSame(0, ReturnOrder::count());
    }

    public function test_rejecting_a_mixed_line_return_keeps_each_lines_remaining_cents(): void
    {
        $order = $this->order(3, '3.00');
        $product = $this->makeProduct($order->organization, 'MIXED-SKU');
        $order->items()->create(['product_id' => $product->id, 'product_name' => $product->name, 'sku' => $product->sku,
            'quantity' => 2, 'unit_price' => 1, 'subtotal' => 2, 'total' => 2, 'tax' => 0]);
        $order->forceFill(['subtotal' => 5, 'discount_amount' => '1.67', 'total' => '3.33'])->save();
        $order->load(['items' => fn ($query) => $query->orderBy('id')]);
        [$a, $b] = [$order->items[0], $order->items[1]];
        $first = $this->createReturn($order, [[$a, 1], [$b, 1]]);
        $second = $this->createReturn($order, [[$a, 1], [$b, 1]]);
        $admin = $this->makeStaff($order->organization, 'mixed@round.test');
        app(ReturnOrderService::class)->reject($first, $admin, 'Withdrawn');
        $last = $this->createReturn($order, [[$a, 2], [$b, 1]]);
        $this->assertSame('3.33', Money::add($second->refund_amount, $last->refund_amount));
        $amounts = app(ReturnOrderService::class)->refundedLineAmounts($order, app(ReturnOrderService::class)->paidLineNets($order));
        $this->assertSame('2.00', $amounts[$a->id]);
        $this->assertSame('1.33', $amounts[$b->id]);
    }
}
