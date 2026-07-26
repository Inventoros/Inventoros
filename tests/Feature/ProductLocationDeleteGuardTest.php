<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductLocation;
use App\Models\Inventory\ProductLocationStock;
use App\Models\Role;
use App\Models\System\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A location that still holds stock in a bin must not be deletable — locations
 * are soft-deleted, so the product_location_stocks cascade never fires and the
 * stock would be stranded against a trashed location.
 */
class ProductLocationDeleteGuardTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        SystemSetting::set('installed', true, 'boolean');

        $this->org = Organization::create([
            'name' => 'Loc Org', 'email' => 'l@org.com', 'currency' => 'USD', 'timezone' => 'UTC',
        ]);
        $this->admin = User::create([
            'name' => 'Admin', 'email' => 'admin@l.com', 'password' => bcrypt('x'),
            'organization_id' => $this->org->id, 'role' => 'admin',
        ]);
        $role = Role::firstOrCreate(
            ['slug' => 'loc-admin'],
            ['name' => 'Loc Admin', 'is_system' => false, 'permissions' => ['manage_locations']],
        );
        $this->admin->roles()->syncWithoutDetaching([$role->id]);
    }

    public function test_cannot_delete_a_location_that_still_holds_stock_in_a_bin(): void
    {
        $primary = ProductLocation::create(['organization_id' => $this->org->id, 'name' => 'A', 'code' => 'A', 'is_active' => true]);
        $overflow = ProductLocation::create(['organization_id' => $this->org->id, 'name' => 'B', 'code' => 'B', 'is_active' => true]);

        // Product's primary location is A, but 5 units sit in a bin at B (e.g.
        // after a transfer). B is nobody's primary, but it holds stock.
        $product = Product::create([
            'organization_id' => $this->org->id, 'sku' => 'P-1', 'name' => 'P',
            'price' => 10, 'currency' => 'USD', 'stock' => 5, 'location_id' => $primary->id, 'is_active' => true,
        ]);
        ProductLocationStock::create([
            'organization_id' => $this->org->id, 'product_id' => $product->id,
            'location_id' => $overflow->id, 'quantity' => 5,
        ]);

        $this->actingAs($this->admin)
            ->delete(route('locations.destroy', $overflow))
            ->assertSessionHasErrors('location');

        $this->assertDatabaseHas('product_locations', ['id' => $overflow->id, 'deleted_at' => null]);
    }

    public function test_can_delete_an_empty_location(): void
    {
        $empty = ProductLocation::create(['organization_id' => $this->org->id, 'name' => 'Empty', 'code' => 'E', 'is_active' => true]);

        $this->actingAs($this->admin)
            ->delete(route('locations.destroy', $empty))
            ->assertSessionHas('success');

        $this->assertSoftDeleted('product_locations', ['id' => $empty->id]);
    }
}
