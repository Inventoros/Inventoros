<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductBatch;
use App\Models\Inventory\ProductLocation;
use App\Models\Inventory\ProductLocationStock;
use App\Models\Inventory\ProductSerial;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The per-location and tracking tables carry organization_id but relied purely
 * on hand-written scoping. They now apply OrganizationScope as a backstop, so a
 * future controller that binds one directly can't leak another tenant's rows.
 */
class NewTenantTablesOrgScopeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Create an org with one product that has a bin, a serial, and a batch.
     *
     * @return array{0: Organization, 1: User, 2: ProductSerial}
     */
    private function seedOrg(string $slug): array
    {
        $org = Organization::create([
            'name' => $slug, 'email' => "{$slug}@org.com", 'currency' => 'USD', 'timezone' => 'UTC',
        ]);
        $user = User::create([
            'name' => $slug, 'email' => "u-{$slug}@org.com", 'password' => bcrypt('x'),
            'organization_id' => $org->id, 'role' => 'admin',
        ]);
        $location = ProductLocation::create([
            'organization_id' => $org->id, 'name' => "L-{$slug}", 'code' => "L{$slug}", 'is_active' => true,
        ]);
        $product = Product::create([
            'organization_id' => $org->id, 'sku' => "P-{$slug}", 'name' => "P-{$slug}",
            'price' => 10, 'currency' => 'USD', 'stock' => 5, 'location_id' => $location->id, 'is_active' => true,
        ]);
        ProductLocationStock::create([
            'organization_id' => $org->id, 'product_id' => $product->id, 'location_id' => $location->id, 'quantity' => 5,
        ]);
        $serial = ProductSerial::create([
            'organization_id' => $org->id, 'product_id' => $product->id,
            'serial_number' => "SN-{$slug}", 'status' => 'available',
        ]);
        ProductBatch::create([
            'organization_id' => $org->id, 'product_id' => $product->id, 'batch_number' => "B-{$slug}", 'quantity' => 5,
        ]);

        return [$org, $user, $serial];
    }

    public function test_the_scope_hides_other_tenants_rows_from_an_authenticated_user(): void
    {
        [, $userA] = $this->seedOrg('a');
        [, , $serialB] = $this->seedOrg('b');

        $this->actingAs($userA);

        // Each unscoped table would show 2 rows (one per org); the scope narrows
        // every one of them to org A.
        $this->assertSame(1, ProductLocationStock::count());
        $this->assertSame(1, ProductSerial::count());
        $this->assertSame(1, ProductBatch::count());
        $this->assertSame(1, ProductLocation::count());

        // A direct lookup of org B's serial by id returns nothing — the backstop
        // fails closed even against an explicit id.
        $this->assertNull(ProductSerial::find($serialB->id));
    }

    public function test_console_context_still_sees_every_tenant(): void
    {
        $this->seedOrg('a');
        $this->seedOrg('b');

        // No authenticated user (console/reconcile/backfill): the scope skips, so
        // cross-tenant maintenance still works.
        $this->assertSame(2, ProductLocationStock::count());
        $this->assertSame(2, ProductSerial::count());
    }
}
