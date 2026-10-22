<?php

declare(strict_types=1);

namespace Tests\Feature\Reservations;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductBatch;
use App\Models\Inventory\ProductLocation;
use App\Models\Inventory\ProductLocationStock;
use App\Models\Inventory\ProductSerial;
use App\Models\Inventory\ProductVariant;
use App\Models\Inventory\StockAdjustment;
use App\Models\Inventory\StockReservation;
use App\Models\System\SystemSetting;
use App\Models\User;
use App\Services\StockReservationService;
use Illuminate\Support\Facades\Mail;

/**
 * One organization with an admin, two location bins and a product of 10
 * units binned 6 in A and 4 in B. Helpers snapshot everything a refused
 * stock movement must leave untouched: on-hand totals, bins, the ledger,
 * holds, serials and batches.
 */
trait ReservationFixtures
{
    protected Organization $org;

    protected User $admin;

    protected ProductLocation $binA;

    protected ProductLocation $binB;

    protected Product $product;

    protected function setUpReservationWorld(): void
    {
        SystemSetting::set('installed', true, 'boolean');
        Mail::fake();

        $this->org = Organization::create([
            'name' => 'Hire Co', 'email' => 'ops@hire.test', 'currency' => 'USD', 'timezone' => 'UTC',
        ]);

        $this->admin = User::create([
            'name' => 'Ada Admin', 'email' => 'ada@hire.test', 'password' => bcrypt('x'),
            'organization_id' => $this->org->id, 'role' => 'admin',
        ]);

        $this->binA = ProductLocation::create([
            'organization_id' => $this->org->id, 'name' => 'Aisle A', 'code' => 'A', 'is_active' => true,
        ]);
        $this->binB = ProductLocation::create([
            'organization_id' => $this->org->id, 'name' => 'Aisle B', 'code' => 'B', 'is_active' => true,
        ]);

        $this->product = $this->makeProduct('DRILL', 10, [$this->binA->id => 6, $this->binB->id => 4]);
    }

    /**
     * @param  array<int, int>  $bins  location_id => quantity
     * @param  array<string, mixed>  $attributes
     */
    protected function makeProduct(string $sku, int $stock, array $bins = [], array $attributes = [], ?Organization $org = null): Product
    {
        $org ??= $this->org;

        $product = Product::create($attributes + [
            'organization_id' => $org->id, 'sku' => $sku, 'name' => ucfirst(strtolower($sku)),
            'price' => 10, 'purchase_price' => 4, 'currency' => 'USD', 'stock' => $stock, 'min_stock' => 0,
            'is_active' => true, 'location_id' => array_key_first($bins),
        ]);

        foreach ($bins as $locationId => $quantity) {
            ProductLocationStock::create([
                'organization_id' => $org->id, 'product_id' => $product->id,
                'location_id' => $locationId, 'quantity' => $quantity,
            ]);
        }

        return $product;
    }

    protected function makeVariantProduct(string $sku, int $variantStock): ProductVariant
    {
        $product = Product::create([
            'organization_id' => $this->org->id, 'sku' => $sku, 'name' => ucfirst(strtolower($sku)),
            'price' => 20, 'currency' => 'USD', 'stock' => 0, 'min_stock' => 0,
            'is_active' => true, 'has_variants' => true,
        ]);

        return ProductVariant::create([
            'organization_id' => $this->org->id, 'product_id' => $product->id, 'sku' => "{$sku}-L",
            'title' => 'Large', 'option_values' => ['Size' => 'L'], 'price' => 20, 'stock' => $variantStock,
            'is_active' => true,
        ]);
    }

    protected function reservations(): StockReservationService
    {
        return app(StockReservationService::class);
    }

    protected function hold(int $quantity, string $reference = 'BOOKING-1', ?int $locationId = null, ?Product $product = null, string $owner = 'rental'): StockReservation
    {
        return $this->reservations()->reserve($product ?? $this->product, null, $quantity, $owner, $reference, $locationId);
    }

    /**
     * Everything a refused movement of $product must leave as it was.
     *
     * @return array<string, mixed>
     */
    protected function snapshot(?Product $product = null): array
    {
        $product ??= $this->product;

        return [
            'stock' => (int) Product::withoutGlobalScopes()->whereKey($product->id)->value('stock'),
            'variants' => ProductVariant::withoutGlobalScopes()->where('product_id', $product->id)->orderBy('id')->pluck('stock', 'id')->all(),
            'bins' => ProductLocationStock::withoutGlobalScopes()->where('product_id', $product->id)->orderBy('location_id')->pluck('quantity', 'location_id')->all(),
            'ledger' => StockAdjustment::withoutGlobalScopes()->where('product_id', $product->id)->count(),
            'holds' => StockReservation::withoutGlobalScopes()->where('product_id', $product->id)->orderBy('id')
                ->get()->map(fn (StockReservation $r) => [$r->id, $r->quantity, $r->status()])->all(),
            'serials' => ProductSerial::withoutGlobalScopes()->where('product_id', $product->id)->orderBy('id')->pluck('status', 'id')->all(),
            'batches' => ProductBatch::withoutGlobalScopes()->where('product_id', $product->id)->orderBy('id')->pluck('quantity', 'id')->all(),
        ];
    }

    protected function stockOf(?Product $product = null): int
    {
        return (int) Product::withoutGlobalScopes()->whereKey(($product ?? $this->product)->id)->value('stock');
    }

    protected function binQuantity(int $locationId, ?Product $product = null): int
    {
        return (int) ProductLocationStock::withoutGlobalScopes()
            ->where('product_id', ($product ?? $this->product)->id)
            ->where('location_id', $locationId)
            ->value('quantity');
    }
}
