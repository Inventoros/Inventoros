<?php

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Inventory\Supplier;
use App\Models\Purchasing\PurchaseOrder;
use App\Models\System\SystemSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseOrderNumberRetryTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_with_number_recovers_from_a_concurrent_number_collision(): void
    {
        SystemSetting::set('installed', true, 'boolean');

        $org = Organization::create([
            'name' => 'RetryOrg', 'email' => 'ro@test.com', 'currency' => 'USD', 'timezone' => 'UTC',
        ]);

        $supplier = Supplier::create([
            'organization_id' => $org->id,
            'name' => 'Retry Supplier',
            'email' => 'supplier@retry.com',
            'is_active' => true,
        ]);

        $today = now()->format('Ymd');

        // Simulate a racing request that commits the same generated number
        // first: the first time a PO is inserted, inject a twin row with an
        // identical po_number inside the transaction so the per-org UNIQUE
        // index trips exactly once. createWithNumber must roll the attempt back
        // and retry with a freshly read number rather than surfacing the 500.
        // Once it has fired, the listener no-ops (benign if it outlives the
        // test, since $collided stays true).
        $collided = false;
        PurchaseOrder::creating(function (PurchaseOrder $po) use (&$collided) {
            if ($collided) {
                return;
            }
            $collided = true;
            PurchaseOrder::withoutEvents(fn () => PurchaseOrder::create($po->getAttributes()));
        });

        $po = PurchaseOrder::createWithNumber($org->id, fn (string $poNumber) => PurchaseOrder::create([
            'organization_id' => $org->id,
            'supplier_id' => $supplier->id,
            'po_number' => $poNumber,
            'status' => PurchaseOrder::STATUS_DRAFT,
            'order_date' => now(),
            'subtotal' => 0,
            'tax' => 0,
            'shipping' => 0,
            'total' => 0,
            'currency' => 'USD',
        ]));

        $this->assertTrue($collided, 'Expected the injected twin to force a UNIQUE collision.');
        $this->assertNotNull($po->id);
        $this->assertStringStartsWith("PO-{$today}-", $po->po_number);

        // Exactly one PO survives — the twin was rolled back with the failed
        // attempt, so the retry did not leave a phantom row behind.
        $this->assertSame(1, PurchaseOrder::count());
    }

    public function test_po_number_generation_accounts_for_soft_deleted_purchase_orders(): void
    {
        SystemSetting::set('installed', true, 'boolean');

        $org = Organization::create([
            'name' => 'SoftOrg', 'email' => 'so@test.com', 'currency' => 'USD', 'timezone' => 'UTC',
        ]);
        $supplier = Supplier::create([
            'organization_id' => $org->id, 'name' => 'S', 'email' => 's@x.com', 'is_active' => true,
        ]);

        $today = now()->format('Ymd');

        // Soft-delete the highest-numbered PO of the day. The unique index
        // still counts the trashed row, so a generator reading MAX() through
        // the default scope regenerates the same number forever.
        $po = PurchaseOrder::create([
            'organization_id' => $org->id, 'supplier_id' => $supplier->id,
            'po_number' => "PO-{$today}-0001", 'status' => PurchaseOrder::STATUS_DRAFT,
            'order_date' => now(), 'subtotal' => 0, 'tax' => 0, 'shipping' => 0, 'total' => 0, 'currency' => 'USD',
        ]);
        $po->delete();

        $new = PurchaseOrder::createWithNumber($org->id, fn (string $poNumber) => PurchaseOrder::create([
            'organization_id' => $org->id, 'supplier_id' => $supplier->id,
            'po_number' => $poNumber, 'status' => PurchaseOrder::STATUS_DRAFT,
            'order_date' => now(), 'subtotal' => 0, 'tax' => 0, 'shipping' => 0, 'total' => 0, 'currency' => 'USD',
        ]));

        $this->assertNotSame("PO-{$today}-0001", $new->po_number);
    }
}
