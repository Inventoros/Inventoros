<?php

declare(strict_types=1);

namespace Tests\Feature\Approvals;

use App\Models\Inventory\Product;
use App\Models\Inventory\ProductVariant;
use App\Models\Inventory\StockAdjustment;
use App\Models\Inventory\VariantLocationStock;
use App\Models\Role;
use App\Models\User;
use App\Services\ProductService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Warehouses\WarehouseAccessFixture;
use Tests\TestCase;

/**
 * A new variant's opening stock raises an existing product's on-hand, the
 * same as a stock adjustment. Adding one with stock therefore needs
 * manage_stock (not only edit_products) and must respect the organization's
 * stock-adjustment approval rules, on every variant-creating path: variant
 * create, bulk create, and the variant sync of a product update.
 */
final class VariantOpeningStockPermissionTest extends TestCase
{
    use RefreshDatabase, WarehouseAccessFixture;

    private User $editor;

    private User $clerk;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->withoutVite();
        $this->setUpWarehouseAccess();

        // productA (warehouse A) is the parent; the unassigned clerk is not
        // narrowed by warehouse and holds edit_products + manage_stock.
        $this->productA->update(['has_variants' => true]);
        $this->clerk = $this->unassigned;

        $editorRole = Role::create([
            'name' => 'Catalogue Editor', 'slug' => 'catalogue-editor', 'organization_id' => $this->organization->id,
            'is_system' => false, 'permissions' => ['view_products', 'edit_products'],
        ]);
        $this->editor = User::factory()->forOrganization($this->organization->id)->create(['name' => 'Catalogue Editor']);
        $this->editor->roles()->attach($editorRole->id);
    }

    private function enableApprovals(array $settings = []): void
    {
        $this->organization->update(['settings' => ['approvals' => ['stock_adjustments_enabled' => true] + $settings]]);
    }

    private function storeVariant(User $user, int $stock): TestResponse
    {
        Sanctum::actingAs($user, ['*']);

        return $this->postJson("/api/v1/products/{$this->productA->id}/variants", [
            'sku' => 'ALPHA-RED', 'title' => 'Red', 'option_values' => ['Color' => 'Red'], 'price' => 10, 'stock' => $stock,
        ]);
    }

    private function bulkVariants(User $user, int $stock): TestResponse
    {
        Sanctum::actingAs($user, ['*']);

        return $this->postJson("/api/v1/products/{$this->productA->id}/variants/bulk", ['variants' => [
            ['sku' => 'ALPHA-BLUE', 'option_values' => ['Color' => 'Blue'], 'price' => 10, 'stock' => $stock],
            ['sku' => 'ALPHA-GREEN', 'option_values' => ['Color' => 'Green'], 'price' => 10, 'stock' => 0],
        ]]);
    }

    private function syncVariants(User $user, int $stock): TestResponse
    {
        Sanctum::actingAs($user, ['*']);

        return $this->putJson("/api/v1/products/{$this->productA->id}", [
            'has_variants' => true,
            'options' => [['name' => 'Color', 'values' => ['Black']]],
            'variants' => [['sku' => 'ALPHA-BLACK', 'option_values' => ['Color' => 'Black'], 'price' => 10, 'stock' => $stock]],
        ]);
    }

    private function webSyncVariants(User $user, int $stock): TestResponse
    {
        return $this->actingAs($user)->put(route('products.update', $this->productA), [
            'sku' => $this->productA->sku, 'name' => $this->productA->name, 'price' => 10, 'currency' => 'USD', 'min_stock' => 5,
            'location_id' => $this->locationA->id, 'has_variants' => true,
            'options' => [['name' => 'Color', 'values' => ['White']]],
            'variants' => [['sku' => 'ALPHA-WHITE', 'option_values' => ['Color' => 'White'], 'price' => 10, 'stock' => $stock]],
        ]);
    }

    private function assertNoVariantStock(): void
    {
        $this->assertSame(0, (int) ProductVariant::withTrashed()->sum('stock'));
        $this->assertDatabaseMissing('stock_adjustments', ['type' => 'opening_stock']);
        $this->assertSame(0, (int) VariantLocationStock::sum('quantity'));
    }

    // ---- manage_stock -------------------------------------------------

    public function test_edit_products_alone_cannot_give_a_new_variant_opening_stock(): void
    {
        // A field error on the variant's stock, never a 403 page: the web
        // edit form keeps the user's input.
        $message = 'Opening stock on a new variant needs the manage_stock permission';
        $this->storeVariant($this->editor, 5)->assertUnprocessable()->assertJsonValidationErrors(['stock' => $message]);
        $this->bulkVariants($this->editor, 5)->assertUnprocessable()->assertJsonValidationErrors(['variants.0.stock' => $message]);
        $this->syncVariants($this->editor, 5)->assertUnprocessable()->assertJsonValidationErrors(['variants.0.stock' => $message]);
        $this->from(route('products.edit', $this->productA))->webSyncVariants($this->editor, 5)
            ->assertRedirect(route('products.edit', $this->productA))
            ->assertSessionHasErrors(['variants.0.stock' => ProductService::VARIANT_OPENING_STOCK_PERMISSION_MESSAGE]);

        $this->assertDatabaseCount('product_variants', 0);
        $this->assertNoVariantStock();
    }

    public function test_edit_products_alone_can_still_add_variants_without_stock(): void
    {
        $this->storeVariant($this->editor, 0)->assertCreated();
        $this->bulkVariants($this->editor, 0)->assertCreated();
        $this->syncVariants($this->editor, 0)->assertOk();

        $this->assertNoVariantStock();
        $this->assertSame(1, ProductVariant::where('product_id', $this->productA->id)->count());
    }

    public function test_a_token_without_the_manage_stock_ability_cannot_set_opening_stock(): void
    {
        $token = $this->clerk->createToken('catalogue-only', ['edit_products'])->plainTextToken;

        $this->withToken($token)->postJson("/api/v1/products/{$this->productA->id}/variants", [
            'sku' => 'ALPHA-RED', 'title' => 'Red', 'option_values' => ['Color' => 'Red'], 'price' => 10, 'stock' => 5,
        ])->assertUnprocessable()->assertJsonValidationErrors('stock');

        $this->assertNoVariantStock();
    }

    public function test_manage_stock_holder_books_opening_stock_as_before(): void
    {
        $this->storeVariant($this->clerk, 5)->assertCreated();
        $this->bulkVariants($this->clerk, 3)->assertCreated();

        $this->assertSame(5, (int) ProductVariant::where('sku', 'ALPHA-RED')->value('stock'));
        $this->assertSame(3, (int) ProductVariant::where('sku', 'ALPHA-BLUE')->value('stock'));
        $this->assertSame(2, StockAdjustment::where('type', 'opening_stock')->count());
    }

    // ---- approvals ----------------------------------------------------

    public function test_opening_stock_that_would_need_approval_is_refused(): void
    {
        $this->enableApprovals();

        $this->storeVariant($this->clerk, 5)->assertUnprocessable()->assertJsonValidationErrors(['stock' => 'needs approval']);
        $this->bulkVariants($this->clerk, 5)->assertUnprocessable()->assertJsonValidationErrors('variants.0.stock');
        $this->syncVariants($this->clerk, 5)->assertUnprocessable()->assertJsonValidationErrors('variants.0.stock');
        $this->storeVariant($this->admin, 5)->assertUnprocessable();

        $this->assertDatabaseCount('product_variants', 0);
        $this->assertNoVariantStock();
        $this->assertDatabaseCount('stock_adjustment_requests', 0);

        // A variant with no opening stock moves nothing and needs no approval.
        $this->storeVariant($this->clerk, 0)->assertCreated();
    }

    public function test_opening_stock_under_the_approval_threshold_is_booked(): void
    {
        $this->enableApprovals(['stock_adjustments_quantity_threshold' => 10]);

        $this->storeVariant($this->clerk, 5)->assertCreated();
        $this->bulkVariants($this->clerk, 50)->assertUnprocessable();

        $this->assertSame(5, (int) ProductVariant::where('sku', 'ALPHA-RED')->value('stock'));
        $this->assertNull(ProductVariant::where('sku', 'ALPHA-BLUE')->first());
    }

    public function test_new_product_with_variants_keeps_its_opening_stock_rules(): void
    {
        // Creating a product (with its variants) is the product's own
        // opening stock, governed by create_products as before.
        $this->enableApprovals();
        $this->clerk->roles()->firstOrFail()->update(['permissions' => array_merge($this->stockPermissions, ['create_products'])]);
        Sanctum::actingAs($this->clerk, ['*']);

        $this->postJson('/api/v1/products', [
            'sku' => 'NEW-1', 'name' => 'New', 'price' => 10, 'location_id' => $this->locationA->id, 'has_variants' => true,
            'options' => [['name' => 'Size', 'values' => ['S']]],
            'variants' => [['sku' => 'NEW-1-S', 'option_values' => ['Size' => 'S'], 'stock' => 4]],
        ])->assertCreated();

        $this->assertSame(4, (int) Product::where('sku', 'NEW-1')->firstOrFail()->variants()->value('stock'));
    }
}
