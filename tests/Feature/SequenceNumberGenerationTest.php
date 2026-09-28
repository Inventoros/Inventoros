<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductLocation;
use App\Models\Inventory\StockTransfer;
use App\Models\Inventory\WorkOrder;
use App\Models\Order\Order;
use App\Models\Order\ReturnOrder;
use App\Models\Purchasing\PurchaseOrder;
use App\Models\Inventory\Supplier;
use App\Models\User;
use App\Support\SequenceNumber;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Every per-day document number (orders, returns, transfers, POs, work
 * orders) must keep counting past 9,999 in a day. The generators used to take
 * the last four characters of the text-highest number, so after ...-9999 the
 * next was ...-10000 and then ...-10000 again forever: every create in that
 * tenant failed on the unique index until midnight.
 */
class SequenceNumberGenerationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Organization $otherOrg;

    private User $user;

    private Product $product;

    private Order $order;

    private ProductLocation $from;

    private ProductLocation $to;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'Seq Org', 'email' => 'seq@test.com', 'currency' => 'USD', 'timezone' => 'UTC']);
        $this->otherOrg = Organization::create(['name' => 'Other Org', 'email' => 'other@test.com', 'currency' => 'USD', 'timezone' => 'UTC']);
        $this->user = User::create([
            'name' => 'Admin', 'email' => 'seq-admin@test.com', 'password' => bcrypt('x'),
            'organization_id' => $this->org->id, 'role' => 'admin',
        ]);
        $this->product = Product::create([
            'organization_id' => $this->org->id, 'sku' => 'SEQ-1', 'name' => 'Seq product',
            'price' => 10, 'currency' => 'USD', 'stock' => 10, 'min_stock' => 0, 'is_active' => true,
        ]);
        $this->order = $this->makeOrder($this->org->id, 'ORD-SEED-1');
        $this->from = ProductLocation::create(['organization_id' => $this->org->id, 'name' => 'A', 'code' => 'SEQ-A', 'is_active' => true]);
        $this->to = ProductLocation::create(['organization_id' => $this->org->id, 'name' => 'B', 'code' => 'SEQ-B', 'is_active' => true]);
        $this->supplier = Supplier::create(['organization_id' => $this->org->id, 'name' => 'Seq supplier', 'code' => 'SEQ-SUP', 'is_active' => true]);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function sequences(): array
    {
        return [
            'order' => ['order'],
            'return' => ['return'],
            'transfer' => ['transfer'],
            'purchase order' => ['purchase_order'],
            'work order' => ['work_order'],
        ];
    }

    #[DataProvider('sequences')]
    public function test_the_first_number_of_the_day_is_0001(string $kind): void
    {
        $this->assertSame($this->prefix($kind).'0001', $this->generate($kind));
    }

    #[DataProvider('sequences')]
    public function test_the_number_after_9999_is_10000(string $kind): void
    {
        $this->seedRow($kind, $this->org->id, $this->prefix($kind).'9998');
        $this->seedRow($kind, $this->org->id, $this->prefix($kind).'9999');

        $this->assertSame($this->prefix($kind).'10000', $this->generate($kind));
    }

    #[DataProvider('sequences')]
    public function test_numbering_keeps_counting_past_10000(string $kind): void
    {
        // Text order puts 9999 above 10000 and 10001; the generator must not.
        $this->seedRow($kind, $this->org->id, $this->prefix($kind).'9999');
        $this->seedRow($kind, $this->org->id, $this->prefix($kind).'10000');
        $this->seedRow($kind, $this->org->id, $this->prefix($kind).'10001');

        $this->assertSame($this->prefix($kind).'10002', $this->generate($kind));
    }

    #[DataProvider('sequences')]
    public function test_another_tenants_numbers_are_ignored(string $kind): void
    {
        $this->seedRow($kind, $this->org->id, $this->prefix($kind).'0004');
        $this->seedRow($kind, $this->otherOrg->id, $this->prefix($kind).'0500');

        $this->assertSame($this->prefix($kind).'0005', $this->generate($kind));
    }

    public function test_soft_deleted_rows_still_count(): void
    {
        $prefix = $this->prefix('order');
        $this->seedRow('order', $this->org->id, $prefix.'9999');
        $this->makeOrder($this->org->id, $prefix.'10000')->delete();

        $this->assertSame($prefix.'10001', $this->generate('order'));

        PurchaseOrder::withoutGlobalScopes()->whereKey($this->seedRow('purchase_order', $this->org->id, $this->prefix('purchase_order').'10000'))->delete();
        $this->assertSame($this->prefix('purchase_order').'10001', $this->generate('purchase_order'));
    }

    public function test_suffix_is_the_part_after_the_last_dash(): void
    {
        $this->assertSame(10000, SequenceNumber::suffix('ORD-20260928-10000'));
        $this->assertSame(7, SequenceNumber::suffix('WO-20260928-0007'));
        $this->assertSame(42, SequenceNumber::suffix('42'));
    }

    private function prefix(string $kind): string
    {
        $date = now()->format('Ymd');

        return match ($kind) {
            'order' => "ORD-{$date}-",
            'return' => "RMA-{$date}-",
            'transfer' => "ST-{$date}-",
            'purchase_order' => "PO-{$date}-",
            'work_order' => "WO-{$date}-",
        };
    }

    private function generate(string $kind): string
    {
        $orgId = $this->org->id;

        return match ($kind) {
            'order' => Order::generateOrderNumber($orgId),
            'return' => ReturnOrder::generateReturnNumber($orgId),
            'transfer' => StockTransfer::generateTransferNumber($orgId),
            'purchase_order' => PurchaseOrder::generatePONumber($orgId),
            'work_order' => WorkOrder::generateWorkOrderNumber($orgId),
        };
    }

    /**
     * Insert a row holding $number and return its id.
     */
    private function seedRow(string $kind, int $orgId, string $number): int
    {
        $now = now();
        $base = ['organization_id' => $orgId, 'created_at' => $now, 'updated_at' => $now];

        $row = match ($kind) {
            'order' => fn () => $this->makeOrder($orgId, $number)->id,
            'return' => fn () => DB::table('return_orders')->insertGetId($base + [
                'order_id' => $this->order->id, 'return_number' => $number, 'reason' => 'Test',
            ]),
            'transfer' => fn () => DB::table('stock_transfers')->insertGetId($base + [
                'transfer_number' => $number, 'from_location_id' => $this->from->id,
                'to_location_id' => $this->to->id, 'transferred_by' => $this->user->id,
            ]),
            'purchase_order' => fn () => DB::table('purchase_orders')->insertGetId($base + [
                'supplier_id' => $this->supplier->id, 'po_number' => $number, 'order_date' => $now->toDateString(),
            ]),
            'work_order' => fn () => DB::table('work_orders')->insertGetId($base + [
                'product_id' => $this->product->id, 'created_by' => $this->user->id, 'work_order_number' => $number,
            ]),
        };

        /** @var Closure(): int $row */
        return (int) $row();
    }

    private function makeOrder(int $orgId, string $number): Order
    {
        return Order::withoutGlobalScopes()->create([
            'organization_id' => $orgId,
            'order_number' => $number,
            'source' => 'manual',
            'customer_name' => 'Seq customer',
            'status' => 'pending',
            'subtotal' => 0, 'tax' => 0, 'shipping' => 0, 'total' => 0,
            'currency' => 'USD',
            'order_date' => now(),
        ]);
    }
}
