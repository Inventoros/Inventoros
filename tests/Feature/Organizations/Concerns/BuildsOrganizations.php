<?php

declare(strict_types=1);

namespace Tests\Feature\Organizations\Concerns;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Role;
use App\Models\Scopes\OrganizationScope;
use App\Models\System\SystemSetting;
use App\Models\User;
use App\Services\Organizations\OrganizationMembershipService;

/**
 * Two organizations, A (the user's home) and B (a further membership), with
 * one product each, for the cross-organization isolation tests.
 */
trait BuildsOrganizations
{
    protected function markInstalled(): void
    {
        SystemSetting::set('installed', true, 'boolean');
    }

    protected function organization(string $name, string $currency = 'USD'): Organization
    {
        return Organization::create([
            'name' => $name,
            'email' => strtolower(str_replace(' ', '', $name)).uniqid().'@org.test',
            'currency' => $currency,
            'timezone' => 'UTC',
            'is_active' => true,
        ]);
    }

    protected function homeUser(Organization $organization, string $role = 'admin', string $name = 'Pat'): User
    {
        return User::create([
            'name' => $name,
            'email' => strtolower($name).uniqid().'@user.test',
            'password' => bcrypt('password'),
            'organization_id' => $organization->id,
            'role' => $role,
            'email_verified_at' => now(),
        ]);
    }

    protected function addMember(Organization $organization, User $user, string $role = 'member'): void
    {
        app(OrganizationMembershipService::class)->add($organization, $user, $role);
    }

    /**
     * A custom role of $organization holding $permissions, held by $user there.
     *
     * @param  array<int, string>  $permissions
     */
    protected function grantInOrganization(User $user, Organization $organization, array $permissions): Role
    {
        $role = Role::create([
            'name' => 'Custom '.uniqid(),
            'slug' => 'custom-'.uniqid(),
            'organization_id' => $organization->id,
            'permissions' => $permissions,
            'is_system' => false,
        ]);

        \Illuminate\Support\Facades\DB::table('role_user')->insert([
            'role_id' => $role->id,
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $role;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function product(Organization $organization, string $sku, array $attributes = []): Product
    {
        return Product::withoutGlobalScope(OrganizationScope::class)->create(array_merge([
            'organization_id' => $organization->id,
            'sku' => $sku,
            'name' => 'Product '.$sku,
            'price' => 10.00,
            'currency' => $organization->currency ?? 'USD',
            'stock' => 50,
            'min_stock' => 1,
            'is_active' => true,
        ], $attributes));
    }
}
