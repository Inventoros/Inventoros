<?php

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductCategory;
use App\Models\Inventory\ProductLocation;
use App\Models\Inventory\StockAudit;
use App\Models\Inventory\StockAuditItem;
use App\Models\System\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class StockAuditConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected Organization $organization;
    protected ProductCategory $category;
    protected ProductLocation $location;
    protected Product $product;
    protected StockAudit $audit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        // Stock changes fan out to notifications that otherwise build a real
        // mailer; fake them so the transaction does not error.
        Mail::fake();
        Notification::fake();

        SystemSetting::set('installed', true, 'boolean');

        $this->organization = Organization::create([
            'name' => 'Test Organization',
            'email' => 'test@organization.com',
            'phone' => '123-456-7890',
            'address' => '123 Test St',
            'currency' => 'USD',
            'timezone' => 'UTC',
        ]);

        $this->category = ProductCategory::create([
            'organization_id' => $this->organization->id,
            'name' => 'Test Category',
            'slug' => 'test-category',
            'is_active' => true,
        ]);

        $this->location = ProductLocation::create([
            'organization_id' => $this->organization->id,
            'name' => 'Warehouse A',
            'code' => 'WH-A',
            'is_active' => true,
        ]);

        // role => 'admin' grants every permission via isAdmin() short-circuit,
        // which covers manage_stock_audits on the complete route.
        $this->admin = User::create([
            'name' => 'Admin User',
            'email' => 'admin@test.com',
            'password' => bcrypt('password'),
            'organization_id' => $this->organization->id,
            'role' => 'admin',
        ]);

        $this->product = Product::create([
            'organization_id' => $this->organization->id,
            'sku' => 'TEST-AUDIT-1',
            'name' => 'Audited Product',
            'price' => 99.99,
            'purchase_price' => 50.00,
            'currency' => 'USD',
            'stock' => 100,
            'min_stock' => 10,
            'is_active' => true,
            'category_id' => $this->category->id,
            'location_id' => $this->location->id,
        ]);

        // In-progress audit with a single counted item: system 100, counted 90
        // => discrepancy -10. Completing applies a -10 recount adjustment once.
        $this->audit = StockAudit::create([
            'organization_id' => $this->organization->id,
            'audit_number' => StockAudit::generateAuditNumber($this->organization->id),
            'name' => 'Concurrency Audit',
            'status' => 'in_progress',
            'audit_type' => 'full',
            'started_at' => now(),
            'created_by' => $this->admin->id,
        ]);

        StockAuditItem::create([
            'stock_audit_id' => $this->audit->id,
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'system_quantity' => 100,
            'counted_quantity' => 90,
            'discrepancy' => 0,
            'status' => 'counted',
        ]);
    }

    public function test_double_complete_applies_recount_once(): void
    {
        // First completion: applies the -10 recount adjustment.
        $this->actingAs($this->admin)->post(route('stock-audits.complete', $this->audit));
        $this->assertSame(90, $this->product->fresh()->stock);

        // Second completion: audit is now 'completed' — the lock + re-check
        // inside the transaction must reject it so the recount is not re-applied.
        $response = $this->actingAs($this->admin)->post(route('stock-audits.complete', $this->audit));
        $response->assertSessionHas('error');
        $this->assertSame(90, $this->product->fresh()->stock);
    }
}
