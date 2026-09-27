<?php

declare(strict_types=1);

namespace Tests\Feature\Portal;

use App\Enums\OrderApprovalStatus;
use App\Enums\OrderStatus;
use App\Models\Auth\Organization;
use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\Inventory\Product;
use App\Models\Order\Order;
use App\Models\Order\OrderItem;
use App\Models\Role;
use App\Models\System\SystemSetting;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * Shared fixtures for the customer portal tests: organizations with the
 * portal switched on, customers, contacts, staff users and orders, all
 * created explicitly (no auth context) so no global scope is involved.
 */
trait BuildsPortalFixtures
{
    protected function markInstalled(): void
    {
        SystemSetting::set('installed', true, 'boolean');
    }

    protected function makeOrganization(string $name, bool $portalEnabled = true): Organization
    {
        $organization = Organization::create([
            'name' => $name,
            'email' => strtolower(str_replace(' ', '', $name)).'@example.test',
            'currency' => 'USD',
            'timezone' => 'UTC',
        ]);

        $organization->forceFill(['portal_enabled' => $portalEnabled])->save();

        return $organization->refresh();
    }

    protected function makeCustomer(Organization $organization, string $name, array $attributes = []): Customer
    {
        return Customer::create(array_merge([
            'organization_id' => $organization->id,
            'name' => $name,
            'email' => strtolower(str_replace(' ', '', $name)).'@customer.test',
            'currency' => 'USD',
            'is_active' => true,
        ], $attributes));
    }

    protected function makeContact(Customer $customer, string $email, string $password = 'secret-password', array $attributes = []): CustomerContact
    {
        return CustomerContact::create(array_merge([
            'organization_id' => $customer->organization_id,
            'customer_id' => $customer->id,
            'name' => 'Contact '.$email,
            'email' => $email,
            'password' => $password === '' ? null : Hash::make($password),
            'invited_at' => now()->subDay(),
            'activated_at' => $password === '' ? null : now()->subDay(),
        ], $attributes));
    }

    protected function makeStaff(Organization $organization, string $email, string $role = 'admin'): User
    {
        $user = User::create([
            'name' => 'Staff '.$email,
            'email' => $email,
            'password' => bcrypt('password'),
            'organization_id' => $organization->id,
        ]);
        $user->forceFill(['role' => $role])->save();

        return $user;
    }

    /**
     * A staff member whose only permissions come from a custom role.
     *
     * @param  array<int, string>  $permissions
     */
    protected function makeStaffWithPermissions(Organization $organization, string $email, array $permissions): User
    {
        $user = $this->makeStaff($organization, $email, 'member');

        $role = Role::create([
            'organization_id' => $organization->id,
            'name' => 'Custom '.$email,
            'slug' => 'custom-'.md5($email),
            'is_system' => false,
            'permissions' => $permissions,
        ]);
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    protected function makeProduct(Organization $organization, string $sku, int $stock = 100): Product
    {
        return Product::create([
            'organization_id' => $organization->id,
            'sku' => $sku,
            'name' => 'Product '.$sku,
            'price' => 10,
            'currency' => 'USD',
            'stock' => $stock,
            'min_stock' => 0,
            'is_active' => true,
        ]);
    }

    /**
     * @param  array<int, array{product: Product, quantity: int, unit_price?: float}>  $lines
     */
    protected function makeOrder(Customer $customer, string $number, OrderStatus $status = OrderStatus::DELIVERED, array $lines = [], array $attributes = []): Order
    {
        $order = Order::create(array_merge([
            'organization_id' => $customer->organization_id,
            'customer_id' => $customer->id,
            'order_number' => $number,
            'source' => 'manual',
            'customer_name' => $customer->name,
            'customer_email' => $customer->email,
            'status' => $status,
            'approval_status' => OrderApprovalStatus::APPROVED,
            'subtotal' => 0,
            'tax' => 0,
            'shipping' => 0,
            'total' => 0,
            'currency' => 'USD',
            'order_date' => now()->subDays(3),
            'notes' => 'Internal staff note for '.$number,
        ], $attributes));

        $subtotal = 0;
        foreach ($lines as $line) {
            $price = $line['unit_price'] ?? 10;
            $lineTotal = $price * $line['quantity'];
            $subtotal += $lineTotal;

            OrderItem::create([
                'order_id' => $order->id,
                'product_id' => $line['product']->id,
                'product_name' => $line['product']->name,
                'sku' => $line['product']->sku,
                'quantity' => $line['quantity'],
                'unit_price' => $price,
                'subtotal' => $lineTotal,
                'tax' => 0,
                'total' => $lineTotal,
            ]);
        }

        $order->forceFill(['subtotal' => $subtotal, 'total' => $subtotal])->save();

        return $order->refresh();
    }

    protected function portalUrl(Organization $organization, string $path = ''): string
    {
        return '/portal/'.$organization->slug.($path === '' ? '' : '/'.ltrim($path, '/'));
    }
}
