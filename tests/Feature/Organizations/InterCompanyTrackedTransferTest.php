<?php

declare(strict_types=1);

namespace Tests\Feature\Organizations;

use App\Exceptions\InsufficientStockException;
use App\Jobs\WebhookDeliveryJob;
use App\Models\Auth\Organization;
use App\Models\Inventory\InterCompanyTransfer;
use App\Models\Inventory\InterCompanyTransferLine;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductBatch;
use App\Models\Inventory\ProductComponent;
use App\Models\Inventory\ProductSerial;
use App\Models\Inventory\StockAdjustment;
use App\Models\Scopes\OrganizationScope;
use App\Models\User;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Services\Organizations\InterCompanyTransferService;
use App\Services\TrackedStockReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Organizations\Concerns\BuildsOrganizations;
use Tests\TestCase;

/**
 * Inter-company transfers of serial-tracked units, batch-tracked lots and
 * virtual kits: the tracking records move with the totals, both sides stay
 * consistent, and any refusal leaves both organizations untouched.
 */
final class InterCompanyTrackedTransferTest extends TestCase
{
    use BuildsOrganizations, RefreshDatabase;

    private Organization $alpha;

    private Organization $beta;

    private User $user;

    private Product $alphaPhone;

    private Product $betaPhone;

    private Product $alphaMilk;

    private Product $betaMilk;

    protected function setUp(): void
    {
        parent::setUp();
        $this->markInstalled();

        $this->alpha = $this->organization('Alpha');
        $this->beta = $this->organization('Beta');
        $this->user = $this->homeUser($this->alpha, 'admin');
        $this->homeUser($this->beta, 'admin', 'Owner');
        $this->addMember($this->beta, $this->user, 'admin');

        $this->alphaPhone = $this->product($this->alpha, 'PHONE', ['tracking_type' => 'serial', 'stock' => 3]);
        $this->betaPhone = $this->product($this->beta, 'PHONE', ['tracking_type' => 'serial', 'stock' => 0]);

        foreach (['SN-1', 'SN-2', 'SN-3'] as $number) {
            $this->serial($this->alphaPhone, $number);
        }

        $this->alphaMilk = $this->product($this->alpha, 'MILK', ['tracking_type' => 'batch', 'stock' => 15]);
        $this->betaMilk = $this->product($this->beta, 'MILK', ['tracking_type' => 'batch', 'stock' => 0]);
    }

    private function service(): InterCompanyTransferService
    {
        return app(InterCompanyTransferService::class);
    }

    private function serial(Product $product, string $number, string $status = ProductSerial::STATUS_AVAILABLE): ProductSerial
    {
        return ProductSerial::withoutGlobalScope(OrganizationScope::class)->create([
            'organization_id' => $product->organization_id,
            'product_id' => $product->id,
            'serial_number' => $number,
            'status' => $status,
        ]);
    }

    private function batch(Product $product, string $number, int $quantity, ?string $expiry = null, ?string $manufactured = null): ProductBatch
    {
        return ProductBatch::withoutGlobalScope(OrganizationScope::class)->create([
            'organization_id' => $product->organization_id,
            'product_id' => $product->id,
            'batch_number' => $number,
            'quantity' => $quantity,
            'expiry_date' => $expiry,
            'manufactured_date' => $manufactured,
        ]);
    }

    private function stock(Product $product): int
    {
        return (int) Product::withoutGlobalScope(OrganizationScope::class)->whereKey($product->id)->value('stock');
    }

    /**
     * @return array<string, array{0: int, 1: int, 2: string}>
     */
    private function serials(Organization $organization): array
    {
        return ProductSerial::withoutGlobalScope(OrganizationScope::class)
            ->where('organization_id', $organization->id)
            ->orderBy('serial_number')
            ->get()
            ->mapWithKeys(fn (ProductSerial $s) => [$s->serial_number => [$s->id, (int) $s->product_id, $s->status]])
            ->all();
    }

    /**
     * @param  array<int, string>  $serials
     */
    private function serialLine(array $serials, array $extra = []): array
    {
        return array_merge(['from_product_id' => $this->alphaPhone->id, 'to_product_id' => $this->betaPhone->id, 'serials' => $serials], $extra);
    }

    /**
     * @param  array<int, array{batch_id: int, quantity: int}>  $batches
     */
    private function batchLine(array $batches, array $extra = []): array
    {
        return array_merge(['from_product_id' => $this->alphaMilk->id, 'to_product_id' => $this->betaMilk->id, 'batches' => $batches], $extra);
    }

    private function assertNoDrift(Product ...$products): void
    {
        $reconciliation = app(TrackedStockReconciliationService::class);
        $drifted = $reconciliation->discrepancies($this->alpha->id)->merge($reconciliation->discrepancies($this->beta->id))
            ->map(fn (array $row) => $row['product']->id)
            ->all();

        foreach ($products as $product) {
            $this->assertNotContains($product->id, $drifted, "{$product->sku} drifted from its tracking records.");
        }
    }

    private function assertNoTransfer(): void
    {
        $this->assertSame(0, InterCompanyTransfer::count());
        $this->assertSame(0, StockAdjustment::whereIn('type', [InterCompanyTransferService::OUT, InterCompanyTransferService::IN])->count());
    }

    /**
     * @param  callable(): mixed  $attempt
     * @param  class-string<\Throwable>  $exception
     */
    private function assertRefused(callable $attempt, string $exception, ?string $field = null): void
    {
        try {
            $attempt();
            $this->fail("Expected {$exception} for ".($field ?? 'the attempt').'.');
        } catch (\Throwable $e) {
            $this->assertInstanceOf($exception, $e, $e->getMessage());

            if ($field !== null) {
                $this->assertInstanceOf(ValidationException::class, $e);
                $this->assertArrayHasKey($field, $e->errors(), json_encode($e->errors()));
            }
        }

        $this->assertNoTransfer();
    }

    // ---- Serials ------------------------------------------------------------

    public function test_named_serials_leave_the_source_and_arrive_in_the_destination(): void
    {
        $transfer = $this->service()->transfer($this->user, $this->alpha->id, $this->beta->id, [$this->serialLine(['SN-1', 'SN-2'])]);

        $this->assertSame(1, $this->stock($this->alphaPhone));
        $this->assertSame(2, $this->stock($this->betaPhone));

        $alpha = $this->serials($this->alpha);
        $beta = $this->serials($this->beta);
        $this->assertSame([ProductSerial::STATUS_TRANSFERRED, ProductSerial::STATUS_TRANSFERRED, ProductSerial::STATUS_AVAILABLE], array_column($alpha, 2));
        $this->assertSame(['SN-1', 'SN-2'], array_keys($beta));
        foreach ($beta as [, $productId, $status]) {
            $this->assertSame([$this->betaPhone->id, ProductSerial::STATUS_AVAILABLE], [$productId, $status]);
        }

        $line = $transfer->lines->sole();
        $this->assertSame(2, $line->quantity);
        $this->assertTrue($line->relationLoaded('serials'));
        $this->assertSame(
            [['SN-1', $alpha['SN-1'][0], $beta['SN-1'][0]], ['SN-2', $alpha['SN-2'][0], $beta['SN-2'][0]]],
            $line->serials->sortBy('serial_number')->map(fn ($s) => [$s->serial_number, (int) $s->from_serial_id, (int) $s->to_serial_id])->values()->all(),
        );

        // Both sides' tracking records agree with their recorded stock.
        $this->assertNoDrift($this->alphaPhone, $this->betaPhone);
    }

    public function test_a_quantity_given_with_serials_must_match_them(): void
    {
        $this->assertRefused(fn () => $this->service()->transfer($this->user, $this->alpha->id, $this->beta->id, [
            $this->serialLine(['SN-1', 'SN-2'], ['quantity' => 3]),
        ]), ValidationException::class, 'lines.0.quantity');

        $this->service()->transfer($this->user, $this->alpha->id, $this->beta->id, [$this->serialLine(['SN-1'], ['quantity' => 1])]);
        $this->assertSame(2, $this->stock($this->alphaPhone));
    }

    public function test_serials_that_are_unknown_of_another_organization_or_not_available_are_refused(): void
    {
        $gamma = $this->organization('Gamma');
        $gammaPhone = $this->product($gamma, 'PHONE', ['tracking_type' => 'serial']);
        $this->serial($gammaPhone, 'SN-GAMMA');
        $this->serial($this->betaPhone, 'SN-BETA');
        $otherAlphaPhone = $this->product($this->alpha, 'PHONE-2', ['tracking_type' => 'serial']);
        $this->serial($otherAlphaPhone, 'SN-OTHER');
        $this->serial($this->alphaPhone, 'SN-SOLD', ProductSerial::STATUS_SOLD);
        $this->serial($this->alphaPhone, 'SN-DAMAGED', ProductSerial::STATUS_DAMAGED);
        $before = [$this->serials($this->alpha), $this->serials($this->beta), $this->serials($gamma)];

        foreach (['SN-NOPE', 'SN-GAMMA', 'SN-BETA', 'SN-OTHER', 'SN-SOLD', 'SN-DAMAGED'] as $number) {
            $this->assertRefused(fn () => $this->service()->transfer($this->user, $this->alpha->id, $this->beta->id, [
                $this->serialLine(['SN-1', $number]),
            ]), ValidationException::class, 'lines.0.serials.1');
        }

        // The same unit named twice, in one line or in two.
        $this->assertRefused(fn () => $this->service()->transfer($this->user, $this->alpha->id, $this->beta->id, [
            $this->serialLine(['SN-1', 'SN-1']),
        ]), ValidationException::class);
        $this->assertRefused(fn () => $this->service()->transfer($this->user, $this->alpha->id, $this->beta->id, [
            $this->serialLine(['SN-1']),
            $this->serialLine(['SN-1']),
        ]), ValidationException::class, 'lines.1.serials.0');

        $this->assertSame($before, [$this->serials($this->alpha), $this->serials($this->beta), $this->serials($gamma)]);
        $this->assertSame(3, $this->stock($this->alphaPhone));
    }

    public function test_a_serial_already_in_the_destination_fails_the_whole_transfer(): void
    {
        $alphaWidget = $this->product($this->alpha, 'WIDGET', ['stock' => 50]);
        $betaWidget = $this->product($this->beta, 'WIDGET', ['stock' => 5]);
        // Serial numbers are unique per organization, whichever product holds them.
        $betaOther = $this->product($this->beta, 'OTHER', ['tracking_type' => 'serial']);
        $this->serial($betaOther, 'SN-2');
        $before = [$this->serials($this->alpha), $this->serials($this->beta)];

        $this->assertRefused(fn () => $this->service()->transfer($this->user, $this->alpha->id, $this->beta->id, [
            ['from_product_id' => $alphaWidget->id, 'to_product_id' => $betaWidget->id, 'quantity' => 4],
            $this->serialLine(['SN-1', 'SN-2']),
        ]), ValidationException::class, 'lines.1.serials.1');

        $this->assertSame($before, [$this->serials($this->alpha), $this->serials($this->beta)]);
        $this->assertSame([50, 5, 3, 0], [$this->stock($alphaWidget), $this->stock($betaWidget), $this->stock($this->alphaPhone), $this->stock($this->betaPhone)]);
    }

    public function test_a_serial_coming_back_reuses_the_record_it_left_behind(): void
    {
        $this->service()->transfer($this->user, $this->alpha->id, $this->beta->id, [$this->serialLine(['SN-1'])]);
        $original = $this->serials($this->alpha)['SN-1'][0];

        $this->service()->transfer($this->user, $this->beta->id, $this->alpha->id, [
            ['from_product_id' => $this->betaPhone->id, 'to_product_id' => $this->alphaPhone->id, 'serials' => ['SN-1']],
        ]);

        $this->assertSame([$original, $this->alphaPhone->id, ProductSerial::STATUS_AVAILABLE], $this->serials($this->alpha)['SN-1']);
        $this->assertSame(ProductSerial::STATUS_TRANSFERRED, $this->serials($this->beta)['SN-1'][2]);
        $this->assertSame([3, 0], [$this->stock($this->alphaPhone), $this->stock($this->betaPhone)]);
    }

    public function test_a_transferred_serial_cannot_be_moved_again_from_the_source(): void
    {
        $this->service()->transfer($this->user, $this->alpha->id, $this->beta->id, [$this->serialLine(['SN-1'])]);

        try {
            $this->service()->transfer($this->user, $this->alpha->id, $this->beta->id, [$this->serialLine(['SN-1'])]);
            $this->fail('Expected the transferred serial to be refused.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('lines.0.serials.0', $e->errors());
        }

        $this->assertSame(1, InterCompanyTransfer::count());
    }

    public function test_tracking_must_agree_on_both_sides_and_with_the_units_named(): void
    {
        $alphaWidget = $this->product($this->alpha, 'WIDGET', ['stock' => 50]);
        $betaWidget = $this->product($this->beta, 'WIDGET', ['stock' => 5]);
        $milkBatch = $this->batch($this->alphaMilk, 'LOT-A', 10);

        foreach ([
            // A serial-tracked product needs its serials named, and an untracked destination cannot hold them.
            [['from_product_id' => $this->alphaPhone->id, 'to_product_id' => $this->betaPhone->id, 'quantity' => 1], 'lines.0.serials'],
            [$this->serialLine(['SN-1'], ['to_product_id' => $betaWidget->id]), 'lines.0.to_product_id'],
            [$this->serialLine(['SN-1'], ['to_product_id' => $this->betaMilk->id]), 'lines.0.to_product_id'],
            // A batch-tracked product needs its batches named.
            [['from_product_id' => $this->alphaMilk->id, 'to_product_id' => $this->betaMilk->id, 'quantity' => 1], 'lines.0.batches'],
            [$this->batchLine([['batch_id' => $milkBatch->id, 'quantity' => 1]], ['to_product_id' => $betaWidget->id]), 'lines.0.to_product_id'],
            // Units named for an untracked product, or the wrong kind of unit.
            [['from_product_id' => $alphaWidget->id, 'to_product_id' => $betaWidget->id, 'serials' => ['SN-1']], 'lines.0.serials'],
            [['from_product_id' => $alphaWidget->id, 'to_product_id' => $betaWidget->id, 'batches' => [['batch_id' => $milkBatch->id, 'quantity' => 1]]], 'lines.0.batches'],
            [$this->serialLine(['SN-1'], ['batches' => [['batch_id' => $milkBatch->id, 'quantity' => 1]]]), 'lines.0.batches'],
        ] as [$line, $field]) {
            $this->assertRefused(fn () => $this->service()->transfer($this->user, $this->alpha->id, $this->beta->id, [$line]), ValidationException::class, $field);
        }

        $this->assertSame(10, $milkBatch->fresh()->quantity);
    }

    // ---- Batches ------------------------------------------------------------

    public function test_batch_quantities_move_with_their_lot_number_and_dates(): void
    {
        $lotA = $this->batch($this->alphaMilk, 'LOT-A', 10, '2027-01-31', '2026-09-01');
        $lotB = $this->batch($this->alphaMilk, 'LOT-B', 5, '2027-06-30');

        $transfer = $this->service()->transfer($this->user, $this->alpha->id, $this->beta->id, [$this->batchLine([
            ['batch_id' => $lotA->id, 'quantity' => 4],
            ['batch_id' => $lotB->id, 'quantity' => 5],
        ])]);

        $this->assertSame([6, 0], [$lotA->fresh()->quantity, $lotB->fresh()->quantity]);
        $this->assertSame([6, 9], [$this->stock($this->alphaMilk), $this->stock($this->betaMilk)]);

        $betaLots = ProductBatch::withoutGlobalScope(OrganizationScope::class)->where('organization_id', $this->beta->id)->orderBy('batch_number')->get();
        $this->assertSame(
            [['LOT-A', $this->betaMilk->id, 4, '2027-01-31', '2026-09-01'], ['LOT-B', $this->betaMilk->id, 5, '2027-06-30', null]],
            $betaLots->map(fn (ProductBatch $b) => [$b->batch_number, (int) $b->product_id, $b->quantity, $b->expiry_date?->toDateString(), $b->manufactured_date?->toDateString()])->all(),
        );

        $line = $transfer->lines->sole();
        $this->assertSame(9, $line->quantity);
        $this->assertSame(
            [['LOT-A', $lotA->id, $betaLots[0]->id, 4, '2027-01-31'], ['LOT-B', $lotB->id, $betaLots[1]->id, 5, '2027-06-30']],
            $line->batches->sortBy('batch_number')->map(fn ($b) => [$b->batch_number, (int) $b->from_batch_id, (int) $b->to_batch_id, (int) $b->quantity, $b->expiry_date?->toDateString()])->values()->all(),
        );

        // A second transfer of the same lot tops up the destination's batch.
        $this->service()->transfer($this->user, $this->alpha->id, $this->beta->id, [$this->batchLine([['batch_id' => $lotA->id, 'quantity' => 2]])]);
        $this->assertSame(6, $betaLots[0]->fresh()->quantity);
        $this->assertSame(2, ProductBatch::withoutGlobalScope(OrganizationScope::class)->where('organization_id', $this->beta->id)->count());

        $this->assertNoDrift($this->alphaMilk, $this->betaMilk);
    }

    public function test_a_batch_cannot_give_more_than_it_holds(): void
    {
        $lotA = $this->batch($this->alphaMilk, 'LOT-A', 10, '2027-01-31');

        $this->assertRefused(fn () => $this->service()->transfer($this->user, $this->alpha->id, $this->beta->id, [
            $this->batchLine([['batch_id' => $lotA->id, 'quantity' => 11]]),
        ]), InsufficientStockException::class);

        // Two lines drawing on one batch count together.
        $this->assertRefused(fn () => $this->service()->transfer($this->user, $this->alpha->id, $this->beta->id, [
            $this->batchLine([['batch_id' => $lotA->id, 'quantity' => 6]]),
            $this->batchLine([['batch_id' => $lotA->id, 'quantity' => 5]]),
        ]), InsufficientStockException::class);

        $this->assertSame(10, $lotA->fresh()->quantity);
        $this->assertSame(0, ProductBatch::withoutGlobalScope(OrganizationScope::class)->where('organization_id', $this->beta->id)->count());
    }

    public function test_batches_of_another_product_or_organization_are_refused(): void
    {
        $betaLot = $this->batch($this->betaMilk, 'LOT-BETA', 10);
        $otherAlphaMilk = $this->product($this->alpha, 'MILK-2', ['tracking_type' => 'batch']);
        $otherLot = $this->batch($otherAlphaMilk, 'LOT-OTHER', 10);

        foreach ([$betaLot->id, $otherLot->id, 999999] as $batchId) {
            $this->assertRefused(fn () => $this->service()->transfer($this->user, $this->alpha->id, $this->beta->id, [
                $this->batchLine([['batch_id' => $batchId, 'quantity' => 1]]),
            ]), ValidationException::class, 'lines.0.batches.0.batch_id');
        }

        $this->assertSame([10, 10], [$betaLot->fresh()->quantity, $otherLot->fresh()->quantity]);
    }

    public function test_a_destination_lot_with_another_expiry_fails_the_whole_transfer(): void
    {
        $lotA = $this->batch($this->alphaMilk, 'LOT-A', 10, '2027-01-31');
        $clash = $this->batch($this->betaMilk, 'LOT-A', 3, '2027-02-28');
        $alphaWidget = $this->product($this->alpha, 'WIDGET', ['stock' => 50]);
        $betaWidget = $this->product($this->beta, 'WIDGET', ['stock' => 5]);

        $this->assertRefused(fn () => $this->service()->transfer($this->user, $this->alpha->id, $this->beta->id, [
            ['from_product_id' => $alphaWidget->id, 'to_product_id' => $betaWidget->id, 'quantity' => 4],
            $this->batchLine([['batch_id' => $lotA->id, 'quantity' => 2]]),
        ]), ValidationException::class, 'lines.1.batches.0.batch_id');

        $this->assertSame([10, 3, 50], [$lotA->fresh()->quantity, $clash->fresh()->quantity, $this->stock($alphaWidget)]);
    }

    // ---- Kits ---------------------------------------------------------------

    /**
     * @param  array<int, array{0: Product, 1: string}>  $components
     */
    private function kit(Organization $organization, string $sku, array $components): Product
    {
        $kit = $this->product($organization, $sku, ['type' => 'kit', 'stock' => 0]);

        foreach ($components as $position => [$component, $quantity]) {
            ProductComponent::create(['parent_product_id' => $kit->id, 'component_product_id' => $component->id, 'quantity' => $quantity, 'sort_order' => $position]);
        }

        return $kit;
    }

    public function test_a_kit_moves_as_its_components(): void
    {
        $alphaWidget = $this->product($this->alpha, 'WIDGET', ['stock' => 50]);
        $alphaGadget = $this->product($this->alpha, 'GADGET', ['stock' => 10]);
        $betaWidget = $this->product($this->beta, 'WIDGET', ['stock' => 5]);
        $betaGadget = $this->product($this->beta, 'GADGET', ['stock' => 0]);
        $alphaKit = $this->kit($this->alpha, 'STARTER', [[$alphaWidget, '2'], [$alphaGadget, '1']]);
        $betaKit = $this->kit($this->beta, 'STARTER-B', [[$betaGadget, '1'], [$betaWidget, '2']]);

        $transfer = $this->service()->transfer($this->user, $this->alpha->id, $this->beta->id, [
            ['from_product_id' => $alphaKit->id, 'to_product_id' => $betaKit->id, 'quantity' => 3],
        ]);

        $this->assertSame([44, 7, 11, 3], [$this->stock($alphaWidget), $this->stock($alphaGadget), $this->stock($betaWidget), $this->stock($betaGadget)]);
        $this->assertSame([0, 0], [$this->stock($alphaKit), $this->stock($betaKit)]);

        $lines = $transfer->lines->sortBy('from_product_id')->values();
        $this->assertSame(
            [[$alphaWidget->id, $betaWidget->id, 6, $alphaKit->id, $betaKit->id, 3], [$alphaGadget->id, $betaGadget->id, 3, $alphaKit->id, $betaKit->id, 3]],
            $lines->map(fn (InterCompanyTransferLine $l) => [$l->from_product_id, $l->to_product_id, $l->quantity, $l->from_kit_product_id, $l->to_kit_product_id, $l->kit_quantity])->all(),
        );
        $this->assertSame(4, StockAdjustment::whereIn('type', [InterCompanyTransferService::OUT, InterCompanyTransferService::IN])->count());
    }

    public function test_kits_that_cannot_be_exploded_safely_are_refused(): void
    {
        $alphaWidget = $this->product($this->alpha, 'WIDGET', ['stock' => 50]);
        $alphaGadget = $this->product($this->alpha, 'GADGET', ['stock' => 10]);
        $betaWidget = $this->product($this->beta, 'WIDGET', ['stock' => 5]);
        $betaGadget = $this->product($this->beta, 'GADGET', ['stock' => 0]);
        $alphaKit = $this->kit($this->alpha, 'STARTER', [[$alphaWidget, '2'], [$alphaGadget, '1']]);

        $otherBom = $this->kit($this->beta, 'B1', [[$betaWidget, '1'], [$betaGadget, '1']]);
        $missingComponent = $this->kit($this->beta, 'B2', [[$betaWidget, '2']]);
        $extraComponent = $this->kit($this->beta, 'B3', [[$betaWidget, '2'], [$betaGadget, '1'], [$this->product($this->beta, 'EXTRA'), '1']]);
        $emptyKit = $this->kit($this->beta, 'B4', []);

        foreach ([$otherBom, $missingComponent, $extraComponent, $emptyKit, $betaWidget] as $destination) {
            $this->assertRefused(fn () => $this->service()->transfer($this->user, $this->alpha->id, $this->beta->id, [
                ['from_product_id' => $alphaKit->id, 'to_product_id' => $destination->id, 'quantity' => 1],
            ]), ValidationException::class, 'lines.0.to_product_id');
        }

        // A plain product cannot be put into a kit.
        $betaKit = $this->kit($this->beta, 'STARTER', [[$betaWidget, '2'], [$betaGadget, '1']]);
        $this->assertRefused(fn () => $this->service()->transfer($this->user, $this->alpha->id, $this->beta->id, [
            ['from_product_id' => $alphaWidget->id, 'to_product_id' => $betaKit->id, 'quantity' => 1],
        ]), ValidationException::class, 'lines.0.to_product_id');

        // Half a component per kit only moves in whole units.
        $alphaHalf = $this->kit($this->alpha, 'HALF', [[$alphaWidget, '0.5']]);
        $betaHalf = $this->kit($this->beta, 'HALF', [[$betaWidget, '0.5']]);
        $this->assertRefused(fn () => $this->service()->transfer($this->user, $this->alpha->id, $this->beta->id, [
            ['from_product_id' => $alphaHalf->id, 'to_product_id' => $betaHalf->id, 'quantity' => 3],
        ]), ValidationException::class, 'lines.0.quantity');
        $this->service()->transfer($this->user, $this->alpha->id, $this->beta->id, [
            ['from_product_id' => $alphaHalf->id, 'to_product_id' => $betaHalf->id, 'quantity' => 4],
        ]);
        $this->assertSame([48, 7], [$this->stock($alphaWidget), $this->stock($betaWidget)]);
    }

    public function test_a_kit_with_a_tracked_component_is_refused(): void
    {
        $alphaKit = $this->kit($this->alpha, 'BUNDLE', [[$this->alphaPhone, '1']]);
        $betaKit = $this->kit($this->beta, 'BUNDLE', [[$this->betaPhone, '1']]);

        $this->assertRefused(fn () => $this->service()->transfer($this->user, $this->alpha->id, $this->beta->id, [
            ['from_product_id' => $alphaKit->id, 'to_product_id' => $betaKit->id, 'quantity' => 1],
        ]), ValidationException::class, 'lines.0.from_product_id');

        $this->assertSame(3, $this->stock($this->alphaPhone));
    }

    // ---- Guarantees ---------------------------------------------------------

    public function test_a_retry_with_the_same_key_moves_tracked_units_once(): void
    {
        $lotA = $this->batch($this->alphaMilk, 'LOT-A', 10, '2027-01-31');
        $lines = [$this->serialLine(['SN-1']), $this->batchLine([['batch_id' => $lotA->id, 'quantity' => 4]])];

        $first = $this->service()->transfer($this->user, $this->alpha->id, $this->beta->id, $lines, null, 'tracked-7');
        $again = $this->service()->transfer($this->user, $this->alpha->id, $this->beta->id, $lines, null, 'tracked-7');

        $this->assertSame($first->id, $again->id);
        $this->assertTrue($again->lines->every(fn ($line) => $line->relationLoaded('serials') && $line->relationLoaded('batches')));
        $this->assertSame([2, 1], [$this->stock($this->alphaPhone), $this->stock($this->betaPhone)]);
        $this->assertSame(6, $lotA->fresh()->quantity);
        $this->assertSame(4, (int) ProductBatch::withoutGlobalScope(OrganizationScope::class)->where('organization_id', $this->beta->id)->sum('quantity'));
        $this->assertCount(1, $this->serials($this->beta));
    }

    public function test_hooks_and_webhooks_of_both_organizations_carry_the_units(): void
    {
        Queue::fake([WebhookDeliveryJob::class]);
        $alphaHook = Webhook::create(['organization_id' => $this->alpha->id, 'name' => 'Alpha', 'url' => 'https://alpha.example.test/hook', 'events' => ['stock.adjusted'], 'is_active' => true]);
        $betaHook = Webhook::create(['organization_id' => $this->beta->id, 'name' => 'Beta', 'url' => 'https://beta.example.test/hook', 'events' => ['stock.adjusted'], 'is_active' => true]);
        $lotA = $this->batch($this->alphaMilk, 'LOT-A', 10, '2027-01-31');
        $seen = [];
        add_action('inter_company_transfer_completed', function ($transfer) use (&$seen) {
            $seen = $transfer->lines->map(fn ($line) => [$line->serials->pluck('serial_number')->all(), $line->batches->pluck('batch_number')->all()])->all();
        });
        $this->actingAs($this->user);

        $this->service()->transfer($this->user, $this->alpha->id, $this->beta->id, [
            $this->serialLine(['SN-1', 'SN-3']),
            $this->batchLine([['batch_id' => $lotA->id, 'quantity' => 4]]),
        ]);

        $this->assertSame([[['SN-1', 'SN-3'], []], [[], ['LOT-A']]], $seen);
        $this->assertSame(2, WebhookDelivery::where('webhook_id', $alphaHook->id)->count());
        $this->assertSame(2, WebhookDelivery::where('webhook_id', $betaHook->id)->count());
        foreach (WebhookDelivery::where('webhook_id', $betaHook->id)->get() as $delivery) {
            $this->assertSame($this->beta->id, $delivery->payload['organization_id']);
        }
    }
}
