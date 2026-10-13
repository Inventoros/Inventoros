<?php

declare(strict_types=1);

namespace Tests\Feature\Reservations;

use App\Exceptions\InsufficientStockException;
use App\Models\Auth\Organization;
use App\Models\Inventory\InterCompanyTransfer;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductLocation;
use App\Models\Inventory\ProductLocationStock;
use App\Models\Inventory\StockAdjustment;
use App\Models\Scopes\OrganizationScope;
use App\Models\User;
use App\Services\Organizations\InterCompanyTransferService;
use App\Services\StockReservationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Organizations\Concerns\BuildsOrganizations;
use Tests\TestCase;

/**
 * Stock leaving an organization through an inter-company transfer is checked
 * against what is available there: units its reservations promise stay put.
 */
final class InterCompanyTransferHonoursReservationsTest extends TestCase
{
    use BuildsOrganizations, RefreshDatabase;

    private Organization $alpha;

    private Organization $beta;

    private User $user;

    private Product $alphaWidget;

    private Product $betaWidget;

    protected function setUp(): void
    {
        parent::setUp();
        $this->markInstalled();

        $this->alpha = $this->organization('Alpha');
        $this->beta = $this->organization('Beta');
        $this->user = $this->homeUser($this->alpha, 'admin');
        $this->homeUser($this->beta, 'admin', 'Owner');
        $this->addMember($this->beta, $this->user, 'admin');

        $this->alphaWidget = $this->product($this->alpha, 'WIDGET', ['stock' => 10]);
        $this->betaWidget = $this->product($this->beta, 'WIDGET', ['stock' => 0]);
    }

    private function stock(Product $product): int
    {
        return (int) Product::withoutGlobalScope(OrganizationScope::class)->whereKey($product->id)->value('stock');
    }

    private function transfer(int $quantity, array $extra = []): InterCompanyTransfer
    {
        return app(InterCompanyTransferService::class)->transfer($this->user, $this->alpha->id, $this->beta->id, [
            ['from_product_id' => $this->alphaWidget->id, 'to_product_id' => $this->betaWidget->id, 'quantity' => $quantity] + $extra,
        ]);
    }

    public function test_an_inter_company_transfer_cannot_take_reserved_units(): void
    {
        app(StockReservationService::class)->reserve($this->alphaWidget, null, 7, 'rental', 'B-1');

        try {
            $this->transfer(4);
            $this->fail('Promised units must not leave the organization.');
        } catch (InsufficientStockException) {
        }

        $this->assertSame(10, $this->stock($this->alphaWidget));
        $this->assertSame(0, $this->stock($this->betaWidget));
        $this->assertSame(0, InterCompanyTransfer::count());
        $this->assertSame(0, StockAdjustment::withoutGlobalScopes()->count());

        $this->transfer(3);
        $this->assertSame(7, $this->stock($this->alphaWidget));
        $this->assertSame(3, $this->stock($this->betaWidget));
    }

    public function test_a_bin_hold_keeps_units_in_the_source_bin(): void
    {
        $bins = [];
        foreach (['A', 'B'] as $code) {
            $bins[$code] = ProductLocation::withoutGlobalScope(OrganizationScope::class)->create([
                'organization_id' => $this->alpha->id, 'name' => "Aisle {$code}", 'code' => $code, 'is_active' => true,
            ]);
            ProductLocationStock::withoutGlobalScope(OrganizationScope::class)->create([
                'organization_id' => $this->alpha->id, 'product_id' => $this->alphaWidget->id, 'location_id' => $bins[$code]->id, 'quantity' => 5,
            ]);
        }
        $this->alphaWidget->forceFill(['location_id' => $bins['A']->id])->save();
        // Every unit in aisle A is promised; aisle B is free.
        app(StockReservationService::class)->reserve($this->alphaWidget, null, 5, 'rental', 'B-2', $bins['A']->id);

        try {
            $this->transfer(3, ['from_location_id' => $bins['A']->id]);
            $this->fail('Units held in aisle A must not leave from it.');
        } catch (InsufficientStockException) {
        }
        $this->assertSame(10, $this->stock($this->alphaWidget));

        $this->transfer(3, ['from_location_id' => $bins['B']->id]);
        $this->assertSame(7, $this->stock($this->alphaWidget));
    }
}
