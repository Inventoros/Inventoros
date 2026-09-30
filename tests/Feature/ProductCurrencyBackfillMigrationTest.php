<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Auth\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Before 2.0.0, products created through REST, GraphQL, MCP or the products
 * import without a currency were stored as USD whatever the organization's
 * currency. Orders now price a line from the product's own price only when
 * the order is in the product's currency, so those products would make an
 * API order without unit_price fail in a non-USD organization. The upgrade
 * moves them to the organization's currency.
 */
class ProductCurrencyBackfillMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'migrations/2026_09_28_102816_move_default_usd_products_to_organization_currency.php';

    private function product(int $orgId, string $sku, string $currency): int
    {
        return DB::table('products')->insertGetId([
            'organization_id' => $orgId, 'sku' => $sku, 'name' => $sku, 'price' => 10,
            'currency' => $currency, 'stock' => 0, 'min_stock' => 0, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_usd_products_in_a_non_usd_organization_move_to_its_currency(): void
    {
        $cad = Organization::create(['name' => 'Maple', 'email' => 'maple@org.com', 'currency' => 'CAD', 'timezone' => 'UTC']);
        $usd = Organization::create(['name' => 'Eagle', 'email' => 'eagle@org.com', 'currency' => 'USD', 'timezone' => 'UTC']);
        $blank = Organization::create(['name' => 'Blank', 'email' => 'blank@org.com', 'timezone' => 'UTC']);
        DB::table('organizations')->where('id', $blank->id)->update(['currency' => null]);

        $defaulted = $this->product($cad->id, 'CAD-USD', 'USD');
        $eur = $this->product($cad->id, 'CAD-EUR', 'EUR');
        $already = $this->product($cad->id, 'CAD-CAD', 'CAD');
        $usdOrg = $this->product($usd->id, 'USD-USD', 'USD');
        $blankOrg = $this->product($blank->id, 'BLANK-USD', 'USD');

        (require database_path(self::MIGRATION))->up();

        $currency = fn (int $id) => DB::table('products')->where('id', $id)->value('currency');
        $this->assertSame('CAD', $currency($defaulted));
        $this->assertSame('EUR', $currency($eur));
        $this->assertSame('CAD', $currency($already));
        $this->assertSame('USD', $currency($usdOrg));
        // No organization currency: nothing to move to.
        $this->assertSame('USD', $currency($blankOrg));
    }

    public function test_it_is_case_insensitive_and_safe_to_run_twice(): void
    {
        $eur = Organization::create(['name' => 'Euro', 'email' => 'euro@org.com', 'currency' => 'eur', 'timezone' => 'UTC']);
        $id = $this->product($eur->id, 'EU-1', 'usd');

        $migration = require database_path(self::MIGRATION);
        $migration->up();
        $migration->up();

        $this->assertSame('EUR', DB::table('products')->where('id', $id)->value('currency'));
    }
}
