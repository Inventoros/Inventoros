<?php

declare(strict_types=1);

namespace Tests\Feature\ExchangeRates;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\Supplier;
use App\Models\Purchasing\PurchaseOrder;
use App\Models\Role;
use App\Models\System\SystemSetting;
use App\Models\User;
use App\Services\ExchangeRates\ExchangeRateProviderRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * Shared set-up for the exchange rate tests: a HUF organization buying in
 * EUR (the case in issue #420), users with and without the override
 * permission, and a faked Frankfurter.
 */
trait ExchangeRateFixtures
{
    protected function atTime(string $now = '2026-11-16 12:00:00'): void
    {
        Carbon::setTestNow($now);
        CarbonImmutable::setTestNow($now);
    }

    protected function tearDownExchangeRates(): void
    {
        app(ExchangeRateProviderRegistry::class)->clear();
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
    }

    protected function organization(string $name = 'Budapest Bikes', string $currency = 'HUF'): Organization
    {
        SystemSetting::set('installed', true, 'boolean');

        return Organization::create([
            'name' => $name,
            'email' => strtolower(str_replace(' ', '', $name)).'@example.com',
            'currency' => $currency,
            'timezone' => 'UTC',
        ]);
    }

    /**
     * @param  array<int, string>  $permissions  a custom role with these, for role "member"
     */
    protected function user(Organization $organization, string $role = 'admin', array $permissions = []): User
    {
        $user = User::create([
            'name' => ucfirst($role).' '.uniqid(),
            'email' => uniqid().'@'.$organization->id.'.test',
            'password' => bcrypt('x'),
            'organization_id' => $organization->id,
            'role' => $role,
        ]);

        if ($permissions !== []) {
            $custom = Role::create(['name' => 'Custom '.uniqid(), 'slug' => 'custom-'.uniqid(), 'permissions' => $permissions]);
            $user->roles()->attach($custom->id);
        }

        return $user;
    }

    protected function supplier(Organization $organization, string $currency = 'EUR'): Supplier
    {
        return Supplier::create([
            'organization_id' => $organization->id,
            'name' => 'Supplier '.$currency.' '.uniqid(),
            'email' => uniqid().'@supplier.test',
            'currency' => $currency,
            'is_active' => true,
        ]);
    }

    protected function product(Organization $organization, string $currency = 'HUF'): Product
    {
        return Product::create([
            'organization_id' => $organization->id,
            'sku' => 'SKU-'.uniqid(),
            'name' => 'Brake pads',
            'price' => 9000,
            'purchase_price' => 3000,
            'currency' => $currency,
            'stock' => 0,
            'min_stock' => 0,
            'is_active' => true,
        ]);
    }

    protected function fakeFrankfurter(float $rate = 364.35, string $date = '2026-11-16', string $base = 'EUR', string $quote = 'HUF'): void
    {
        Http::fake(['api.frankfurter.dev/*' => Http::response(['amount' => 1.0, 'base' => $base, 'date' => $date, 'rates' => [$quote => $rate]])]);
    }

    /**
     * A PO saved straight to the table, as older rows are: no snapshot.
     */
    protected function legacyPurchaseOrder(Organization $organization, Supplier $supplier, Product $product, string $currency, string $status = PurchaseOrder::STATUS_SENT, string $unitCost = '10.00', int $quantity = 5): PurchaseOrder
    {
        $total = number_format((float) $unitCost * $quantity, 2, '.', '');

        $po = PurchaseOrder::create([
            'organization_id' => $organization->id,
            'supplier_id' => $supplier->id,
            'po_number' => 'PO-LEGACY-'.uniqid(),
            'status' => $status,
            'order_date' => '2026-10-01',
            'subtotal' => $total,
            'tax' => 0,
            'shipping' => 0,
            'total' => $total,
            'currency' => $currency,
        ]);

        $po->items()->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'sku' => $product->sku,
            'quantity_ordered' => $quantity,
            'quantity_received' => 0,
            'unit_cost' => $unitCost,
            'subtotal' => $total,
            'tax' => 0,
            'total' => $total,
        ]);

        return $po;
    }
}
