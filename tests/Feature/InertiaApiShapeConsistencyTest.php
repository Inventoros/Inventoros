<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductCategory;
use App\Models\Inventory\ProductLocation;
use App\Models\System\SystemSetting;
use App\Models\User;
use App\Services\OrderService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * P2-15 — Inertia <-> API resource shape consistency.
 *
 * The Inertia (web) surface serializes models via their default toArray(),
 * while the REST API serializes the same models through JSON Resources.
 * The two are allowed to expose *different* sets of fields (the API adds
 * computed fields; the web surface exposes raw columns the API omits), but
 * any field present on BOTH surfaces must carry the same value — otherwise
 * the frontend and API silently drift (a renamed column, a changed cast, a
 * different enum serialization) with nothing to catch it.
 *
 * These tests pin that contract: for each core entity, every key shared
 * between the API resource payload and the Inertia prop must agree in value
 * (dates compared as instants, since the two surfaces format them
 * differently by design). This fails loudly the moment a shared field drifts.
 */
class InertiaApiShapeConsistencyTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $organization;

    protected User $admin;

    protected ProductCategory $category;

    protected ProductLocation $location;

    protected function setUp(): void
    {
        parent::setUp();

        SystemSetting::set('installed', true, 'boolean');

        $this->organization = Organization::create([
            'name' => 'Shape Org',
            'email' => 'shape@org.com',
            'currency' => 'USD',
            'timezone' => 'UTC',
        ]);

        $this->category = ProductCategory::create([
            'organization_id' => $this->organization->id,
            'name' => 'Widgets',
            'slug' => 'widgets',
            'is_active' => true,
        ]);

        $this->location = ProductLocation::create([
            'organization_id' => $this->organization->id,
            'name' => 'Main',
            'code' => 'MAIN',
            'is_active' => true,
        ]);

        $this->admin = User::create([
            'name' => 'Admin',
            'email' => 'admin@shape.com',
            'password' => bcrypt('password'),
            'organization_id' => $this->organization->id,
            'role' => 'admin',
        ]);
    }

    public function test_product_show_surfaces_agree_on_shared_fields(): void
    {
        $product = Product::create([
            'organization_id' => $this->organization->id,
            'category_id' => $this->category->id,
            'location_id' => $this->location->id,
            'sku' => 'SHAPE-1',
            'name' => 'Shape Product',
            'description' => 'A product used for the shape contract.',
            'price' => 19.99,
            'purchase_price' => 9.50,
            'currency' => 'USD',
            'stock' => 42,
            'min_stock' => 5,
            'max_stock' => 100,
            'barcode' => '0123456789',
            'is_active' => true,
            'tracking_type' => 'none',
        ]);

        $api = $this->apiData("/api/v1/products/{$product->id}");
        $inertia = $this->inertiaProp("/products/{$product->id}", 'product');

        $this->assertSharedFieldsAgree($api, $inertia);
    }

    public function test_order_show_surfaces_agree_on_shared_fields(): void
    {
        $product = Product::create([
            'organization_id' => $this->organization->id,
            'sku' => 'SHAPE-ORD',
            'name' => 'Order Line Product',
            'price' => 10.00,
            'currency' => 'USD',
            'stock' => 100,
            'min_stock' => 0,
            'is_active' => true,
        ]);

        $order = app(OrderService::class)->create([
            'customer_name' => 'Acme Inc',
            'customer_email' => 'buyer@acme.test',
            'customer_address' => '1 Test St',
            'status' => 'pending',
            'order_date' => now()->toDateString(),
            'notes' => 'Contract order.',
            'items' => [
                ['product_id' => $product->id, 'quantity' => 2, 'unit_price' => 10.00],
            ],
        ], $this->admin, 'manual');

        $api = $this->apiData("/api/v1/orders/{$order->id}");
        $inertia = $this->inertiaProp("/orders/{$order->id}", 'order');

        $this->assertSharedFieldsAgree($api, $inertia);
    }

    public function test_product_index_surfaces_agree_on_shared_fields(): void
    {
        Product::create([
            'organization_id' => $this->organization->id,
            'category_id' => $this->category->id,
            'location_id' => $this->location->id,
            'sku' => 'IDX-1',
            'name' => 'Index Product',
            'price' => 12.50,
            'currency' => 'USD',
            'stock' => 7,
            'min_stock' => 1,
            'is_active' => true,
            'tracking_type' => 'none',
        ]);

        $api = $this->apiData('/api/v1/products')[0];
        $inertia = $this->inertiaProp('/products', 'products')['data'][0];

        $this->assertSharedFieldsAgree($api, $inertia);
    }

    public function test_order_index_surfaces_agree_on_shared_fields(): void
    {
        $product = Product::create([
            'organization_id' => $this->organization->id,
            'sku' => 'IDX-ORD',
            'name' => 'Index Order Product',
            'price' => 10.00,
            'currency' => 'USD',
            'stock' => 50,
            'min_stock' => 0,
            'is_active' => true,
        ]);

        app(OrderService::class)->create([
            'customer_name' => 'Acme Inc',
            'customer_email' => 'buyer@acme.test',
            'status' => 'pending',
            'order_date' => now()->toDateString(),
            'items' => [
                ['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 10.00],
            ],
        ], $this->admin, 'manual');

        $api = $this->apiData('/api/v1/orders')[0];
        $inertia = $this->inertiaProp('/orders', 'orders')['data'][0];

        $this->assertSharedFieldsAgree($api, $inertia);
    }

    /**
     * Fetch the `data` envelope of an API resource response.
     *
     * @return array<string, mixed>
     */
    private function apiData(string $url): array
    {
        Sanctum::actingAs($this->admin);

        return $this->getJson($url)->assertOk()->json('data');
    }

    /**
     * Fetch a single named prop from an Inertia page render.
     *
     * @return array<string, mixed>
     */
    private function inertiaProp(string $url, string $prop): array
    {
        $captured = null;

        $this->actingAs($this->admin)
            ->get($url)
            ->assertInertia(function (AssertableInertia $page) use ($prop, &$captured) {
                $captured = $page->toArray()['props'][$prop];
            });

        $this->assertIsArray($captured, "Inertia prop '{$prop}' was not an array.");

        return $captured;
    }

    /**
     * Assert every key present in both payloads carries the same value.
     *
     * @param  array<string, mixed>  $api
     * @param  array<string, mixed>  $inertia
     */
    private function assertSharedFieldsAgree(array $api, array $inertia): void
    {
        $shared = array_intersect(array_keys($api), array_keys($inertia));

        $this->assertNotEmpty($shared, 'Surfaces share no fields — payloads are unexpectedly disjoint.');

        foreach ($shared as $key) {
            $this->assertSame(
                $this->normalize($api[$key]),
                $this->normalize($inertia[$key]),
                "Field '{$key}' drifted between the API resource and the Inertia prop.",
            );
        }
    }

    /**
     * Normalize a field value for cross-surface comparison.
     *
     * Dates are compared as instants (the surfaces format them differently by
     * design); nested relations/collections are reduced to a structural marker
     * since their item shape is covered by the related resource's own tests.
     */
    private function normalize(mixed $value): mixed
    {
        if (is_array($value)) {
            return '__structured__';
        }

        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}T/', $value) === 1) {
            return Carbon::parse($value)->getTimestamp();
        }

        return $value;
    }
}
