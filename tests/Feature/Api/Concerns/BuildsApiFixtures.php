<?php

declare(strict_types=1);

namespace Tests\Feature\Api\Concerns;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductLocation;
use App\Models\Role;
use App\Models\System\SystemSetting;
use App\Models\User;

/**
 * Tenant fixtures for the REST parity tests: organizations, an admin, a
 * member holding only the named permissions, and basic inventory records.
 */
trait BuildsApiFixtures
{
    protected function markInstalled(): void
    {
        SystemSetting::set('installed', true, 'boolean');
    }

    protected function makeOrganization(string $name = 'Org'): Organization
    {
        return Organization::create([
            'name' => $name,
            'email' => strtolower(str_replace(' ', '', $name)).uniqid().'@org.test',
            'currency' => 'USD',
            'timezone' => 'UTC',
        ]);
    }

    protected function makeAdmin(Organization $org): User
    {
        return User::create([
            'name' => 'Admin '.$org->name,
            'email' => 'admin'.uniqid().'@org.test',
            'password' => bcrypt('password'),
            'organization_id' => $org->id,
            'role' => 'admin',
        ]);
    }

    /**
     * A plain member whose only permissions come from one custom role.
     *
     * @param  array<int, string>  $permissions
     */
    protected function makeMember(Organization $org, array $permissions = []): User
    {
        $user = User::create([
            'name' => 'Member',
            'email' => 'member'.uniqid().'@org.test',
            'password' => bcrypt('password'),
            'organization_id' => $org->id,
            'role' => 'member',
        ]);

        if ($permissions !== []) {
            $role = Role::create([
                'name' => 'Custom '.uniqid(),
                'slug' => 'custom-'.uniqid(),
                'organization_id' => $org->id,
                'permissions' => $permissions,
                'is_system' => false,
            ]);
            $user->roles()->attach($role->id);
        }

        return $user;
    }

    protected function makeLocation(Organization $org, string $name = 'Bin'): ProductLocation
    {
        return ProductLocation::create([
            'organization_id' => $org->id,
            'name' => $name,
            'code' => strtoupper(substr($name, 0, 3)).uniqid(),
            'is_active' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function makeProduct(Organization $org, array $attributes = []): Product
    {
        return Product::create(array_merge([
            'organization_id' => $org->id,
            'sku' => 'SKU-'.uniqid(),
            'name' => 'Product '.uniqid(),
            'price' => 10.00,
            'currency' => 'USD',
            'stock' => 50,
            'min_stock' => 1,
            'is_active' => true,
        ], $attributes));
    }
}
