<?php

declare(strict_types=1);

namespace Tests\Feature\Organizations;

use App\Exceptions\InsufficientStockException;
use App\Jobs\WebhookDeliveryJob;
use App\Models\Auth\Organization;
use App\Models\Inventory\InterCompanyTransfer;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductLocation;
use App\Models\Inventory\ProductLocationStock;
use App\Models\Inventory\ProductVariant;
use App\Models\Inventory\StockAdjustment;
use App\Models\Scopes\OrganizationScope;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Services\Organizations\InterCompanyTransferService;
use App\Services\WarehouseAccessService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Organizations\Concerns\BuildsOrganizations;
use Tests\TestCase;

/**
 * Inter-company transfers: stock leaves one organization and arrives in
 * another as one atomic operation, recorded in both ledgers, checked in both
 * organizations, and never touching a record of the wrong organization.
 */
final class InterCompanyTransferServiceTest extends TestCase
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

        $this->alphaWidget = $this->product($this->alpha, 'WIDGET', ['stock' => 50]);
        $this->betaWidget = $this->product($this->beta, 'WIDGET', ['stock' => 5]);
    }

    private function service(): InterCompanyTransferService
    {
        return app(InterCompanyTransferService::class);
    }

    private function line(Product $from, Product $to, int $quantity, array $extra = []): array
    {
        return ['from_product_id' => $from->id, 'to_product_id' => $to->id, 'quantity' => $quantity] + $extra;
    }

    private function stock(Product $product): int
    {
        return (int) Product::withoutGlobalScope(OrganizationScope::class)->whereKey($product->id)->value('stock');
    }

    private function assertNothingMoved(): void
    {
        $this->assertSame(50, $this->stock($this->alphaWidget));
        $this->assertSame(5, $this->stock($this->betaWidget));
        $this->assertSame(0, InterCompanyTransfer::count());
        $this->assertSame(0, StockAdjustment::whereIn('type', [InterCompanyTransferService::OUT, InterCompanyTransferService::IN])->count());
    }

    /**
     * @param  callable(): mixed  $attempt
     * @param  class-string<\Throwable>  $exception
     */
    private function assertRefused(callable $attempt, string $exception): void
    {
        try {
            $attempt();
            $this->fail("Expected {$exception}.");
        } catch (\Throwable $e) {
            $this->assertInstanceOf($exception, $e, $e->getMessage());
        }

        $this->assertNothingMoved();
    }

    public function test_stock_leaves_one_organization_and_arrives_in_the_other_in_both_ledgers(): void
    {
        $transfer = $this->service()->transfer($this->user, $this->alpha->id, $this->beta->id, [
            $this->line($this->alphaWidget, $this->betaWidget, 10),
        ], 'Monthly rebalance');

        $this->assertSame(40, $this->stock($this->alphaWidget));
        $this->assertSame(15, $this->stock($this->betaWidget));

        $out = StockAdjustment::where('type', InterCompanyTransferService::OUT)->sole();
        $in = StockAdjustment::where('type', InterCompanyTransferService::IN)->sole();

        $this->assertSame([$this->alpha->id, $this->alphaWidget->id, -10, 50, 40], [$out->organization_id, $out->product_id, $out->adjustment_quantity, $out->quantity_before, $out->quantity_after]);
        $this->assertSame([$this->beta->id, $this->betaWidget->id, 10, 5, 15], [$in->organization_id, $in->product_id, $in->adjustment_quantity, $in->quantity_before, $in->quantity_after]);

        foreach ([$out, $in] as $adjustment) {
            $this->assertSame(InterCompanyTransfer::class, $adjustment->reference_type);
            $this->assertSame($transfer->id, $adjustment->reference_id);
            $this->assertSame($this->user->id, $adjustment->user_id);
            $this->assertStringContainsString($transfer->reference, $adjustment->reason);
            $this->assertSame('Monthly rebalance', $adjustment->notes);
        }

        $this->assertSame([$this->alpha->id, $this->beta->id, $this->user->id], [$transfer->from_organization_id, $transfer->to_organization_id, $transfer->initiated_by]);
        $this->assertNotNull($transfer->completed_at);
        $line = $transfer->lines->sole();
        $this->assertSame([10, $out->id, $in->id], [$line->quantity, $line->out_adjustment_id, $line->in_adjustment_id]);

        $this->assertSame(1, InterCompanyTransfer::involving($this->alpha->id)->count());
        $this->assertSame(1, InterCompanyTransfer::involving($this->beta->id)->count());
        $this->assertSame(0, InterCompanyTransfer::involving($this->organization('Gamma')->id)->count());
    }

    public function test_the_bins_move_with_the_totals(): void
    {
        $alphaBin = ProductLocation::withoutGlobalScope(OrganizationScope::class)->create(['organization_id' => $this->alpha->id, 'name' => 'A1', 'code' => 'A1', 'is_active' => true]);
        $betaBin = ProductLocation::withoutGlobalScope(OrganizationScope::class)->create(['organization_id' => $this->beta->id, 'name' => 'B1', 'code' => 'B1', 'is_active' => true]);
        ProductLocationStock::create(['organization_id' => $this->alpha->id, 'product_id' => $this->alphaWidget->id, 'location_id' => $alphaBin->id, 'quantity' => 50]);

        $this->service()->transfer($this->user, $this->alpha->id, $this->beta->id, [
            $this->line($this->alphaWidget, $this->betaWidget, 8, ['from_location_id' => $alphaBin->id, 'to_location_id' => $betaBin->id]),
        ]);

        $this->assertSame(42, (int) ProductLocationStock::where('location_id', $alphaBin->id)->value('quantity'));
        $this->assertSame(8, (int) ProductLocationStock::where('location_id', $betaBin->id)->where('product_id', $this->betaWidget->id)->value('quantity'));
    }

    public function test_variants_move_between_variants(): void
    {
        $alphaShirt = $this->product($this->alpha, 'SHIRT', ['has_variants' => true, 'stock' => 0]);
        $betaShirt = $this->product($this->beta, 'SHIRT', ['has_variants' => true, 'stock' => 0]);
        $alphaLarge = ProductVariant::create(['product_id' => $alphaShirt->id, 'organization_id' => $this->alpha->id, 'sku' => 'SHIRT-L', 'option_values' => ['Size' => 'L'], 'stock' => 12, 'min_stock' => 0, 'is_active' => true, 'position' => 0]);
        $betaLarge = ProductVariant::create(['product_id' => $betaShirt->id, 'organization_id' => $this->beta->id, 'sku' => 'SHIRT-L', 'option_values' => ['Size' => 'L'], 'stock' => 1, 'min_stock' => 0, 'is_active' => true, 'position' => 0]);

        $this->service()->transfer($this->user, $this->alpha->id, $this->beta->id, [
            $this->line($alphaShirt, $betaShirt, 4, ['from_variant_id' => $alphaLarge->id, 'to_variant_id' => $betaLarge->id]),
        ]);

        $this->assertSame(8, $alphaLarge->fresh()->stock);
        $this->assertSame(5, $betaLarge->fresh()->stock);

        // A product sold by variant needs its variant named.
        $this->expectException(ValidationException::class);
        $this->service()->transfer($this->user, $this->alpha->id, $this->beta->id, [$this->line($alphaShirt, $betaShirt, 1)]);
    }

    public function test_every_line_moves_or_none_does(): void
    {
        $alphaGadget = $this->product($this->alpha, 'GADGET', ['stock' => 2]);
        $betaGadget = $this->product($this->beta, 'GADGET', ['stock' => 0]);

        $this->assertRefused(fn () => $this->service()->transfer($this->user, $this->alpha->id, $this->beta->id, [
            $this->line($this->alphaWidget, $this->betaWidget, 10),
            $this->line($alphaGadget, $betaGadget, 3),
        ]), InsufficientStockException::class);

        $this->assertSame(2, $this->stock($alphaGadget));
    }

    public function test_the_actor_must_be_a_member_of_both_organizations(): void
    {
        $gamma = $this->organization('Gamma');
        $gammaWidget = $this->product($gamma, 'WIDGET');
        $outsider = $this->homeUser($this->alpha, 'admin', 'Outsider');

        $this->assertRefused(fn () => $this->service()->transfer($outsider, $this->alpha->id, $this->beta->id, [
            $this->line($this->alphaWidget, $this->betaWidget, 1),
        ]), AuthorizationException::class);

        $this->assertRefused(fn () => $this->service()->transfer($this->user, $this->alpha->id, $gamma->id, [
            $this->line($this->alphaWidget, $gammaWidget, 1),
        ]), AuthorizationException::class);

        // Out of an organization the actor does not belong to, into one they do.
        $this->assertRefused(fn () => $this->service()->transfer($this->user, $gamma->id, $this->alpha->id, [
            $this->line($gammaWidget, $this->alphaWidget, 1),
        ]), AuthorizationException::class);
        $this->assertSame(50, $this->stock($gammaWidget));
    }

    public function test_the_actor_needs_transfer_stock_in_both_organizations(): void
    {
        $gamma = $this->organization('Gamma');
        $this->homeUser($gamma, 'admin', 'Owner');
        $gammaWidget = $this->product($gamma, 'WIDGET');
        $this->addMember($gamma, $this->user, 'member');
        $this->grantInOrganization($this->user, $gamma, ['view_products']);

        $this->assertRefused(fn () => $this->service()->transfer($this->user, $this->alpha->id, $gamma->id, [
            $this->line($this->alphaWidget, $gammaWidget, 1),
        ]), AuthorizationException::class);

        $this->grantInOrganization($this->user, $gamma, ['transfer_stock']);
        $this->service()->transfer($this->user, $this->alpha->id, $gamma->id, [$this->line($this->alphaWidget, $gammaWidget, 1)]);
        $this->assertSame(51, $this->stock($gammaWidget));
    }

    public function test_records_of_the_wrong_organization_are_refused_as_not_found(): void
    {
        $alphaBin = ProductLocation::withoutGlobalScope(OrganizationScope::class)->create(['organization_id' => $this->alpha->id, 'name' => 'A1', 'code' => 'A1', 'is_active' => true]);
        $gamma = $this->organization('Gamma');
        $gammaWidget = $this->product($gamma, 'WIDGET');

        foreach ([
            [$this->line($this->betaWidget, $this->betaWidget, 1), 'lines.0.from_product_id'],
            [$this->line($this->alphaWidget, $this->alphaWidget, 1), 'lines.0.to_product_id'],
            [$this->line($this->alphaWidget, $gammaWidget, 1), 'lines.0.to_product_id'],
            [$this->line($this->alphaWidget, $this->betaWidget, 1, ['to_location_id' => $alphaBin->id]), 'lines.0.to_location_id'],
            [$this->line($this->alphaWidget, $this->betaWidget, 1, ['from_location_id' => 999999]), 'lines.0.from_location_id'],
        ] as [$line, $field]) {
            try {
                $this->service()->transfer($this->user, $this->alpha->id, $this->beta->id, [$line]);
                $this->fail("Expected {$field} to be refused.");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey($field, $e->errors());
            }
        }

        $this->assertNothingMoved();
        $this->assertSame(50, $this->stock($gammaWidget));
    }

    public function test_malformed_requests_are_refused(): void
    {
        foreach ([
            fn () => $this->service()->transfer($this->user, $this->alpha->id, $this->alpha->id, [$this->line($this->alphaWidget, $this->alphaWidget, 1)]),
            fn () => $this->service()->transfer($this->user, $this->alpha->id, $this->beta->id, []),
            fn () => $this->service()->transfer($this->user, $this->alpha->id, $this->beta->id, [$this->line($this->alphaWidget, $this->betaWidget, 0)]),
            fn () => $this->service()->transfer($this->user, $this->alpha->id, $this->beta->id, [$this->line($this->alphaWidget, $this->betaWidget, -3)]),
            fn () => $this->service()->transfer($this->user, $this->alpha->id, $this->beta->id, [['from_product_id' => $this->alphaWidget->id, 'to_product_id' => $this->betaWidget->id, 'quantity' => '2.5']]),
            fn () => $this->service()->transfer($this->user, $this->alpha->id, $this->beta->id, [['from_product_id' => $this->alphaWidget->id, 'quantity' => 1]]),
        ] as $attempt) {
            $this->assertRefused($attempt, ValidationException::class);
        }
    }

    public function test_tracked_products_and_kits_are_refused(): void
    {
        $tracked = $this->product($this->alpha, 'SERIAL-1', ['tracking_type' => 'serial']);
        $kit = $this->product($this->alpha, 'KIT-1', ['type' => 'kit']);

        $this->assertRefused(fn () => $this->service()->transfer($this->user, $this->alpha->id, $this->beta->id, [$this->line($tracked, $this->betaWidget, 1)]), ValidationException::class);
        $this->assertRefused(fn () => $this->service()->transfer($this->user, $this->alpha->id, $this->beta->id, [$this->line($kit, $this->betaWidget, 1)]), ValidationException::class);
    }

    public function test_warehouse_access_is_checked_on_both_sides(): void
    {
        $betaWarehouse = Warehouse::factory()->create(['organization_id' => $this->beta->id]);
        $betaBin = ProductLocation::withoutGlobalScope(OrganizationScope::class)->create(['organization_id' => $this->beta->id, 'warehouse_id' => $betaWarehouse->id, 'name' => 'B1', 'code' => 'B1', 'is_active' => true]);
        $clerk = $this->homeUser($this->alpha, 'member', 'Clerk');
        $this->grantInOrganization($clerk, $this->alpha, ['transfer_stock']);
        $this->addMember($this->beta, $clerk, 'member');
        $this->grantInOrganization($clerk, $this->beta, ['transfer_stock']);
        app(WarehouseAccessService::class)->setOrganizationRestrictsToAssigned($this->beta->id, true);

        $this->assertRefused(fn () => $this->service()->transfer($clerk, $this->alpha->id, $this->beta->id, [
            $this->line($this->alphaWidget, $this->betaWidget, 1, ['to_location_id' => $betaBin->id]),
        ]), AuthorizationException::class);
    }

    public function test_a_retry_with_the_same_key_moves_the_stock_once(): void
    {
        $first = $this->service()->transfer($this->user, $this->alpha->id, $this->beta->id, [$this->line($this->alphaWidget, $this->betaWidget, 10)], null, 'sync-42');
        $again = $this->service()->transfer($this->user, $this->alpha->id, $this->beta->id, [$this->line($this->alphaWidget, $this->betaWidget, 10)], null, 'sync-42');

        $this->assertSame($first->id, $again->id);
        $this->assertSame(40, $this->stock($this->alphaWidget));
        $this->assertSame(15, $this->stock($this->betaWidget));

        $gamma = $this->organization('Gamma');
        $this->homeUser($gamma, 'admin', 'Owner');
        $this->addMember($gamma, $this->user, 'admin');
        $this->expectException(ValidationException::class);
        $this->service()->transfer($this->user, $this->alpha->id, $gamma->id, [$this->line($this->alphaWidget, $this->product($gamma, 'WIDGET'), 1)], null, 'sync-42');
    }

    public function test_it_works_from_a_request_in_either_organization_and_leaves_its_scope_alone(): void
    {
        $this->actingAs($this->user);

        $this->service()->transfer($this->user, $this->alpha->id, $this->beta->id, [$this->line($this->alphaWidget, $this->betaWidget, 3)]);

        $this->assertSame(['WIDGET'], Product::pluck('sku')->all());
        $this->assertSame($this->alphaWidget->id, Product::value('id'));
        $this->assertSame(47, $this->stock($this->alphaWidget));
        $this->assertSame(8, $this->stock($this->betaWidget));
    }

    public function test_hooks_and_webhooks_reach_both_organizations_after_commit(): void
    {
        Queue::fake([WebhookDeliveryJob::class]);
        $alphaHook = Webhook::create(['organization_id' => $this->alpha->id, 'name' => 'Alpha', 'url' => 'https://alpha.example.test/hook', 'events' => ['stock.adjusted'], 'is_active' => true]);
        $betaHook = Webhook::create(['organization_id' => $this->beta->id, 'name' => 'Beta', 'url' => 'https://beta.example.test/hook', 'events' => ['stock.adjusted'], 'is_active' => true]);
        $completed = [];
        add_action('inter_company_transfer_completed', function ($transfer, $actor) use (&$completed) {
            $completed[] = [$transfer->id, $actor->id];
        });
        $this->actingAs($this->user);

        $transfer = $this->service()->transfer($this->user, $this->alpha->id, $this->beta->id, [$this->line($this->alphaWidget, $this->betaWidget, 3)]);

        $this->assertSame([[$transfer->id, $this->user->id]], $completed);
        $this->assertSame(1, WebhookDelivery::where('webhook_id', $alphaHook->id)->count());
        $this->assertSame(1, WebhookDelivery::where('webhook_id', $betaHook->id)->count());
        $this->assertSame($this->beta->id, WebhookDelivery::where('webhook_id', $betaHook->id)->sole()->payload['organization_id']);
    }
}
