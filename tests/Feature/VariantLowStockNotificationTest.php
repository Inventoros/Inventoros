<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductVariant;
use App\Models\Inventory\StockAdjustment;
use App\Models\Notification;
use App\Models\Role;
use App\Models\System\SystemSetting;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Low-stock and out-of-stock alerts only watched products.stock, which a
 * product sold by variant never moves, so its stock could run out without
 * an alert. A variant with its own minimum (min_stock above 0) alerts on
 * its own; otherwise the product's summed stock (total_stock, as the
 * dashboard counts it) is compared with the product's minimum. Alerts fire
 * when the threshold is crossed and share the product alerts' cooldown.
 */
class VariantLowStockNotificationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $manager;

    private Product $tee;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        SystemSetting::set('installed', true, 'boolean');

        $this->org = Organization::create(['name' => 'Acme', 'email' => 'acme@example.com', 'currency' => 'USD', 'timezone' => 'UTC']);

        $role = Role::create([
            'name' => 'Stock', 'slug' => 'stock', 'organization_id' => $this->org->id,
            'permissions' => ['manage_stock', 'view_products', 'create_orders'],
        ]);
        $this->manager = User::create([
            'name' => 'Manager', 'email' => 'manager@example.com', 'password' => bcrypt('x'),
            'organization_id' => $this->org->id,
        ]);
        $this->manager->forceFill(['role' => 'member'])->save();
        $this->manager->roles()->attach($role->id);
        $this->actingAs($this->manager);

        $this->tee = Product::create([
            'organization_id' => $this->org->id, 'sku' => 'TEE', 'name' => 'Tee',
            'price' => 20, 'currency' => 'USD', 'stock' => 0, 'min_stock' => 4,
            'has_variants' => true, 'is_active' => true,
        ]);
    }

    private function variant(string $sku, int $stock, int $minStock = 0): ProductVariant
    {
        return ProductVariant::create([
            'product_id' => $this->tee->id, 'organization_id' => $this->org->id,
            'sku' => $sku, 'title' => $sku, 'option_values' => ['Size' => $sku],
            'stock' => $stock, 'min_stock' => $minStock, 'is_active' => true,
        ]);
    }

    private function alerts(string $type)
    {
        return Notification::where('type', $type)->where('user_id', $this->manager->id)->get();
    }

    public function test_a_variant_with_its_own_minimum_alerts_when_it_runs_low(): void
    {
        $small = $this->variant('TEE-S', 5, 3);
        $this->variant('TEE-M', 20);

        StockAdjustment::adjustVariant($small, -3, 'decrease');

        $alerts = $this->alerts('low_stock');
        $this->assertCount(1, $alerts);
        $this->assertSame($small->id, $alerts[0]->data['variant_id']);
        $this->assertSame(2, $alerts[0]->data['current_stock']);
        $this->assertStringContainsString('TEE-S', $alerts[0]->message);

        // Still low: no repeat.
        StockAdjustment::adjustVariant($small->fresh(), -1, 'decrease');
        $this->assertCount(1, $this->alerts('low_stock'));
    }

    public function test_a_variant_that_dips_again_within_the_cooldown_does_not_realert(): void
    {
        $small = $this->variant('TEE-S', 5, 3);

        StockAdjustment::adjustVariant($small, -3, 'decrease');   // 5 -> 2, alert
        StockAdjustment::adjustVariant($small->fresh(), 4, 'increase'); // 2 -> 6
        StockAdjustment::adjustVariant($small->fresh(), -4, 'decrease'); // 6 -> 2, within cooldown

        $this->assertCount(1, $this->alerts('low_stock'));

        $this->travel((int) config('notifications.low_stock_cooldown_minutes') + 1)->minutes();
        StockAdjustment::adjustVariant($small->fresh(), 4, 'increase');
        StockAdjustment::adjustVariant($small->fresh(), -4, 'decrease');

        $this->assertCount(2, $this->alerts('low_stock'));
    }

    public function test_a_variant_with_its_own_minimum_alerts_when_it_runs_out(): void
    {
        $small = $this->variant('TEE-S', 2, 3);
        $this->variant('TEE-M', 20);

        StockAdjustment::adjustVariant($small, -2, 'decrease');

        $alerts = $this->alerts('out_of_stock');
        $this->assertCount(1, $alerts);
        $this->assertSame($small->id, $alerts[0]->data['variant_id']);
    }

    public function test_variants_without_a_minimum_alert_on_the_products_summed_stock(): void
    {
        $small = $this->variant('TEE-S', 3);
        $this->variant('TEE-M', 3);

        // 6 on hand, product minimum 4: selling 1 leaves 5, no alert.
        StockAdjustment::adjustVariant($small, -1, 'decrease');
        $this->assertCount(0, $this->alerts('low_stock'));

        // 5 -> 3 crosses the product minimum.
        StockAdjustment::adjustVariant($small->fresh(), -2, 'decrease');

        $alerts = $this->alerts('low_stock');
        $this->assertCount(1, $alerts);
        $this->assertSame($this->tee->id, $alerts[0]->data['product_id']);
        $this->assertArrayNotHasKey('variant_id', $alerts[0]->data);
        $this->assertSame(3, $alerts[0]->data['current_stock']);
    }

    public function test_the_product_alerts_out_of_stock_when_its_variants_run_out(): void
    {
        $small = $this->variant('TEE-S', 1);
        $this->variant('TEE-M', 0);

        StockAdjustment::adjustVariant($small, -1, 'decrease');

        $alerts = $this->alerts('out_of_stock');
        $this->assertCount(1, $alerts);
        $this->assertSame($this->tee->id, $alerts[0]->data['product_id']);
    }

    public function test_selling_a_variant_on_an_order_alerts_too(): void
    {
        $small = $this->variant('TEE-S', 5, 3);

        app(OrderService::class)->create([
            'customer_name' => 'Buyer', 'status' => 'pending', 'order_date' => now()->toDateString(),
            'items' => [['product_id' => $this->tee->id, 'product_variant_id' => $small->id, 'quantity' => 3, 'unit_price' => 20]],
        ], $this->manager);

        $this->assertCount(1, $this->alerts('low_stock'));
    }

    public function test_the_product_alert_message_uses_the_summed_stock(): void
    {
        $small = $this->variant('TEE-S', 5);

        StockAdjustment::adjustVariant($small, -2, 'decrease');

        $alert = $this->alerts('low_stock')->first();
        $this->assertNotNull($alert);
        $this->assertStringContainsString('Current stock: 3', $alert->message);
    }
}
