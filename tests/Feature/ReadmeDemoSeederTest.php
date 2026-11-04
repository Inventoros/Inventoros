<?php

namespace Tests\Feature;

use App\Models\Inventory\Product;
use App\Models\Purchasing\PurchaseOrder;
use App\Models\User;
use App\Support\SchedulerHealth;
use Database\Seeders\ReadmeDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The README demo GIFs (scripts/generate-demo.js) depend on these records.
 */
class ReadmeDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_the_records_the_demo_flows_use(): void
    {
        $this->seed(ReadmeDemoSeeder::class);

        $user = User::where('email', ReadmeDemoSeeder::EMAIL)->firstOrFail();
        $orgId = $user->organization_id;

        $lowStock = Product::withoutGlobalScopes()->where('organization_id', $orgId)
            ->whereColumn('stock', '<', 'min_stock')->pluck('sku')->all();
        $this->assertSame(['AF-1001'], $lowStock, 'The receiving demo starts from exactly one low-stock product');

        $po = PurchaseOrder::withoutGlobalScopes()->where('organization_id', $orgId)
            ->where('po_number', ReadmeDemoSeeder::RESTOCK_PO)->with('items')->firstOrFail();
        $this->assertSame('sent', $po->status);
        $laptopStand = $po->items->firstWhere('sku', 'AF-1001');
        $this->assertNotNull($laptopStand);
        $this->assertGreaterThanOrEqual(12 - 4, $laptopStand->quantity_ordered, 'Receiving the PO clears the low stock');

        $total = '0.00';
        foreach ($po->items as $item) {
            $total = bcadd($total, (string) $item->total, 2);
        }
        $this->assertSame((string) $po->total, $total);

        $this->assertSame([], app(SchedulerHealth::class)->warnings());
    }

    public function test_the_demo_user_can_sign_in_and_see_the_dashboard(): void
    {
        $this->seed(ReadmeDemoSeeder::class);

        $this->post('/login', ['email' => ReadmeDemoSeeder::EMAIL, 'password' => ReadmeDemoSeeder::PASSWORD])
            ->assertRedirect();
        $this->assertAuthenticated();
        $this->get('/dashboard')->assertOk();
    }

    public function test_it_can_run_twice(): void
    {
        $this->seed(ReadmeDemoSeeder::class);
        $this->seed(ReadmeDemoSeeder::class);

        $this->assertSame(1, User::where('email', ReadmeDemoSeeder::EMAIL)->count());
    }
}
