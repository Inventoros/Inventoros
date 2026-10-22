<?php

declare(strict_types=1);

namespace Tests\Feature\Reservations;

use App\Mcp\Servers\InventorosServer;
use App\Mcp\Tools\GetProductTool;
use App\Mcp\Tools\ListProductsTool;
use App\Models\ActivityLog;
use App\Models\Auth\Organization;
use App\Models\Inventory\StockReservation;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * On hand / reserved / available on the product page, the REST resource,
 * GraphQL and MCP, the display filter, and the admin list of holds with its
 * permission-gated release.
 */
final class ReservationVisibilityTest extends TestCase
{
    use RefreshDatabase, ReservationFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpReservationWorld();
        $this->withoutVite();
    }

    private function member(array $permissions): User
    {
        $role = Role::create([
            'slug' => 'r-'.uniqid(), 'name' => 'Role '.uniqid(), 'is_system' => false,
            'organization_id' => $this->org->id, 'permissions' => $permissions,
        ]);
        $user = User::create([
            'name' => 'Member', 'email' => uniqid().'@hire.test', 'password' => bcrypt('x'),
            'organization_id' => $this->org->id, 'role' => 'member',
        ]);
        $user->roles()->syncWithoutDetaching([$role->id]);

        return $user;
    }

    public function test_product_page_shows_on_hand_reserved_and_available(): void
    {
        $this->hold(4);

        $this->actingAs($this->admin)->get(route('products.show', $this->product))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('stockAvailability.on_hand', 10)
                ->where('stockAvailability.reserved', 4)
                ->where('stockAvailability.available', 6));
    }

    public function test_variant_rows_carry_their_own_availability(): void
    {
        $variant = $this->makeVariantProduct('SHIRT', 8);
        $this->reservations()->reserve($variant->product, $variant, 3, 'shop', 'CART');

        $this->actingAs($this->admin)->get(route('products.show', $variant->product))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('stockAvailability.reserved', 3)
                ->where('stockAvailability.available', 5)
                ->where("stockAvailability.variants.{$variant->id}.on_hand", 8)
                ->where("stockAvailability.variants.{$variant->id}.reserved", 3)
                ->where("stockAvailability.variants.{$variant->id}.available", 5));
    }

    public function test_rest_resource_exposes_reserved_and_available_stock(): void
    {
        $this->hold(4);
        Sanctum::actingAs($this->admin, ['*']);

        $this->getJson("/api/v1/products/{$this->product->id}")
            ->assertOk()
            ->assertJsonPath('data.stock', 10)
            ->assertJsonPath('data.reserved_stock', 4)
            ->assertJsonPath('data.available_stock', 6);

        $this->getJson('/api/v1/products')
            ->assertOk()
            ->assertJsonPath('data.0.available_stock', 6);
    }

    public function test_graphql_exposes_reserved_and_available_stock(): void
    {
        $variant = $this->makeVariantProduct('SHIRT', 8);
        $this->reservations()->reserve($variant->product, $variant, 3, 'shop', 'CART');
        $this->hold(4);
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->postJson('/graphql', ['query' => sprintf(
            '{ product(id: %d) { stock reserved_stock available_stock } productVariants(product_id: %d) { id reserved_stock available_stock } }',
            $this->product->id, $variant->product_id,
        )]);

        $this->assertNull($response->json('errors'), (string) json_encode($response->json('errors')));
        $response->assertJsonPath('data.product.reserved_stock', 4)
            ->assertJsonPath('data.product.available_stock', 6)
            ->assertJsonPath('data.productVariants.0.available_stock', 5);
    }

    public function test_mcp_product_payloads_expose_available_stock(): void
    {
        $this->hold(4);

        InventorosServer::actingAs($this->admin)
            ->tool(GetProductTool::class, ['id' => $this->product->id])
            ->assertOk()
            ->assertSee('"available_stock":6');

        $this->app['auth']->forgetGuards();
        InventorosServer::actingAs($this->admin)
            ->tool(ListProductsTool::class, [])
            ->assertOk()
            ->assertSee('"reserved_stock":4');
    }

    public function test_the_display_filter_changes_what_is_shown_but_not_the_checks(): void
    {
        $this->hold(4);
        add_filter('product_available_quantity', fn ($available, $product, $variant) => $variant === null ? $available - 2 : $available);

        $this->assertSame(4, $this->product->fresh()->available_stock);
        $this->assertSame(6, $this->reservations()->available($this->product));

        // An order for all 6 available still goes through.
        app(\App\Services\OrderService::class)->create([
            'customer_name' => 'Walk-in', 'items' => [['product_id' => $this->product->id, 'quantity' => 6, 'unit_price' => '10.00']],
        ], $this->admin);
        $this->assertSame(4, $this->stockOf());
    }

    public function test_the_admin_list_shows_active_holds_with_owner_reference_and_expiry(): void
    {
        $this->reservations()->reserve($this->product, null, 2, 'rental', 'B-77', expiresAt: now()->addDay());
        $this->hold(1, 'OLD');
        $this->reservations()->release('rental', 'OLD');

        $this->actingAs($this->admin)->get(route('reservations.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Reservations/Index')
                ->has('reservations.data', 1)
                ->where('reservations.data.0.owner_type', 'rental')
                ->where('reservations.data.0.owner_reference', 'B-77')
                ->where('reservations.data.0.quantity', 2)
                ->where('reservations.data.0.status', 'active')
                ->where('reservations.data.0.product.id', $this->product->id)
                ->whereNot('reservations.data.0.expires_at', null)
                ->where('canRelease', true));

        $this->actingAs($this->admin)->get(route('reservations.index', ['status' => 'all']))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('reservations.data', 2));
    }

    public function test_the_list_is_tenant_scoped(): void
    {
        $other = Organization::create(['name' => 'Other', 'email' => 'o@other.test', 'currency' => 'USD', 'timezone' => 'UTC']);
        $theirs = $this->hold(5, 'THEIRS', product: $this->makeProduct('OTHER', 10, [], [], $other));
        $this->hold(2, 'MINE');

        $this->actingAs($this->admin)->get(route('reservations.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('reservations.data', 1)
                ->where('reservations.data.0.owner_reference', 'MINE'));

        $this->actingAs($this->admin)->post(route('reservations.release', $theirs->id))->assertNotFound();
        $this->assertTrue($theirs->fresh()->isActive());
    }

    public function test_release_needs_manage_stock(): void
    {
        $hold = $this->hold(3);
        $viewer = $this->member(['view_products']);

        $this->actingAs($viewer)->get(route('reservations.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('canRelease', false));
        $this->actingAs($viewer)->post(route('reservations.release', $hold))->assertForbidden();
        $this->assertTrue($hold->fresh()->isActive());

        $this->actingAs($this->member(['view_orders']))->get(route('reservations.index'))->assertForbidden();
    }

    public function test_a_stock_manager_releases_a_hold_and_the_owner_hears_about_it(): void
    {
        $hold = $this->hold(3);
        $manager = $this->member(['view_products', 'manage_stock']);
        $released = [];
        add_action('stock_reservation_released', function (StockReservation $reservation) use (&$released) {
            $released[] = [$reservation->id, $reservation->release_reason];
        });

        $this->actingAs($manager)->post(route('reservations.release', $hold))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(StockReservation::ADMIN, $hold->fresh()->release_reason);
        $this->assertSame([[$hold->id, StockReservation::ADMIN]], $released);
        $this->assertSame(10, $this->reservations()->available($this->product));
        $this->assertTrue(ActivityLog::where('subject_type', StockReservation::class)->where('subject_id', $hold->id)->where('action', 'released')->exists());

        // Releasing it again does nothing.
        $this->actingAs($manager)->post(route('reservations.release', $hold))->assertSessionHas('error');
    }
}
