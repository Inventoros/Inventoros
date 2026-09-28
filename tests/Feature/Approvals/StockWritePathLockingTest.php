<?php

declare(strict_types=1);

namespace Tests\Feature\Approvals;

use App\Exceptions\ApprovalException;
use App\Mcp\Servers\InventorosServer;
use App\Mcp\Tools\ReceivePurchaseOrderTool;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductLocationStock;
use App\Models\Inventory\StockTransfer;
use App\Models\Purchasing\PurchaseOrder;
use App\Services\StockTransferService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Stock write paths that ran outside the shared locking conventions:
 * the MCP purchase-order receive hand-rolled an unlocked loop, transfer
 * completion trusted an approval status read before the lock and locked
 * products in line order, and a new transfer was committed before it was
 * held for approval.
 */
final class StockWritePathLockingTest extends TestCase
{
    use ApprovalFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpApprovalWorld();
    }

    public function test_mcp_receive_rechecks_the_purchase_order_under_the_lock(): void
    {
        $po = $this->draftPo();
        $po->forceFill(['status' => PurchaseOrder::STATUS_SENT])->save();
        $item = $po->items()->sole();

        // The PO is cancelled by another request after the tool's up-front
        // status check but before it books stock. PurchaseOrderService
        // re-reads the PO under a row lock inside the receiving transaction
        // and refuses; the hand-rolled MCP loop trusted the early read.
        $cancelled = false;
        DB::listen(function (QueryExecuted $query) use (&$cancelled, $po) {
            if (! $cancelled && str_contains($query->sql, 'from "purchase_order_items"')) {
                $cancelled = true;
                DB::table('purchase_orders')->where('id', $po->id)->update(['status' => PurchaseOrder::STATUS_CANCELLED]);
            }
        });

        InventorosServer::actingAs($this->admin)
            ->tool(ReceivePurchaseOrderTool::class, [
                'id' => $po->id,
                'items' => [['id' => $item->id, 'quantity_to_receive' => 4]],
            ])
            ->assertHasErrors();

        $this->assertTrue($cancelled);
        $this->assertSame(100, (int) $this->product->fresh()->stock);
        $this->assertSame(0, (int) $item->fresh()->quantity_received);
    }

    public function test_mcp_receive_still_books_the_goods(): void
    {
        $po = $this->draftPo();
        $po->forceFill(['status' => PurchaseOrder::STATUS_SENT])->save();
        $item = $po->items()->sole();

        InventorosServer::actingAs($this->admin)
            ->tool(ReceivePurchaseOrderTool::class, [
                'id' => $po->id,
                'items' => [['id' => $item->id, 'quantity_to_receive' => 4]],
            ])
            ->assertOk();

        $this->assertSame(104, (int) $this->product->fresh()->stock);
        $this->assertSame(PurchaseOrder::STATUS_PARTIAL, $po->fresh()->status);
    }

    private function transfer(array $items): StockTransfer
    {
        return app(StockTransferService::class)->create($this->org->id, $this->admin, [
            'from_location_id' => $this->locationA->id,
            'to_location_id' => $this->locationB->id,
            'items' => $items,
        ]);
    }

    public function test_completion_rechecks_the_approval_hold_under_the_lock(): void
    {
        $transfer = $this->transfer([['product_id' => $this->product->id, 'quantity' => 10]]);

        // Another request put the transfer on hold after this instance was
        // read: the in-memory copy still says "no approval needed".
        StockTransfer::whereKey($transfer->id)->update(['approval_status' => StockTransfer::APPROVAL_PENDING]);
        $this->assertNull($transfer->approval_status);

        try {
            app(StockTransferService::class)->complete($transfer, $this->admin);
            $this->fail('A transfer on hold must not complete.');
        } catch (ApprovalException) {
            // expected
        }

        $this->assertSame('pending', $transfer->fresh()->status);
        $this->assertSame(0, (int) ProductLocationStock::where('location_id', $this->locationB->id)->sum('quantity'));
    }

    public function test_completion_locks_products_in_id_order(): void
    {
        $second = Product::create([
            'organization_id' => $this->org->id, 'sku' => 'N-1', 'name' => 'Nut',
            'price' => 1, 'currency' => 'USD', 'stock' => 50, 'min_stock' => 0,
            'is_active' => true, 'location_id' => $this->locationA->id,
        ]);
        $this->assertGreaterThan($this->product->id, $second->id);

        // Lines listed highest id first.
        $transfer = $this->transfer([
            ['product_id' => $second->id, 'quantity' => 5],
            ['product_id' => $this->product->id, 'quantity' => 5],
        ]);

        $productIds = [$this->product->id, $second->id];
        $touched = [];
        DB::listen(function (QueryExecuted $query) use (&$touched, $productIds) {
            if (! preg_match('/from "products" where/i', $query->sql)) {
                return;
            }
            foreach ($query->bindings as $binding) {
                if (in_array((int) $binding, $productIds, true) && ! in_array((int) $binding, $touched, true)) {
                    $touched[] = (int) $binding;
                }
            }
        });

        app(StockTransferService::class)->complete($transfer, $this->admin);

        $this->assertSame([$this->product->id, $second->id], $touched);
        $this->assertSame('completed', $transfer->fresh()->status);
    }

    public function test_a_transfer_is_held_inside_the_transaction_that_creates_it(): void
    {
        $this->enableApprovals(['stock_transfers_enabled' => true]);

        // Make the hold itself fail.
        StockTransfer::updating(function (StockTransfer $transfer) {
            if ($transfer->isDirty('approval_status')) {
                throw new \RuntimeException('hold failed');
            }
        });

        try {
            $this->transfer([['product_id' => $this->product->id, 'quantity' => 10]]);
            $this->fail('The hold failure should surface.');
        } catch (\RuntimeException $e) {
            $this->assertSame('hold failed', $e->getMessage());
        }

        // No transfer is left behind un-held: it rolled back with the hold.
        $this->assertSame(0, StockTransfer::count());
    }
}
