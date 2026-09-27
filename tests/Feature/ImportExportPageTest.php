<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\System\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Import/Export page exposes products, orders and users.
 */
final class ImportExportPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_page_carries_what_the_order_and_currency_sections_need(): void
    {
        SystemSetting::set('installed', true, 'boolean');
        $org = Organization::create(['name' => 'Org', 'email' => 'o@org.com', 'currency' => 'USD', 'timezone' => 'UTC']);
        $admin = User::create([
            'name' => 'Admin', 'email' => 'admin@org.com', 'password' => bcrypt('x'),
            'organization_id' => $org->id, 'role' => 'admin',
        ]);
        Product::create([
            'organization_id' => $org->id, 'sku' => 'P-1', 'name' => 'P', 'price' => 1, 'currency' => 'USD',
            'stock' => 0, 'min_stock' => 0, 'price_in_currencies' => ['GBP' => 1],
        ]);

        $this->actingAs($admin)->get(route('import-export.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('ImportExport/Index')
                ->where('currencyColumns', ['price_GBP'])
                ->where('orderStatuses', ['pending', 'processing', 'shipped', 'delivered', 'cancelled'])
            );
    }
}
