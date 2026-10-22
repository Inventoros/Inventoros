<?php

declare(strict_types=1);

namespace Tests\Feature\Reservations;

use App\Exceptions\InsufficientStockException;
use App\Models\Auth\Organization;
use App\Models\Inventory\InterCompanyTransfer;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductBatch;
use App\Models\Inventory\ProductComponent;
use App\Models\Inventory\ProductLocation;
use App\Models\Inventory\ProductSerial;
use App\Models\Inventory\ProductLocationStock;
use App\Models\Inventory\StockAdjustment;
use App\Models\Scopes\OrganizationScope;
use App\Models\User;
use App\Services\Organizations\InterCompanyTransferService;
use App\Services\StockReservationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
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

    private function assertNothingMoved(): void
    {
        $this->assertSame(0, InterCompanyTransfer::count());
        $this->assertSame(0, StockAdjustment::withoutGlobalScopes()
            ->whereIn('type', [InterCompanyTransferService::OUT, InterCompanyTransferService::IN])->count());
    }

    private function serial(Product $product, string $number): ProductSerial
    {
        return ProductSerial::withoutGlobalScope(OrganizationScope::class)->create([
            'organization_id' => $product->organization_id, 'product_id' => $product->id,
            'serial_number' => $number, 'status' => ProductSerial::STATUS_AVAILABLE,
        ]);
    }

    public function test_a_held_serial_cannot_be_transferred(): void
    {
        $alphaPhone = $this->product($this->alpha, 'PHONE', ['tracking_type' => 'serial', 'stock' => 2]);
        $betaPhone = $this->product($this->beta, 'PHONE', ['tracking_type' => 'serial', 'stock' => 0]);
        $held = $this->serial($alphaPhone, 'SN-1');
        $free = $this->serial($alphaPhone, 'SN-2');
        app(StockReservationService::class)->reserve($alphaPhone, null, 1, 'rental', 'B-3', serial: $held);
        $line = fn (string $number) => [['from_product_id' => $alphaPhone->id, 'to_product_id' => $betaPhone->id, 'serials' => [$number]]];

        try {
            app(InterCompanyTransferService::class)->transfer($this->user, $this->alpha->id, $this->beta->id, $line('SN-1'));
            $this->fail('A held serial must not leave the organization.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('lines.0.serials.0', $e->errors());
        }

        $this->assertNothingMoved();
        $this->assertSame(ProductSerial::STATUS_AVAILABLE, $held->fresh()->status);
        $this->assertSame(2, $this->stock($alphaPhone));

        // The free serial may go: 2 on hand, 1 held, 1 available.
        app(InterCompanyTransferService::class)->transfer($this->user, $this->alpha->id, $this->beta->id, $line('SN-2'));
        $this->assertSame(ProductSerial::STATUS_TRANSFERRED, $free->fresh()->status);
        $this->assertSame(1, $this->stock($alphaPhone));
    }

    public function test_a_product_level_hold_limits_serial_lines_too(): void
    {
        $alphaPhone = $this->product($this->alpha, 'PHONE', ['tracking_type' => 'serial', 'stock' => 2]);
        $betaPhone = $this->product($this->beta, 'PHONE', ['tracking_type' => 'serial', 'stock' => 0]);
        $this->serial($alphaPhone, 'SN-1');
        $this->serial($alphaPhone, 'SN-2');
        app(StockReservationService::class)->reserve($alphaPhone, null, 2, 'shop', 'CHANNEL');

        $this->expectException(InsufficientStockException::class);

        try {
            app(InterCompanyTransferService::class)->transfer($this->user, $this->alpha->id, $this->beta->id, [
                ['from_product_id' => $alphaPhone->id, 'to_product_id' => $betaPhone->id, 'serials' => ['SN-1']],
            ]);
        } finally {
            $this->assertNothingMoved();
        }
    }

    public function test_held_batch_units_cannot_be_transferred(): void
    {
        $alphaMilk = $this->product($this->alpha, 'MILK', ['tracking_type' => 'batch', 'stock' => 10]);
        $betaMilk = $this->product($this->beta, 'MILK', ['tracking_type' => 'batch', 'stock' => 0]);
        $batch = ProductBatch::withoutGlobalScope(OrganizationScope::class)->create([
            'organization_id' => $this->alpha->id, 'product_id' => $alphaMilk->id, 'batch_number' => 'LOT-1', 'quantity' => 10,
        ]);
        app(StockReservationService::class)->reserve($alphaMilk, null, 6, 'kitting', 'RUN-1', batch: $batch);
        $line = fn (int $quantity) => [['from_product_id' => $alphaMilk->id, 'to_product_id' => $betaMilk->id, 'batches' => [['batch_id' => $batch->id, 'quantity' => $quantity]]]];

        try {
            app(InterCompanyTransferService::class)->transfer($this->user, $this->alpha->id, $this->beta->id, $line(5));
            $this->fail('Held batch units must not leave the organization.');
        } catch (InsufficientStockException $e) {
            $this->assertStringContainsString('6 reserved', $e->getMessage());
        }

        $this->assertNothingMoved();
        $this->assertSame(10, (int) $batch->fresh()->quantity);

        app(InterCompanyTransferService::class)->transfer($this->user, $this->alpha->id, $this->beta->id, $line(4));
        $this->assertSame(6, (int) $batch->fresh()->quantity);
        $this->assertSame(6, $this->stock($alphaMilk));
    }

    public function test_kit_component_lines_cannot_take_reserved_components(): void
    {
        $alphaWidget = $this->product($this->alpha, 'BOLT', ['stock' => 10]);
        $betaWidget = $this->product($this->beta, 'BOLT', ['stock' => 0]);
        $alphaKit = $this->product($this->alpha, 'STARTER', ['type' => 'kit', 'stock' => 0]);
        $betaKit = $this->product($this->beta, 'STARTER', ['type' => 'kit', 'stock' => 0]);
        ProductComponent::create(['parent_product_id' => $alphaKit->id, 'component_product_id' => $alphaWidget->id, 'quantity' => '2', 'sort_order' => 0]);
        ProductComponent::create(['parent_product_id' => $betaKit->id, 'component_product_id' => $betaWidget->id, 'quantity' => '2', 'sort_order' => 0]);
        app(StockReservationService::class)->reserve($alphaWidget, null, 7, 'rental', 'B-4');
        $kitLine = fn (int $kits) => [['from_product_id' => $alphaKit->id, 'to_product_id' => $betaKit->id, 'quantity' => $kits]];

        try {
            // 2 kits need 4 widgets; 3 are available.
            app(InterCompanyTransferService::class)->transfer($this->user, $this->alpha->id, $this->beta->id, $kitLine(2));
            $this->fail('Kit components that are promised must not leave.');
        } catch (InsufficientStockException) {
        }

        $this->assertNothingMoved();
        $this->assertSame(10, $this->stock($alphaWidget));

        app(InterCompanyTransferService::class)->transfer($this->user, $this->alpha->id, $this->beta->id, $kitLine(1));
        $this->assertSame(8, $this->stock($alphaWidget));
        $this->assertSame(2, $this->stock($betaWidget));
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
