<?php

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductVariant;
use App\Models\Inventory\StockAdjustment;
use App\Models\System\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StockAdjustmentRaceConditionTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        SystemSetting::set('installed', true, 'boolean');

        $organization = Organization::create([
            'name' => 'Test Org',
            'email' => 'org@test.com',
            'currency' => 'USD',
            'timezone' => 'UTC',
        ]);

        $this->user = User::create([
            'name' => 'Admin',
            'email' => 'a@test.com',
            'password' => bcrypt('password'),
            'organization_id' => $organization->id,
            'role' => 'admin',
        ]);

        $this->product = Product::create([
            'organization_id' => $organization->id,
            'sku' => 'SKU-RACE-001',
            'name' => 'Race Product',
            'price' => 10,
            'currency' => 'USD',
            'stock' => 100,
            'min_stock' => 0,
            'is_active' => true,
        ]);

        $this->actingAs($this->user);
    }

    public function test_adjust_uses_fresh_locked_stock_not_caller_stale_value(): void
    {
        // Hold a stale reference whose in-memory ->stock is 100.
        $staleProduct = $this->product;
        $this->assertSame(100, $staleProduct->stock);

        // A concurrent transaction (simulated) updates stock in the DB to 50.
        DB::table('products')->where('id', $this->product->id)->update(['stock' => 50]);

        // Caller passes the stale reference — pre-fix this would compute
        // quantity_before=100 and quantity_after=110 and overwrite the
        // concurrent write. After fix, adjust() re-reads under lockForUpdate
        // and computes 50 + 10 = 60.
        $adjustment = StockAdjustment::adjust($staleProduct, 10, 'received');

        $this->assertSame(50, $adjustment->quantity_before);
        $this->assertSame(60, $adjustment->quantity_after);
        $this->assertSame(10, $adjustment->adjustment_quantity);

        // Persisted product reflects the locked-read value, not the stale +10.
        $this->assertSame(60, (int) DB::table('products')->where('id', $this->product->id)->value('stock'));

        // Caller's in-memory instance is also synced.
        $this->assertSame(60, $staleProduct->stock);
    }

    public function test_adjust_variant_uses_fresh_locked_stock(): void
    {
        $variant = ProductVariant::create([
            'organization_id' => $this->product->organization_id,
            'product_id' => $this->product->id,
            'sku' => 'SKU-RACE-001-VAR',
            'price' => 12,
            'stock' => 30,
            'is_active' => true,
            'option_values' => ['Size' => 'Small'],
        ]);

        // Simulate concurrent variant stock change.
        DB::table('product_variants')->where('id', $variant->id)->update(['stock' => 15]);

        $adjustment = StockAdjustment::adjustVariant($variant, -5, 'damage');

        $this->assertSame(15, $adjustment->quantity_before);
        $this->assertSame(10, $adjustment->quantity_after);
        $this->assertSame(-5, $adjustment->adjustment_quantity);
        $this->assertSame(10, (int) DB::table('product_variants')->where('id', $variant->id)->value('stock'));
        $this->assertSame(10, $variant->stock);
    }
}
