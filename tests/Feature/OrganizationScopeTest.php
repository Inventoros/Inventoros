<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Scopes\OrganizationScope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrganizationScopeTest extends TestCase
{
    use RefreshDatabase;

    private Organization $orgA;

    private Organization $orgB;

    private User $userA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orgA = Organization::create(['name' => 'A', 'email' => 'a@org.com', 'currency' => 'USD', 'timezone' => 'UTC']);
        $this->orgB = Organization::create(['name' => 'B', 'email' => 'b@org.com', 'currency' => 'USD', 'timezone' => 'UTC']);

        $this->userA = User::create([
            'name' => 'A', 'email' => 'a@user.com', 'password' => bcrypt('x'),
            'organization_id' => $this->orgA->id, 'role' => 'admin',
        ]);

        $this->makeProduct($this->orgA, 'A-1');
        $this->makeProduct($this->orgB, 'B-1');
    }

    private function makeProduct(Organization $org, string $sku): Product
    {
        return Product::withoutGlobalScope(OrganizationScope::class)->create([
            'organization_id' => $org->id,
            'sku' => $sku,
            'name' => $sku,
            'price' => 1.00,
            'currency' => 'USD',
            'stock' => 1,
            'min_stock' => 0,
            'is_active' => true,
        ]);
    }

    public function test_authenticated_queries_see_only_their_org(): void
    {
        $this->actingAs($this->userA);

        $skus = Product::pluck('sku')->all();

        $this->assertContains('A-1', $skus);
        $this->assertNotContains('B-1', $skus);
    }

    public function test_guest_queries_are_not_scoped(): void
    {
        // No authenticated user (jobs, console, installer): scope is inert.
        $this->assertSame(2, Product::count());
    }

    public function test_without_global_scope_reaches_across_orgs(): void
    {
        $this->actingAs($this->userA);

        $this->assertSame(2, Product::withoutGlobalScope(OrganizationScope::class)->count());
    }

    public function test_creating_stamps_organization_from_authenticated_user(): void
    {
        $this->actingAs($this->userA);

        $product = Product::create([
            'sku' => 'NEW', 'name' => 'New', 'price' => 1.00,
            'currency' => 'USD', 'stock' => 1, 'min_stock' => 0, 'is_active' => true,
        ]);

        $this->assertSame($this->orgA->id, $product->organization_id);
    }

    public function test_authenticated_user_without_an_organization_sees_no_tenant_data(): void
    {
        // A self-registered account before onboarding assigns an org is
        // authenticated but has a null organization_id. The scope must fail
        // CLOSED — an org-less account (even an "admin") must not fall through
        // to an unscoped query that exposes every tenant's rows.
        $orphan = User::create([
            'name' => 'Orphan', 'email' => 'orphan@user.com', 'password' => bcrypt('x'),
            'organization_id' => null, 'role' => 'admin',
        ]);

        $this->actingAs($orphan);

        $this->assertSame(0, Product::count());
        $this->assertTrue(Product::pluck('sku')->isEmpty());
    }
}
