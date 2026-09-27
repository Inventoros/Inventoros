<?php

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductSerial;
use App\Models\Inventory\ProductVariant;
use App\Models\Role;
use App\Models\System\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * In-app JSON calls made by Vue components with axios, authenticated the way
 * a real browser is: by the session cookie alone.
 *
 * actingAs() / Sanctum::actingAs() set the user on the guard in memory, which
 * hides whether the route can actually read a browser session. These tests
 * persist a logged-in session to a store, reset every guard and session
 * driver, and send only the session cookie (plus the Origin/Referer headers a
 * same-origin SPA request carries).
 */
class InAppJsonSessionAuthTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $organization;

    protected User $admin;

    protected Product $batchProduct;

    private string $sessionPath;

    protected function setUp(): void
    {
        parent::setUp();

        SystemSetting::set('installed', true, 'boolean');

        $this->sessionPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'inventoros-session-test-'.Str::random(8);
        @mkdir($this->sessionPath, 0777, true);
        config(['session.driver' => 'file', 'session.files' => $this->sessionPath]);

        $this->organization = Organization::create([
            'name' => 'Org A',
            'email' => 'a@org.test',
            'currency' => 'USD',
            'timezone' => 'UTC',
        ]);

        $this->admin = User::create([
            'name' => 'Admin A',
            'email' => 'admin-a@test.com',
            'password' => bcrypt('password'),
            'organization_id' => $this->organization->id,
            'role' => 'admin',
        ]);

        $this->batchProduct = Product::create([
            'organization_id' => $this->organization->id,
            'sku' => 'BATCH-A-1',
            'name' => 'Batch Product A',
            'price' => 10,
            'currency' => 'USD',
            'stock' => 0,
            'min_stock' => 0,
            'is_active' => true,
            'tracking_type' => 'batch',
        ]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->sessionPath.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->sessionPath);

        parent::tearDown();
    }

    /**
     * Persist a logged-in web session for the user and return a test client
     * that carries only the browser's session cookie and same-origin headers.
     */
    protected function asBrowser(User $user, array $extraSession = []): static
    {
        $store = $this->app['session']->driver();
        $store->setId(null);
        $store->start();
        $store->put(Auth::guard('web')->getName(), $user->getKey());
        foreach ($extraSession as $key => $value) {
            $store->put($key, $value);
        }
        $store->save();
        $sessionId = $store->getId();

        // Forget everything held in memory so the request must resolve the
        // user from the cookie, exactly as a fresh browser request would.
        $this->app['auth']->forgetGuards();
        $this->app['session']->forgetDrivers();
        $this->app->forgetInstance('session.store');

        $cookieName = config('session.cookie');
        $appUrl = rtrim(config('app.url'), '/');

        // withCredentials(): JSON test requests only send cookies when asked,
        // like fetch/axios on a same-origin request always do.
        return $this
            ->withCredentials()
            ->withCookie($cookieName, $sessionId)
            ->withHeaders([
                'Origin' => $appUrl,
                'Referer' => $appUrl.'/products/'.$this->batchProduct->id,
                'X-Requested-With' => 'XMLHttpRequest',
            ]);
    }

    protected function makeOrgB(): array
    {
        $orgB = Organization::create([
            'name' => 'Org B',
            'email' => 'b@org.test',
            'currency' => 'USD',
            'timezone' => 'UTC',
        ]);

        $adminB = User::create([
            'name' => 'Admin B',
            'email' => 'admin-b@test.com',
            'password' => bcrypt('password'),
            'organization_id' => $orgB->id,
            'role' => 'admin',
        ]);

        return [$orgB, $adminB];
    }

    protected function makeProduct(array $attributes = []): Product
    {
        return Product::create(array_merge([
            'organization_id' => $this->organization->id,
            'sku' => 'SKU-'.Str::random(6),
            'name' => 'Product',
            'price' => 10,
            'currency' => 'USD',
            'stock' => 0,
            'min_stock' => 0,
            'is_active' => true,
        ], $attributes));
    }

    protected function makeVariant(Product $product, int $stock): ProductVariant
    {
        return ProductVariant::create([
            'organization_id' => $product->organization_id,
            'product_id' => $product->id,
            'sku' => 'VAR-'.Str::random(6),
            'title' => 'Medium',
            'option_values' => ['size' => 'M'],
            'price' => 10,
            'stock' => $stock,
            'is_active' => true,
        ]);
    }

    protected function viewOnlyUser(): User
    {
        $role = Role::create([
            'name' => 'Viewer',
            'slug' => 'viewer-'.Str::lower(Str::random(6)),
            'organization_id' => $this->organization->id,
            'is_system' => false,
            'permissions' => ['view_products'],
        ]);

        $user = User::create([
            'name' => 'Viewer',
            'email' => 'viewer-'.Str::lower(Str::random(6)).'@test.com',
            'password' => bcrypt('password'),
            'organization_id' => $this->organization->id,
            'role' => 'member',
        ]);
        $user->roles()->attach($role->id);

        return $user;
    }

    // ------------------------------------------------------------------
    // The REST API stays bearer-token only.
    // ------------------------------------------------------------------

    public function test_rest_api_does_not_accept_a_browser_session_cookie(): void
    {
        // The in-app components used to call /api/v1 with the session cookie
        // alone, which 401s in a real browser. They now use web routes; the
        // API deliberately stays token-only so a session that has not passed
        // two-factor cannot reach it (or mint tokens through it).
        $this->asBrowser($this->admin)
            ->postJson("/api/v1/products/{$this->batchProduct->id}/batches", ['quantity' => 5])
            ->assertStatus(401);
    }

    public function test_rest_api_still_accepts_a_bearer_token(): void
    {
        $token = $this->admin->createToken('client')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/products/{$this->batchProduct->id}/batches", ['quantity' => 5])
            ->assertStatus(201);
    }

    // ------------------------------------------------------------------
    // Batches (BatchList.vue)
    // ------------------------------------------------------------------

    public function test_browser_session_can_create_a_batch(): void
    {
        $response = $this->asBrowser($this->admin)
            ->postJson(route('products.batches.store', $this->batchProduct), [
                'quantity' => 5,
                'manufactured_date' => '2026-01-01',
                'expiry_date' => '2027-01-01',
                'notes' => 'From the product page',
            ]);

        // BatchList.vue reads response.data.data and renders these fields.
        $response->assertStatus(201)
            ->assertJsonStructure(['data' => ['id', 'batch_number', 'quantity', 'manufactured_date', 'expiry_date', 'notes']])
            ->assertJsonPath('data.quantity', 5)
            ->assertJsonPath('data.manufactured_date', '2026-01-01')
            ->assertJsonPath('data.expiry_date', '2027-01-01');

        $this->assertDatabaseHas('product_batches', [
            'product_id' => $this->batchProduct->id,
            'organization_id' => $this->organization->id,
            'quantity' => 5,
        ]);
    }

    public function test_unauthenticated_batch_create_is_rejected(): void
    {
        $this->postJson(route('products.batches.store', $this->batchProduct), ['quantity' => 5])
            ->assertStatus(401);

        $this->assertDatabaseCount('product_batches', 0);
    }

    public function test_batch_create_requires_edit_products(): void
    {
        $this->asBrowser($this->viewOnlyUser())
            ->postJson(route('products.batches.store', $this->batchProduct), ['quantity' => 5])
            ->assertStatus(403);

        $this->assertDatabaseCount('product_batches', 0);
    }

    public function test_user_of_another_org_cannot_create_a_batch_on_this_orgs_product(): void
    {
        [, $adminB] = $this->makeOrgB();

        $this->asBrowser($adminB)
            ->postJson(route('products.batches.store', $this->batchProduct), ['quantity' => 5])
            ->assertStatus(404);

        $this->assertDatabaseCount('product_batches', 0);
    }

    // ------------------------------------------------------------------
    // Serials (SerialList.vue)
    // ------------------------------------------------------------------

    public function test_browser_session_can_create_and_update_a_serial(): void
    {
        $product = $this->makeProduct(['tracking_type' => 'serial']);

        $created = $this->asBrowser($this->admin)
            ->postJson(route('products.serials.store', $product), [
                'serial_number' => 'SN-0001',
                'status' => 'available',
            ]);

        $created->assertStatus(201)
            ->assertJsonStructure(['data' => ['id', 'serial_number', 'status', 'notes']])
            ->assertJsonPath('data.serial_number', 'SN-0001');

        $serialId = $created->json('data.id');

        $this->asBrowser($this->admin)
            ->putJson(route('products.serials.update', [$product, $serialId]), ['status' => 'damaged'])
            ->assertOk()
            ->assertJsonPath('data.id', $serialId)
            ->assertJsonPath('data.status', 'damaged');
    }

    public function test_unauthenticated_serial_create_is_rejected(): void
    {
        $product = $this->makeProduct(['tracking_type' => 'serial']);

        $this->postJson(route('products.serials.store', $product), ['serial_number' => 'SN-X'])
            ->assertStatus(401);

        $this->assertDatabaseCount('product_serials', 0);
    }

    public function test_user_of_another_org_cannot_touch_this_orgs_serials(): void
    {
        $product = $this->makeProduct(['tracking_type' => 'serial']);
        $serial = ProductSerial::create([
            'organization_id' => $this->organization->id,
            'product_id' => $product->id,
            'serial_number' => 'SN-A-1',
            'status' => 'available',
        ]);
        [, $adminB] = $this->makeOrgB();

        $this->asBrowser($adminB)
            ->postJson(route('products.serials.store', $product), ['serial_number' => 'SN-B-1'])
            ->assertStatus(404);

        $this->asBrowser($adminB)
            ->putJson(route('products.serials.update', [$product, $serial]), ['status' => 'sold'])
            ->assertStatus(404);

        $this->assertSame('available', $serial->fresh()->status);
        $this->assertDatabaseCount('product_serials', 1);
    }

    // ------------------------------------------------------------------
    // Variant stock (VariantStockAdjuster.vue)
    // ------------------------------------------------------------------

    public function test_browser_session_can_adjust_variant_stock(): void
    {
        $product = $this->makeProduct(['has_variants' => true]);
        $variant = $this->makeVariant($product, 10);

        // The adjuster sends a signed delta: a decrease of 3 is -3.
        $this->asBrowser($this->admin)
            ->postJson(route('products.variants.adjust-stock', [$product, $variant]), [
                'quantity' => -3,
                'type' => 'decrease',
                'reason' => null,
            ])
            ->assertOk()
            ->assertJsonPath('data.id', $variant->id)
            ->assertJsonPath('data.stock', 7);

        $this->assertSame(7, $variant->fresh()->stock);
    }

    public function test_unauthenticated_variant_adjust_is_rejected(): void
    {
        $product = $this->makeProduct(['has_variants' => true]);
        $variant = $this->makeVariant($product, 10);

        $this->postJson(route('products.variants.adjust-stock', [$product, $variant]), [
            'quantity' => 5,
            'type' => 'increase',
        ])->assertStatus(401);

        $this->assertSame(10, $variant->fresh()->stock);
    }

    public function test_variant_adjust_requires_manage_stock(): void
    {
        $product = $this->makeProduct(['has_variants' => true]);
        $variant = $this->makeVariant($product, 10);

        $this->asBrowser($this->viewOnlyUser())
            ->postJson(route('products.variants.adjust-stock', [$product, $variant]), [
                'quantity' => 5,
                'type' => 'increase',
            ])
            ->assertStatus(403);

        $this->assertSame(10, $variant->fresh()->stock);
    }

    public function test_user_of_another_org_cannot_adjust_this_orgs_variant(): void
    {
        $product = $this->makeProduct(['has_variants' => true]);
        $variant = $this->makeVariant($product, 10);
        [, $adminB] = $this->makeOrgB();

        $this->asBrowser($adminB)
            ->postJson(route('products.variants.adjust-stock', [$product, $variant]), [
                'quantity' => 5,
                'type' => 'increase',
            ])
            ->assertStatus(404);

        $this->assertSame(10, $variant->fresh()->stock);
    }

    // ------------------------------------------------------------------
    // Barcode lookup (BarcodeScannerModal.vue)
    // ------------------------------------------------------------------

    public function test_browser_session_can_look_up_a_barcode(): void
    {
        $product = $this->makeProduct(['barcode' => '0123456789012', 'name' => 'Scanned', 'stock' => 4]);

        // BarcodeScannerModal.vue reads found and product.id/name/sku/stock.
        $this->asBrowser($this->admin)
            ->getJson(route('barcode.lookup', ['code' => '0123456789012']))
            ->assertOk()
            ->assertJsonPath('found', true)
            ->assertJsonPath('product.id', $product->id)
            ->assertJsonPath('product.name', 'Scanned')
            ->assertJsonPath('product.sku', $product->sku)
            ->assertJsonPath('product.stock', 4);
    }

    public function test_barcode_lookup_accepts_codes_containing_a_slash(): void
    {
        $product = $this->makeProduct(['sku' => 'AB/12']);

        $this->asBrowser($this->admin)
            ->getJson(route('barcode.lookup', ['code' => 'AB/12']))
            ->assertOk()
            ->assertJsonPath('product.id', $product->id);
    }

    public function test_barcode_lookup_requires_a_code(): void
    {
        $this->asBrowser($this->admin)
            ->getJson(route('barcode.lookup'))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['code']);
    }

    public function test_unauthenticated_barcode_lookup_is_rejected(): void
    {
        $this->getJson(route('barcode.lookup', ['code' => 'anything']))
            ->assertStatus(401);
    }

    public function test_barcode_lookup_does_not_find_another_orgs_product(): void
    {
        $this->makeProduct(['barcode' => 'ORG-A-CODE']);
        [, $adminB] = $this->makeOrgB();

        $this->asBrowser($adminB)
            ->getJson(route('barcode.lookup', ['code' => 'ORG-A-CODE']))
            ->assertStatus(404)
            ->assertJsonPath('found', false);
    }

    // ------------------------------------------------------------------
    // Two-factor still gates the session.
    // ------------------------------------------------------------------

    public function test_session_pending_two_factor_cannot_use_in_app_json_routes(): void
    {
        $this->admin->forceFill(['two_factor_enabled' => true, 'two_factor_secret' => 'secret'])->save();

        $this->asBrowser($this->admin)
            ->postJson(route('products.batches.store', $this->batchProduct), ['quantity' => 5])
            ->assertRedirect(route('two-factor.challenge'));

        $this->assertDatabaseCount('product_batches', 0);
    }
}
