<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Order\Order;
use App\Models\Role;
use App\Models\System\SystemSetting;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\OrderService;

/**
 * Shared fixtures for the shipping tests: an organization with an admin, a
 * warehouse, two products, and an order created through OrderService (so stock
 * has already been decremented, exactly as in production).
 */
trait ShippingTestHelpers
{
    protected Organization $organization;

    protected User $admin;

    protected Warehouse $warehouse;

    protected Product $widget;

    protected Product $gadget;

    protected function setUpShipping(): void
    {
        SystemSetting::set('installed', true, 'boolean');

        $this->organization = Organization::create([
            'name' => 'Ship Org', 'email' => 'ship@org.test', 'currency' => 'USD', 'timezone' => 'UTC',
        ]);

        $this->admin = User::create([
            'name' => 'Admin', 'email' => 'admin@ship.test', 'password' => bcrypt('password'),
            'organization_id' => $this->organization->id, 'role' => 'admin',
        ]);

        $this->warehouse = Warehouse::create([
            'organization_id' => $this->organization->id, 'name' => 'Main', 'code' => 'MAIN',
            'address_line_1' => '1 Dock St', 'city' => 'Portland', 'province' => 'OR',
            'postal_code' => '97201', 'country' => 'US', 'phone' => '5035550100',
            'is_default' => true, 'is_active' => true,
        ]);

        $this->widget = $this->makeProduct('WID-1', 'Widget');
        $this->gadget = $this->makeProduct('GAD-1', 'Gadget');
    }

    protected function makeProduct(string $sku, string $name, ?int $orgId = null): Product
    {
        return Product::create([
            'organization_id' => $orgId ?? $this->organization->id,
            'sku' => $sku, 'name' => $name, 'price' => 10, 'currency' => 'USD',
            'stock' => 100, 'min_stock' => 0, 'is_active' => true,
        ]);
    }

    /**
     * @param  array<int, array{0: Product, 1: int}>  $lines
     */
    protected function makeOrder(array $lines = [], array $overrides = []): Order
    {
        $lines = $lines ?: [[$this->widget, 3], [$this->gadget, 2]];

        return app(OrderService::class)->create(array_merge([
            'customer_name' => 'Casey Customer',
            'customer_email' => 'casey@customer.test',
            'customer_address' => "388 Townsend St\nSan Francisco, CA 94107",
            'status' => 'pending',
            'order_date' => now()->toDateString(),
            'warehouse_id' => $this->warehouse->id,
            'items' => array_map(fn ($l) => [
                'product_id' => $l[0]->id, 'quantity' => $l[1], 'unit_price' => 10,
            ], $lines),
        ], $overrides), $this->admin)->fresh('items');
    }

    /**
     * A non-admin member holding exactly the given permissions.
     *
     * @param  array<int, string>  $permissions
     */
    protected function memberWith(array $permissions, string $email = 'member@ship.test'): User
    {
        $role = Role::create([
            'name' => 'Custom '.$email, 'slug' => 'custom-'.md5($email), 'is_system' => false,
            'organization_id' => $this->organization->id, 'permissions' => $permissions,
        ]);

        $user = User::create([
            'name' => 'Member', 'email' => $email, 'password' => bcrypt('password'),
            'organization_id' => $this->organization->id, 'role' => 'member',
        ]);
        $user->roles()->attach($role->id);

        return $user;
    }
}
