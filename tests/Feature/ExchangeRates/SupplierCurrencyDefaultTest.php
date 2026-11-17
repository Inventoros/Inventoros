<?php

declare(strict_types=1);

namespace Tests\Feature\ExchangeRates;

use App\Models\Inventory\Supplier;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A supplier, or a supplier cost, without a currency is in the organization's
 * currency, never a hard-coded USD (#429 follow-up).
 */
final class SupplierCurrencyDefaultTest extends TestCase
{
    use ExchangeRateFixtures, RefreshDatabase;

    protected function tearDown(): void
    {
        $this->tearDownExchangeRates();
        parent::tearDown();
    }

    public function test_a_supplier_created_without_a_currency_uses_the_organization_currency_on_every_surface(): void
    {
        $org = $this->organization('Budapest Bikes', 'HUF');
        $admin = $this->user($org);

        $this->assertSame('HUF', Supplier::create(['organization_id' => $org->id, 'name' => 'Model Supply'])->currency);

        $this->actingAs($admin)->post(route('suppliers.store'), ['name' => 'Web Supply'])->assertSessionHasNoErrors();
        $this->actingAs($admin)->post(route('suppliers.store'), ['name' => 'Blank Supply', 'currency' => ''])->assertSessionHasNoErrors();

        Sanctum::actingAs($admin, ['*']);
        $this->postJson('/api/v1/suppliers', ['name' => 'Api Supply'])->assertCreated()->assertJsonPath('data.currency', 'HUF');
        $this->postJson('/graphql', ['query' => 'mutation { createSupplier(name: "Graph Supply") { id currency } }'])
            ->assertJsonMissingPath('errors')
            ->assertJsonPath('data.createSupplier.currency', 'HUF');

        $this->assertSame(
            ['Api Supply' => 'HUF', 'Blank Supply' => 'HUF', 'Graph Supply' => 'HUF', 'Model Supply' => 'HUF', 'Web Supply' => 'HUF'],
            Supplier::withoutGlobalScopes()->where('organization_id', $org->id)->orderBy('name')->pluck('currency', 'name')->all(),
        );
    }

    public function test_an_explicit_currency_is_kept_and_clearing_it_falls_back_to_the_organization_currency(): void
    {
        $org = $this->organization('Budapest Bikes', 'HUF');

        $supplier = Supplier::create(['organization_id' => $org->id, 'name' => 'Euro Supply', 'currency' => 'eur']);
        $this->assertSame('EUR', $supplier->currency);

        $supplier->update(['currency' => '']);
        $this->assertSame('HUF', $supplier->fresh()->currency);

        $supplier->update(['currency' => 'USD']);
        $this->assertSame('USD', $supplier->fresh()->currency, 'An explicitly chosen USD is kept.');

        $supplier->update(['currency' => null]);
        $this->assertSame('HUF', $supplier->fresh()->currency);
    }

    public function test_a_supplier_cost_without_a_currency_uses_the_organization_currency_when_the_supplier_has_none(): void
    {
        $org = $this->organization('Budapest Bikes', 'HUF');
        $admin = $this->user($org);
        $product = $this->product($org, 'HUF');

        $supplier = $this->supplier($org, 'EUR');
        // A supplier row left with a blank currency (written outside the model).
        DB::table('suppliers')->where('id', $supplier->id)->update(['currency' => '']);

        $this->actingAs($admin)->put(route('products.update', $product), [
            'sku' => $product->sku, 'name' => $product->name, 'price' => $product->price, 'currency' => 'HUF',
            'stock' => 0, 'min_stock' => 0,
            'suppliers' => [['supplier_id' => $supplier->id, 'cost_price' => 1200, 'is_primary' => true]],
        ])->assertSessionHasNoErrors();

        $this->assertSame('HUF', DB::table('product_supplier')->where('supplier_id', $supplier->id)->value('cost_currency'));
    }

    public function test_the_currency_column_no_longer_defaults_to_usd(): void
    {
        $org = $this->organization('Budapest Bikes', 'HUF');

        // A write that skips the model can no longer end up in dollars by default.
        $this->expectException(QueryException::class);

        DB::table('suppliers')->insert([
            'organization_id' => $org->id, 'name' => 'Raw Supply', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
