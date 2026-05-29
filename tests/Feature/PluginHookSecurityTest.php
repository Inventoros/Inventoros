<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Scopes\OrganizationScope;
use App\Models\System\SystemSetting;
use App\Models\User;
use App\Services\HookRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P1-6 — plugin hook contract hardening.
 *
 * Plugin filters receive raw Eloquent builders and could otherwise widen them
 * past the caller's tenant, and the permission resolver must not be rewritable
 * by a plugin. These tests pin both boundaries.
 */
class PluginHookSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $orgA;

    protected Organization $orgB;

    protected User $userA;

    protected function setUp(): void
    {
        parent::setUp();

        SystemSetting::set('installed', true, 'boolean');

        $this->orgA = Organization::create([
            'name' => 'Org A', 'email' => 'a@org.com', 'currency' => 'USD', 'timezone' => 'UTC',
        ]);
        $this->orgB = Organization::create([
            'name' => 'Org B', 'email' => 'b@org.com', 'currency' => 'USD', 'timezone' => 'UTC',
        ]);

        $this->userA = User::create([
            'name' => 'A', 'email' => 'a@user.com', 'password' => bcrypt('password'),
            'organization_id' => $this->orgA->id, 'role' => 'admin',
        ]);
    }

    public function test_plugin_list_query_filter_cannot_leak_other_tenants(): void
    {
        Product::create([
            'organization_id' => $this->orgA->id, 'sku' => 'A-1', 'name' => 'A Product',
            'price' => 1, 'currency' => 'USD', 'stock' => 1, 'min_stock' => 0, 'is_active' => true,
        ]);
        Product::create([
            'organization_id' => $this->orgB->id, 'sku' => 'B-1', 'name' => 'B Product',
            'price' => 1, 'currency' => 'USD', 'stock' => 1, 'min_stock' => 0, 'is_active' => true,
        ]);

        // A hostile plugin strips the tenant scope inside the list-query filter.
        add_filter('product_list_query', function ($query) {
            return $query->withoutGlobalScope(OrganizationScope::class);
        });

        $response = $this->actingAs($this->userA)->get(route('products.index'));

        // The guard re-asserts org isolation after the filter: only org A's row.
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->has('products.data', 1));
    }

    public function test_permission_resolution_ignores_user_permissions_filter(): void
    {
        $member = User::create([
            'name' => 'Member', 'email' => 'member@a.com', 'password' => bcrypt('password'),
            'organization_id' => $this->orgA->id, 'role' => 'member',
        ]);

        // A hostile plugin attempts to self-grant an admin permission.
        add_filter('user_permissions', function ($permissions) {
            $permissions[] = 'manage_organization';

            return $permissions;
        });

        $this->assertNotContains('manage_organization', $member->getAllPermissions());
    }

    public function test_user_permissions_hook_is_not_advertised(): void
    {
        $this->assertArrayNotHasKey('user_permissions', HookRegistry::getFilters());
    }
}
