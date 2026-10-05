<?php

declare(strict_types=1);

namespace Tests\Feature\Plugins;

use App\Imports\OrdersImport;
use App\Imports\ProductsImport;
use App\Mcp\Servers\InventorosServer;
use App\Mcp\Tools\AdjustStockTool;
use App\Mcp\Tools\CreateOrderTool;
use App\Mcp\Tools\CreateProductTool;
use App\Mcp\Tools\CreatePurchaseOrderTool;
use App\Mcp\Tools\ReceivePurchaseOrderTool;
use App\Models\Auth\Organization;
use App\Models\Customer;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductLocation;
use App\Models\Inventory\ProductLocationStock;
use App\Models\Inventory\ProductVariant;
use App\Models\Inventory\StockAdjustment;
use App\Models\Inventory\Supplier;
use App\Models\Order\Order;
use App\Models\Purchasing\PurchaseOrder;
use App\Models\System\SystemSetting;
use App\Models\User;
use App\Services\OrderService;
use App\Services\ProductLocationStockService;
use App\Services\PurchaseOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

/**
 * The domain change hooks plugins build integrations on (product, variant,
 * order, purchase order and customer created/updated/deleted, plus
 * stock_changed) fire exactly once per change, after it commits, whichever
 * surface made it: web, REST, GraphQL, MCP, imports, bulk actions and
 * console commands.
 */
final class DomainChangeHooksTest extends TestCase
{
    use RefreshDatabase;

    private const HOOKS = [
        'product_created', 'product_updated', 'product_deleted',
        'variant_created', 'variant_updated', 'variant_deleted',
        'order_created', 'order_updated', 'order_deleted',
        'purchase_order_created', 'purchase_order_updated', 'purchase_order_deleted',
        'customer_created', 'customer_updated', 'customer_deleted',
        'stock_changed',
    ];

    private Organization $org;

    private User $admin;

    private Supplier $supplier;

    private ProductLocation $location;

    /** @var array<string, array<int, array<int, mixed>>> */
    private array $fired = [];

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Notification::fake();
        SystemSetting::set('installed', true, 'boolean');

        $this->org = Organization::create(['name' => 'Hooks', 'email' => 'hooks@example.com', 'currency' => 'USD', 'timezone' => 'UTC']);
        $this->admin = User::create([
            'name' => 'Admin', 'email' => 'admin@hooks.test', 'password' => bcrypt('password'),
            'organization_id' => $this->org->id, 'role' => 'admin',
        ]);
        $this->supplier = Supplier::create(['organization_id' => $this->org->id, 'name' => 'Acme', 'email' => 'acme@example.com', 'is_active' => true]);
        $this->location = ProductLocation::create(['organization_id' => $this->org->id, 'name' => 'Main', 'code' => 'MAIN', 'is_active' => true]);

        foreach (self::HOOKS as $hook) {
            add_action($hook, function (...$args) use ($hook) {
                $this->fired[$hook][] = $args;
            });
        }
    }

    private function product(array $attributes = []): Product
    {
        $product = Product::create($attributes + [
            'organization_id' => $this->org->id,
            'sku' => 'SKU-'.uniqid(),
            'name' => 'Widget',
            'price' => 10,
            'currency' => 'USD',
            'stock' => 20,
            'min_stock' => 0,
            'location_id' => $this->location->id,
            'is_active' => true,
        ]);

        $this->fired = [];

        return $product;
    }

    /**
     * @param  array<string, int>  $expected  hook => times; unlisted hooks must not fire
     */
    private function assertFiredExactly(array $expected): void
    {
        $actual = array_map('count', $this->fired);
        ksort($actual);
        ksort($expected);

        $this->assertSame($expected, $actual);
    }

    private function graphql(string $query): void
    {
        $response = $this->postJson('/graphql', ['query' => $query]);
        $response->assertOk();
        $this->assertNull($response->json('errors'), (string) json_encode($response->json('errors')));
    }

    private function sentPurchaseOrder(Product $product, int $quantity = 5): PurchaseOrder
    {
        $po = app(PurchaseOrderService::class)->create($this->org->id, $this->admin, [
            'supplier_id' => $this->supplier->id,
            'order_date' => now()->toDateString(),
            'currency' => 'USD',
            'items' => [['product_id' => $product->id, 'quantity' => $quantity, 'unit_cost' => '2.00']],
        ]);
        $po->update(['status' => PurchaseOrder::STATUS_SENT]);
        $this->fired = [];

        return $po->fresh('items');
    }

    // ---------------------------------------------------------------
    // Products
    // ---------------------------------------------------------------

    public function test_web_product_create_fires_product_created_and_stock_changed_once(): void
    {
        $this->actingAs($this->admin)->post(route('products.store'), [
            'sku' => 'WEB-1', 'name' => 'Web', 'price' => 5, 'currency' => 'USD', 'stock' => 7,
            'min_stock' => 0, 'is_active' => true, 'location_id' => $this->location->id,
        ])->assertSessionHasNoErrors();

        $this->assertFiredExactly(['product_created' => 1, 'stock_changed' => 1]);
        [$product, $user] = $this->fired['product_created'][0];
        $this->assertSame('WEB-1', $product->sku);
        $this->assertTrue($user->is($this->admin));
        [, $variant, $change] = $this->fired['stock_changed'][0];
        $this->assertNull($variant);
        $this->assertSame(0, $change['before']);
        $this->assertSame(7, $change['after']);
    }

    public function test_rest_product_create_fires_product_created_once(): void
    {
        Sanctum::actingAs($this->admin);
        $this->postJson('/api/v1/products', ['sku' => 'API-1', 'name' => 'Api', 'price' => 5, 'stock' => 0])->assertCreated();

        $this->assertFiredExactly(['product_created' => 1]);
    }

    public function test_graphql_product_create_fires_product_created_once(): void
    {
        Sanctum::actingAs($this->admin);
        $this->graphql('mutation { createProduct(sku: "GQL-1", name: "Gql", price: 5) { id } }');

        $this->assertFiredExactly(['product_created' => 1]);
    }

    public function test_mcp_product_create_fires_product_created_once(): void
    {
        InventorosServer::actingAs($this->admin)
            ->tool(CreateProductTool::class, ['sku' => 'MCP-1', 'name' => 'Mcp', 'price' => 5, 'stock' => 3])
            ->assertOk();

        $this->assertFiredExactly(['product_created' => 1, 'stock_changed' => 1]);
    }

    public function test_import_fires_created_for_new_rows_and_one_update_per_changed_row(): void
    {
        $existing = $this->product(['sku' => 'IMP-OLD', 'name' => 'Old name', 'stock' => 4]);
        $this->actingAs($this->admin);

        (new ProductsImport($this->org->id, $this->admin))->collection(collect([
            collect(['sku' => 'IMP-OLD', 'name' => 'New name', 'price' => 10, 'stock' => 9]),
            collect(['sku' => 'IMP-NEW', 'name' => 'Brand new', 'price' => 5, 'stock' => 2]),
        ]));

        $this->assertSame(1, count($this->fired['product_created']));
        $this->assertSame('IMP-NEW', $this->fired['product_created'][0][0]->sku);
        $this->assertSame(1, count($this->fired['product_updated']));
        $this->assertTrue($this->fired['product_updated'][0][0]->is($existing));
        $this->assertSame(2, count($this->fired['stock_changed']));
    }

    public function test_web_product_update_fires_product_updated_once(): void
    {
        $product = $this->product();

        $this->actingAs($this->admin)->put(route('products.update', $product), [
            'sku' => $product->sku, 'name' => 'Renamed', 'price' => 12, 'currency' => 'USD',
            'min_stock' => 0, 'is_active' => true,
        ])->assertSessionHasNoErrors();

        $this->assertFiredExactly(['product_updated' => 1]);
        $this->assertSame('Renamed', $this->fired['product_updated'][0][0]->name);
    }

    public function test_rest_product_update_fires_product_updated_once(): void
    {
        $product = $this->product();
        Sanctum::actingAs($this->admin);

        $this->putJson("/api/v1/products/{$product->id}", ['name' => 'Renamed'])->assertOk();

        $this->assertFiredExactly(['product_updated' => 1]);
    }

    public function test_graphql_product_update_fires_product_updated_once(): void
    {
        $product = $this->product();
        Sanctum::actingAs($this->admin);

        $this->graphql("mutation { updateProduct(id: {$product->id}, name: \"Renamed\") { id } }");

        $this->assertFiredExactly(['product_updated' => 1]);
    }

    public function test_bulk_category_change_fires_product_updated_per_product(): void
    {
        $a = $this->product();
        $b = $this->product();
        $category = \App\Models\Inventory\ProductCategory::create(['organization_id' => $this->org->id, 'name' => 'Cat', 'slug' => 'cat']);
        $this->fired = [];

        $this->actingAs($this->admin)->post(route('products.bulk.update-category'), [
            'ids' => [$a->id, $b->id], 'category_id' => $category->id,
        ])->assertSessionHasNoErrors();

        $this->assertFiredExactly(['product_updated' => 2]);
    }

    public function test_product_delete_fires_product_deleted_once_on_each_surface(): void
    {
        $web = $this->product();
        $this->actingAs($this->admin)->delete(route('products.destroy', $web));
        $this->assertFiredExactly(['product_deleted' => 1]);

        $api = $this->product();
        Sanctum::actingAs($this->admin);
        $this->deleteJson("/api/v1/products/{$api->id}")->assertOk();
        $this->assertFiredExactly(['product_deleted' => 1]);

        $gql = $this->product();
        $this->graphql("mutation { deleteProduct(id: {$gql->id}) }");
        $this->assertFiredExactly(['product_deleted' => 1]);

        $bulkA = $this->product();
        $bulkB = $this->product();
        $this->fired = [];
        $this->actingAs($this->admin)->post(route('products.bulk.delete'), ['ids' => [$bulkA->id, $bulkB->id]]);
        $this->assertFiredExactly(['product_deleted' => 2]);
    }

    public function test_several_saves_in_one_transaction_fire_one_product_updated(): void
    {
        $product = $this->product();
        $this->actingAs($this->admin);

        DB::transaction(function () use ($product) {
            $product->update(['name' => 'First']);
            $product->update(['name' => 'Second']);
            $product->update(['description' => 'Third']);
        });

        $this->assertFiredExactly(['product_updated' => 1]);
        $this->assertSame('Second', $this->fired['product_updated'][0][0]->name);
    }

    public function test_nothing_fires_when_the_transaction_rolls_back(): void
    {
        $product = $this->product();

        try {
            DB::transaction(function () use ($product) {
                $product->update(['name' => 'Rolled back']);
                StockAdjustment::adjust($product, 5, 'manual', 'test');
                throw new \RuntimeException('abort');
            });
        } catch (\RuntimeException) {
        }

        $this->assertFiredExactly([]);

        // A later change is announced: the rolled-back keys do not linger.
        $product->refresh()->update(['name' => 'Kept']);
        $this->assertFiredExactly(['product_updated' => 1]);
    }

    // ---------------------------------------------------------------
    // Variants
    // ---------------------------------------------------------------

    public function test_rest_variant_create_update_delete_fire_once_each(): void
    {
        $product = $this->product(['stock' => 0]);
        $product->forceFill(['has_variants' => true])->save();
        $this->fired = [];
        Sanctum::actingAs($this->admin);

        $created = $this->postJson("/api/v1/products/{$product->id}/variants", [
            'sku' => 'VAR-1', 'title' => 'Red', 'option_values' => ['Color' => 'Red'], 'price' => 5, 'stock' => 0,
        ])->assertCreated();
        $this->assertFiredExactly(['variant_created' => 1]);

        $id = $created->json('data.id');
        $this->fired = [];
        $this->putJson("/api/v1/products/{$product->id}/variants/{$id}", ['title' => 'Crimson'])->assertOk();
        $this->assertFiredExactly(['variant_updated' => 1]);

        $this->fired = [];
        $this->deleteJson("/api/v1/products/{$product->id}/variants/{$id}")->assertOk();
        // Removing the last variant also turns has_variants off on the product.
        $this->assertFiredExactly(['variant_deleted' => 1, 'product_updated' => 1]);
    }

    public function test_variant_stock_change_reports_the_variant(): void
    {
        $product = $this->product(['stock' => 0]);
        $product->forceFill(['has_variants' => true])->save();
        $variant = ProductVariant::create([
            'organization_id' => $this->org->id, 'product_id' => $product->id, 'sku' => 'VAR-S', 'title' => 'Blue',
            'option_values' => ['Color' => 'Blue'], 'price' => 5, 'stock' => 3, 'is_active' => true,
        ]);
        $this->fired = [];
        $this->actingAs($this->admin);

        StockAdjustment::adjustVariant($variant, 4, 'manual', 'test');

        $this->assertSame(1, count($this->fired['stock_changed']));
        [$hookProduct, $hookVariant, $change] = $this->fired['stock_changed'][0];
        $this->assertTrue($hookProduct->is($product));
        $this->assertTrue($hookVariant->is($variant));
        $this->assertSame(['before' => 3, 'after' => 7], ['before' => $change['before'], 'after' => $change['after']]);
    }

    // ---------------------------------------------------------------
    // Stock
    // ---------------------------------------------------------------

    public function test_stock_adjustments_fire_stock_changed_once_on_each_surface(): void
    {
        $product = $this->product(['stock' => 20]);
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/v1/stock-adjustments', [
            'product_id' => $product->id, 'quantity' => -3, 'type' => 'manual', 'reason' => 'rest',
        ])->assertCreated();
        $this->assertFiredExactly(['stock_changed' => 1]);
        $change = $this->fired['stock_changed'][0][2];
        $this->assertSame(20, $change['before']);
        $this->assertSame(17, $change['after']);

        $this->fired = [];
        $this->graphql("mutation { createStockAdjustment(product_id: {$product->id}, quantity: 2, type: \"manual\", reason: \"gql\") { id } }");
        $this->assertFiredExactly(['stock_changed' => 1]);

        $this->fired = [];
        InventorosServer::actingAs($this->admin)
            ->tool(AdjustStockTool::class, ['product_id' => $product->id, 'quantity' => 1, 'type' => 'manual'])
            ->assertOk();
        $this->assertFiredExactly(['stock_changed' => 1]);
    }

    public function test_a_bin_move_reports_the_locations_with_an_unchanged_total(): void
    {
        $product = $this->product(['stock' => 10]);
        $other = ProductLocation::create(['organization_id' => $this->org->id, 'name' => 'Back', 'code' => 'BACK', 'is_active' => true]);
        app(ProductLocationStockService::class)->ensureBinned($product);
        $this->fired = [];

        DB::transaction(fn () => app(ProductLocationStockService::class)->move($product, $this->location->id, $other->id, 4));

        $this->assertFiredExactly(['stock_changed' => 1]);
        $change = $this->fired['stock_changed'][0][2];
        $this->assertSame(10, $change['before']);
        $this->assertSame(10, $change['after']);
        $this->assertSame(['before' => 10, 'after' => 6], $change['locations'][$this->location->id]);
        $this->assertSame(['before' => 0, 'after' => 4], $change['locations'][$other->id]);
    }

    public function test_the_reconcile_command_fires_stock_changed_for_corrected_bins(): void
    {
        $product = $this->product(['stock' => 10]);
        ProductLocationStock::create(['organization_id' => $this->org->id, 'product_id' => $product->id, 'location_id' => $this->location->id, 'quantity' => 6]);
        $this->fired = [];

        $this->artisan('inventory:reconcile-location-stock', ['--fix' => true])->assertSuccessful();

        $this->assertFiredExactly(['stock_changed' => 1]);
        $this->assertSame(['before' => 6, 'after' => 10], $this->fired['stock_changed'][0][2]['locations'][$this->location->id]);
    }

    // ---------------------------------------------------------------
    // Orders
    // ---------------------------------------------------------------

    public function test_order_creation_fires_order_created_and_no_order_updated_on_each_surface(): void
    {
        $product = $this->product(['stock' => 50]);
        $items = [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 5]];

        Sanctum::actingAs($this->admin);
        $this->postJson('/api/v1/orders', ['customer_name' => 'Rest', 'items' => $items])->assertCreated();
        $this->assertFiredExactly(['order_created' => 1, 'stock_changed' => 1]);

        $this->fired = [];
        $this->graphql(sprintf('mutation { createOrder(customer_name: "Gql", items: [{product_id: %d, quantity: 1, unit_price: 5}]) { id } }', $product->id));
        $this->assertFiredExactly(['order_created' => 1, 'stock_changed' => 1]);

        $this->fired = [];
        InventorosServer::actingAs($this->admin)
            ->tool(CreateOrderTool::class, ['customer_name' => 'Mcp', 'items' => $items])
            ->assertOk();
        $this->assertFiredExactly(['order_created' => 1, 'stock_changed' => 1]);

        $this->fired = [];
        $this->actingAs($this->admin)->post(route('orders.store'), [
            'customer_name' => 'Web', 'order_date' => now()->toDateString(), 'status' => 'pending', 'currency' => 'USD', 'items' => $items,
        ])->assertSessionHasNoErrors();
        $this->assertFiredExactly(['order_created' => 1, 'stock_changed' => 1]);
    }

    public function test_a_stock_adjusting_order_import_announces_each_order_once(): void
    {
        $product = $this->product(['sku' => 'WID-1', 'stock' => 50]);
        $this->actingAs($this->admin);
        $csv = "external_reference,order_date,status,customer_name,customer_email,product_sku,variant_sku,quantity,unit_price,order_tax,order_shipping,notes\n"
            ."IMP-1,2024-01-05,pending,Acme Imports,acme@imports.test,WID-1,,3,10,,,\n";

        Excel::import(new OrdersImport($this->admin), UploadedFile::fake()->createWithContent('orders.csv', $csv));

        $this->assertSame(1, count($this->fired['order_created'] ?? []));
        $this->assertSame(1, count($this->fired['stock_changed'] ?? []));
        $this->assertArrayNotHasKey('order_updated', $this->fired);
        $this->assertSame(1, count($this->fired['customer_created'] ?? []));
    }

    public function test_order_update_and_delete_fire_once(): void
    {
        $product = $this->product(['stock' => 50]);
        $order = app(OrderService::class)->create([
            'customer_name' => 'Customer', 'status' => 'pending', 'order_date' => now()->toDateString(),
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 5]],
        ], $this->admin, 'manual');
        $this->fired = [];

        Sanctum::actingAs($this->admin);
        $this->putJson("/api/v1/orders/{$order->id}", ['notes' => 'Edited'])->assertOk();
        $this->assertFiredExactly(['order_updated' => 1]);

        $this->fired = [];
        $this->deleteJson("/api/v1/orders/{$order->id}")->assertOk();
        $this->assertSame(1, count($this->fired['order_deleted']));
        $this->assertTrue($this->fired['order_deleted'][0][0]->is($order));
    }

    // ---------------------------------------------------------------
    // Purchase orders
    // ---------------------------------------------------------------

    public function test_purchase_order_creation_fires_created_and_no_updated_on_each_surface(): void
    {
        $product = $this->product();
        $items = [['product_id' => $product->id, 'quantity' => 2, 'unit_cost' => '3.00']];
        $payload = ['supplier_id' => $this->supplier->id, 'order_date' => now()->toDateString(), 'currency' => 'USD', 'items' => $items];

        $this->actingAs($this->admin)->post(route('purchase-orders.store'), $payload)->assertSessionHasNoErrors();
        $this->assertFiredExactly(['purchase_order_created' => 1]);

        $this->fired = [];
        Sanctum::actingAs($this->admin);
        $this->postJson('/api/v1/purchase-orders', $payload)->assertCreated();
        $this->assertFiredExactly(['purchase_order_created' => 1]);

        $this->fired = [];
        $this->graphql(sprintf(
            'mutation { createPurchaseOrder(supplier_id: %d, order_date: "%s", currency: "USD", items: [{product_id: %d, quantity: 2, unit_cost: 3}]) { id } }',
            $this->supplier->id, now()->toDateString(), $product->id,
        ));
        $this->assertFiredExactly(['purchase_order_created' => 1]);

        $this->fired = [];
        InventorosServer::actingAs($this->admin)->tool(CreatePurchaseOrderTool::class, $payload)->assertOk();
        $this->assertFiredExactly(['purchase_order_created' => 1]);
    }

    public function test_receiving_a_purchase_order_fires_one_update_and_one_stock_change_per_surface(): void
    {
        $product = $this->product(['stock' => 0]);

        $po = $this->sentPurchaseOrder($product);
        Sanctum::actingAs($this->admin);
        $this->postJson("/api/v1/purchase-orders/{$po->id}/receive", [
            'items' => [['id' => $po->items->first()->id, 'quantity_to_receive' => 5]],
        ])->assertOk();
        $this->assertFiredExactly(['purchase_order_updated' => 1, 'stock_changed' => 1]);

        $po = $this->sentPurchaseOrder($product);
        InventorosServer::actingAs($this->admin)->tool(ReceivePurchaseOrderTool::class, [
            'id' => $po->id, 'items' => [['id' => $po->items->first()->id, 'quantity_to_receive' => 5]],
        ])->assertOk();
        $this->assertFiredExactly(['purchase_order_updated' => 1, 'stock_changed' => 1]);
    }

    public function test_purchase_order_delete_fires_once(): void
    {
        $product = $this->product();
        $po = app(PurchaseOrderService::class)->create($this->org->id, $this->admin, [
            'supplier_id' => $this->supplier->id, 'order_date' => now()->toDateString(), 'currency' => 'USD',
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_cost' => '1.00']],
        ]);
        $this->fired = [];

        Sanctum::actingAs($this->admin);
        $this->deleteJson("/api/v1/purchase-orders/{$po->id}")->assertOk();

        $this->assertFiredExactly(['purchase_order_deleted' => 1]);
    }

    // ---------------------------------------------------------------
    // Customers
    // ---------------------------------------------------------------

    public function test_customer_creation_fires_once_on_each_surface(): void
    {
        $this->actingAs($this->admin)->post(route('customers.store'), ['name' => 'Web Co', 'email' => 'web@co.test'])->assertSessionHasNoErrors();
        $this->assertFiredExactly(['customer_created' => 1]);

        $this->fired = [];
        Sanctum::actingAs($this->admin);
        $this->postJson('/api/v1/customers', ['name' => 'Api Co', 'email' => 'api@co.test'])->assertCreated();
        $this->assertFiredExactly(['customer_created' => 1]);

        $this->fired = [];
        $this->graphql('mutation { createCustomer(name: "Gql Co") { id } }');
        $this->assertFiredExactly(['customer_created' => 1]);

        $customer = Customer::where('name', 'Gql Co')->sole();
        $this->fired = [];
        $this->putJson("/api/v1/customers/{$customer->id}", ['name' => 'Gql Co Renamed'])->assertOk();
        $this->assertFiredExactly(['customer_updated' => 1]);

        $this->fired = [];
        $this->deleteJson("/api/v1/customers/{$customer->id}")->assertOk();
        $this->assertFiredExactly(['customer_deleted' => 1]);
    }
}
