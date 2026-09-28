<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Imports\ProductsImport;
use App\Mcp\Servers\InventorosServer;
use App\Mcp\Tools\CreateProductTool;
use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductLocation;
use App\Models\Inventory\ProductLocationStock;
use App\Models\Inventory\ProductVariant;
use App\Models\Inventory\StockAdjustment;
use App\Models\System\SystemSetting;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * On-hand stock changes only through the audited ledger (stock adjustments,
 * orders, receipts, transfers). Product edit used to write products.stock
 * straight from the form: no row lock, no ledger row, no bin update, no
 * approval, and a form opened before a sale and saved after it silently put
 * the sold units back. Edit surfaces now refuse (API) or ignore (web form)
 * stock, and a new product's opening stock is written as a ledger row with
 * its bin, like any other movement.
 */
final class ProductStockOnlyThroughLedgerTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $admin;

    private ProductLocation $location;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Notification::fake();
        SystemSetting::set('installed', true, 'boolean');

        $this->org = Organization::create([
            'name' => 'Ledger Org', 'email' => 'ledger@org.com', 'currency' => 'USD', 'timezone' => 'UTC',
        ]);
        $this->admin = User::create([
            'name' => 'Admin', 'email' => 'admin@ledger.com', 'password' => bcrypt('x'),
            'organization_id' => $this->org->id, 'role' => 'admin',
        ]);
        $this->location = ProductLocation::create([
            'organization_id' => $this->org->id, 'name' => 'Main', 'code' => 'MAIN', 'is_active' => true,
        ]);
    }

    private function product(int $stock = 10, array $attributes = []): Product
    {
        return Product::create(array_merge([
            'organization_id' => $this->org->id, 'sku' => 'LED-1', 'name' => 'Ledgered',
            'price' => 10, 'currency' => 'USD', 'stock' => $stock, 'min_stock' => 0,
            'location_id' => $this->location->id, 'is_active' => true,
        ], $attributes));
    }

    private function variant(Product $product, int $stock = 10): ProductVariant
    {
        $product->update(['has_variants' => true]);

        return ProductVariant::create([
            'organization_id' => $this->org->id, 'product_id' => $product->id,
            'sku' => $product->sku.'-S', 'title' => 'S', 'option_values' => ['Size' => 'S'],
            'price' => 10, 'stock' => $stock, 'min_stock' => 0, 'is_active' => true, 'position' => 0,
        ]);
    }

    private function sell(Product $product, int $qty): void
    {
        $this->actingAs($this->admin);
        app(OrderService::class)->create([
            'customer_name' => 'Acme',
            'items' => [['product_id' => $product->id, 'quantity' => $qty, 'unit_price' => 10]],
        ], $this->admin);
    }

    private function binTotal(Product $product): int
    {
        return (int) ProductLocationStock::where('product_id', $product->id)->sum('quantity');
    }

    // ==================== EDIT: web ====================

    public function test_a_stale_web_edit_form_cannot_put_sold_units_back(): void
    {
        $product = $this->product(10);
        $this->sell($product, 3);
        $this->assertSame(7, (int) $product->fresh()->stock);

        // The form was opened before the sale, so it still says 10.
        $this->actingAs($this->admin)->put(route('products.update', $product), [
            'sku' => 'LED-1', 'name' => 'Renamed', 'price' => 10, 'stock' => 10, 'min_stock' => 0,
        ])->assertRedirect(route('products.index'));

        $product->refresh();
        $this->assertSame('Renamed', $product->name);
        $this->assertSame(7, (int) $product->stock);
        $this->assertSame(7, $this->binTotal($product));
    }

    public function test_web_edit_saves_without_a_stock_field(): void
    {
        $product = $this->product(10);

        $this->actingAs($this->admin)->put(route('products.update', $product), [
            'sku' => 'LED-1', 'name' => 'No stock field', 'price' => 12, 'min_stock' => 2,
        ])->assertSessionHasNoErrors()->assertRedirect(route('products.index'));

        $this->assertSame('No stock field', $product->fresh()->name);
        $this->assertSame(10, (int) $product->fresh()->stock);
    }

    public function test_web_edit_does_not_change_an_existing_variants_stock(): void
    {
        $product = $this->product(0);
        $variant = $this->variant($product, 10);

        $this->actingAs($this->admin)->put(route('products.update', $product), [
            'sku' => 'LED-1', 'name' => 'Ledgered', 'price' => 10, 'min_stock' => 0, 'has_variants' => true,
            'options' => [['name' => 'Size', 'values' => ['S']]],
            'variants' => [['id' => $variant->id, 'option_values' => ['Size' => 'S'], 'sku' => 'LED-1-S', 'stock' => 99]],
        ])->assertSessionHasNoErrors();

        $this->assertSame(10, (int) $variant->fresh()->stock);
    }

    public function test_the_stock_adjustment_form_opens_on_the_product_the_edit_page_links_from(): void
    {
        $product = $this->product(10);

        $this->actingAs($this->admin)
            ->get(route('stock-adjustments.create', ['product_id' => $product->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('StockAdjustments/Create')
                ->where('preselectedProductId', $product->id));
    }

    // ==================== EDIT: REST ====================

    public function test_rest_update_rejects_stock_with_a_pointer_to_adjustments(): void
    {
        $product = $this->product(10);
        Sanctum::actingAs($this->admin);

        $response = $this->putJson("/api/v1/products/{$product->id}", ['name' => 'X', 'stock' => 500])
            ->assertStatus(422)
            ->assertJsonValidationErrors('stock');
        $this->assertStringContainsString('stock adjustment', strtolower($response->json('errors.stock.0')));

        $this->assertSame(10, (int) $product->fresh()->stock);
        $this->assertSame('Ledgered', $product->fresh()->name);
    }

    public function test_rest_update_rejects_stock_on_an_existing_variant(): void
    {
        $product = $this->product(0);
        $variant = $this->variant($product, 10);
        Sanctum::actingAs($this->admin);

        $this->putJson("/api/v1/products/{$product->id}", [
            'variants' => [['id' => $variant->id, 'option_values' => ['Size' => 'S'], 'stock' => 99]],
        ])->assertStatus(422)->assertJsonValidationErrors('variants.0.stock');

        $this->assertSame(10, (int) $variant->fresh()->stock);
    }

    public function test_rest_variant_update_rejects_stock(): void
    {
        $product = $this->product(0);
        $variant = $this->variant($product, 10);
        Sanctum::actingAs($this->admin);

        $this->putJson("/api/v1/products/{$product->id}/variants/{$variant->id}", ['stock' => 99])
            ->assertStatus(422)
            ->assertJsonValidationErrors('stock');

        $this->assertSame(10, (int) $variant->fresh()->stock);
    }

    // ==================== EDIT: GraphQL ====================

    public function test_graphql_update_rejects_stock(): void
    {
        $product = $this->product(10);
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->postJson('/graphql', ['query' => sprintf(
            'mutation { updateProduct(id: %d, name: "X", stock: 500) { id stock } }', $product->id
        )]);

        $this->assertArrayHasKey('stock', $response->json('errors.0.extensions.validation') ?? []);
        $this->assertSame(10, (int) $product->fresh()->stock);
        $this->assertSame('Ledgered', $product->fresh()->name);
    }

    // ==================== CREATE: opening stock is a ledger row ====================

    private function assertOpeningLedger(Product $product, int $quantity): void
    {
        $this->assertSame($quantity, (int) $product->stock);

        /** @var Collection<int, StockAdjustment> $rows */
        $rows = StockAdjustment::where('product_id', $product->id)->whereNull('product_variant_id')->get();
        $this->assertCount(1, $rows);
        $this->assertSame('opening_stock', $rows->first()->type);
        $this->assertSame(0, (int) $rows->first()->quantity_before);
        $this->assertSame($quantity, (int) $rows->first()->quantity_after);
        $this->assertSame($quantity, (int) $rows->first()->adjustment_quantity);
        $this->assertSame($this->location->id, (int) $rows->first()->location_id);
        $this->assertSame($quantity, $this->binTotal($product));
    }

    public function test_web_create_books_opening_stock_through_the_ledger_and_bins(): void
    {
        $this->actingAs($this->admin)->post(route('products.store'), [
            'sku' => 'NEW-1', 'name' => 'New', 'price' => 10, 'currency' => 'USD',
            'stock' => 25, 'min_stock' => 0, 'location_id' => $this->location->id,
        ])->assertRedirect(route('products.index'));

        $this->assertOpeningLedger(Product::where('sku', 'NEW-1')->sole(), 25);
    }

    public function test_rest_create_books_opening_stock_through_the_ledger_and_bins(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/v1/products', [
            'sku' => 'NEW-2', 'name' => 'New', 'price' => 10, 'stock' => 8, 'location_id' => $this->location->id,
        ])->assertCreated();

        $this->assertOpeningLedger(Product::where('sku', 'NEW-2')->sole(), 8);
    }

    public function test_graphql_create_books_opening_stock_through_the_ledger_and_bins(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $this->postJson('/graphql', ['query' => sprintf(
            'mutation { createProduct(sku: "NEW-3", name: "New", price: 10, stock: 6, location_id: %d) { id } }', $this->location->id
        )])->assertJsonMissingPath('errors');

        $this->assertOpeningLedger(Product::where('sku', 'NEW-3')->sole(), 6);
    }

    public function test_mcp_create_books_opening_stock_through_the_ledger_and_bins(): void
    {
        InventorosServer::actingAs($this->admin)
            ->tool(CreateProductTool::class, ['sku' => 'NEW-4', 'name' => 'New', 'stock' => 4, 'location_id' => $this->location->id])
            ->assertOk();

        $this->assertOpeningLedger(Product::where('sku', 'NEW-4')->sole(), 4);
    }

    public function test_a_product_created_with_no_stock_writes_no_ledger_row(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/v1/products', ['sku' => 'NEW-0', 'name' => 'Empty', 'price' => 10])->assertCreated();

        $this->assertSame(0, StockAdjustment::count());
    }

    public function test_variants_created_with_stock_get_opening_ledger_rows(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/v1/products', [
            'sku' => 'VAR-1', 'name' => 'Var', 'price' => 10, 'has_variants' => true,
            'options' => [['name' => 'Size', 'values' => ['S', 'M']]],
            'variants' => [
                ['option_values' => ['Size' => 'S'], 'sku' => 'VAR-1-S', 'stock' => 3],
                ['option_values' => ['Size' => 'M'], 'sku' => 'VAR-1-M', 'stock' => 0],
            ],
        ])->assertCreated();

        $small = ProductVariant::where('sku', 'VAR-1-S')->sole();
        $this->assertSame(3, (int) $small->stock);
        $row = StockAdjustment::where('product_variant_id', $small->id)->sole();
        $this->assertSame('opening_stock', $row->type);
        $this->assertSame(0, (int) $row->quantity_before);
        $this->assertSame(3, (int) $row->quantity_after);
        $this->assertSame(0, StockAdjustment::where('product_variant_id', ProductVariant::where('sku', 'VAR-1-M')->value('id'))->count());
    }

    public function test_rest_variant_create_books_opening_stock(): void
    {
        $product = $this->product(0);
        Sanctum::actingAs($this->admin);

        $this->postJson("/api/v1/products/{$product->id}/variants", [
            'sku' => 'LED-1-L', 'option_values' => ['Size' => 'L'], 'stock' => 5,
        ])->assertCreated();

        $variant = ProductVariant::where('sku', 'LED-1-L')->sole();
        $this->assertSame(5, (int) $variant->stock);
        $this->assertSame('opening_stock', StockAdjustment::where('product_variant_id', $variant->id)->sole()->type);
    }

    // ==================== IMPORT ====================

    public function test_import_books_new_and_changed_stock_through_the_ledger(): void
    {
        $existing = $this->product(10);
        $this->sell($existing, 3); // 7 on hand, bins 7

        $import = new ProductsImport($this->org->id, $this->admin);
        $import->collection(collect([
            collect(['sku' => 'LED-1', 'name' => 'Ledgered', 'price' => 10, 'stock' => 9, 'location' => 'Main']),
            collect(['sku' => 'IMP-NEW', 'name' => 'Imported', 'price' => 5, 'stock' => 4, 'location' => 'Main']),
        ]));

        $existing->refresh();
        $this->assertSame(9, (int) $existing->stock);
        $this->assertSame(9, $this->binTotal($existing));
        $recount = StockAdjustment::where('product_id', $existing->id)->where('type', 'recount')->sole();
        $this->assertSame(7, (int) $recount->quantity_before);
        $this->assertSame(9, (int) $recount->quantity_after);

        $this->assertOpeningLedger(Product::where('sku', 'IMP-NEW')->sole(), 4);
    }
}
