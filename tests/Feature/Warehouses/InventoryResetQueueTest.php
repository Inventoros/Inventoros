<?php

declare(strict_types=1);

namespace Tests\Feature\Warehouses;

use App\Jobs\RunInventoryReset;
use App\Models\Inventory\InventoryResetRun;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductVariant;
use App\Models\Inventory\StockAdjustment;
use App\Models\Inventory\VariantLocationStock;
use App\Models\User;
use App\Services\InventoryResetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class InventoryResetQueueTest extends TestCase
{
    use RefreshDatabase, WarehouseAccessFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWarehouseAccess();
    }

    private function enqueue(): InventoryResetRun
    {
        $service = app(InventoryResetService::class);

        return $service->queue($this->admin, $service->preview($this->admin)['signature'], 'Clear imported stock');
    }

    private function process(InventoryResetRun $run): InventoryResetRun
    {
        app()->call([new RunInventoryReset($run->id), 'handle']);

        return $run->fresh();
    }

    public function test_http_submission_is_queued_and_a_real_database_worker_completes_it_once(): void
    {
        $this->actingAs($this->admin)->get(route('products.stock-reset.preview'))->assertOk();
        $this->post(route('products.stock-reset.store'), [
            'token' => session('stock_reset_preview.token'), 'confirmation' => 'ZERO STOCK',
            'password' => 'password', 'reason' => 'Clear imported stock',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $run = InventoryResetRun::firstOrFail();
        $this->assertSame('pending', $run->status);
        $this->assertSame(100, $this->productA->fresh()->stock);
        $this->assertDatabaseCount('jobs', 1);
        $payload = DB::table('jobs')->value('payload');
        $this->assertStringNotContainsString('password', $payload);
        $this->assertStringNotContainsString('ZERO STOCK', $payload);
        $this->assertDatabaseHas('jobs', ['queue' => 'inventory-resets']);

        Artisan::call('queue:work', ['connection' => 'inventory-resets', '--queue' => 'inventory-resets', '--once' => true, '--tries' => 1]);
        $this->assertSame('completed', $run->fresh()->status);
        $this->assertSame(0, $this->productA->fresh()->stock);
        $this->assertDatabaseCount('jobs', 0);
        $writes = StockAdjustment::count();
        $this->assertGreaterThan(0, $writes);
        $this->process($run);
        $this->assertSame($writes, StockAdjustment::count());
        $this->assertDatabaseHas('stock_adjustments', ['reference_type' => $run->getMorphClass(), 'reference_id' => $run->id]);
    }

    public function test_enqueue_failure_rolls_back_the_operation_and_preserves_stock(): void
    {
        DB::listen(static function ($query): void {
            if (str_starts_with(strtolower($query->sql), 'insert into "jobs"') || str_starts_with(strtolower($query->sql), 'insert into `jobs`')) {
                throw new \RuntimeException('Queue unavailable');
            }
        });
        try {
            $this->enqueue();
            $this->fail('Expected enqueue failure');
        } catch (\RuntimeException $error) {
            $this->assertSame('Queue unavailable', $error->getMessage());
        }
        $this->assertDatabaseCount('inventory_reset_runs', 0);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertSame(100, $this->productA->fresh()->stock);
    }

    public function test_a_second_pending_operation_is_rejected(): void
    {
        $run = $this->enqueue();
        try {
            $this->enqueue();
            $this->fail('Expected pending operation guard');
        } catch (ValidationException $error) {
            $this->assertSame(['reset' => ['stockReset.errors.pending']], $error->errors());
        }
        $this->assertDatabaseCount('inventory_reset_runs', 1);
        $this->assertSame('completed', $this->process($run)->status);
    }

    public function test_stock_changes_while_waiting_fail_without_resetting_anything(): void
    {
        $run = $this->enqueue();
        StockAdjustment::adjust($this->productA, 1, 'correction', actor: $this->admin);
        $run = $this->process($run);
        $this->assertSame('failed', $run->status);
        $this->assertSame('stockReset.errors.changed', $run->error_code);
        $this->assertSame(101, $this->productA->fresh()->stock);
        $this->assertSame(50, $this->productB->fresh()->stock);
        $this->assertDatabaseCount('stock_adjustments', 1);
    }

    public function test_revoking_admin_access_before_processing_prevents_the_reset(): void
    {
        $run = $this->enqueue();
        DB::table('users')->where('id', $this->admin->id)->update(['role' => 'member']);
        $this->assertSame('failed', $this->process($run)->status);
        $this->assertSame(100, $this->productA->fresh()->stock);
        $this->assertDatabaseCount('stock_adjustments', 0);
    }

    public function test_failed_worker_retains_its_result_but_rolls_back_stock_and_ledger(): void
    {
        $run = $this->enqueue();
        $writes = 0;
        StockAdjustment::creating(static function () use (&$writes): void {
            if (++$writes === 2) {
                throw new \RuntimeException('Ledger unavailable');
            }
        });
        $this->assertSame('failed', $this->process($run)->status);
        $this->assertSame(100, $this->productA->fresh()->stock);
        $this->assertSame(50, $this->productB->fresh()->stock);
        $this->assertSame(150, (int) DB::table('product_location_stocks')->sum('quantity'));
        $this->assertDatabaseCount('stock_adjustments', 0);
        $this->assertDatabaseMissing('activity_logs', ['action' => 'inventory_reset']);
    }

    public function test_run_status_requires_an_admin_in_the_same_organization(): void
    {
        $run = $this->enqueue();
        $url = route('products.stock-reset.show', $run->public_id);
        $this->actingAs($this->admin)->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Products/ResetStockStatus')->where('run.public_id', $run->public_id)
            ->where('run.status', 'pending')->missing('run.signature')->missing('run.actor_id'));
        $this->actingAs($this->restricted)->get($url)->assertForbidden();
        $foreign = User::factory()->admin()->create();
        $this->actingAs($foreign)->get($url)->assertNotFound();
    }

    #[DataProvider('invalidPreviews')]
    public function test_invalid_preview_sessions_cannot_enqueue(string $condition): void
    {
        $this->actingAs($this->admin)->get(route('products.stock-reset.preview'))->assertOk();
        $preview = session('stock_reset_preview');
        match ($condition) {
            'expired' => $preview['expires'] = now()->subSecond()->timestamp,
            'organization' => $preview['organization_id'] = $this->organization->id + 999,
            'token' => $preview['token'] = (string) Str::uuid(),
            default => null,
        };
        $token = session('stock_reset_preview.token');
        $this->withSession(['stock_reset_preview' => $condition === 'missing' ? null : $preview])
            ->post(route('products.stock-reset.store'), [
                'token' => $token, 'confirmation' => 'ZERO STOCK', 'password' => 'password', 'reason' => 'Clear imported stock',
            ])->assertSessionHasErrors('reset');
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('inventory_reset_runs', 0);
    }

    public static function invalidPreviews(): array
    {
        return array_map(fn ($condition) => [$condition], ['missing', 'expired', 'organization', 'token']);
    }

    public function test_archived_variants_with_and_without_bins_remain_archived_and_reach_zero(): void
    {
        $variants = [];
        foreach ([true, false] as $binned) {
            $variant = ProductVariant::create([
                'organization_id' => $this->organization->id, 'product_id' => $this->productA->id,
                'sku' => 'ARCHIVED-'.(int) $binned, 'stock' => 8, 'is_active' => false, 'option_values' => [],
            ]);
            if ($binned) {
                VariantLocationStock::updateOrCreate(['product_variant_id' => $variant->id, 'location_id' => $this->locationA->id],
                    ['organization_id' => $this->organization->id, 'quantity' => 8]);
            } else {
                DB::table('variant_location_stocks')->where('product_variant_id', $variant->id)->delete();
            }
            $variant->delete();
            $variants[] = $variant;
        }
        $this->productA->delete();
        $this->assertSame('completed', $this->process($this->enqueue())->status);
        foreach ($variants as $variant) {
            $this->assertSoftDeleted($variant);
            $this->assertSame(0, ProductVariant::withTrashed()->findOrFail($variant->id)->stock);
        }
        $this->assertSoftDeleted($this->productA);
        $this->assertSame(0, (int) DB::table('variant_location_stocks')->sum('quantity'));
    }

    #[DataProvider('malformedOwnership')]
    public function test_malformed_ownership_fails_closed(string $table, string $column): void
    {
        $foreignProduct = Product::factory()->create(['stock' => 17]);
        $variant = ProductVariant::create(['organization_id' => $this->organization->id,
            'product_id' => $this->productA->id, 'sku' => 'INVALID-PARENT', 'stock' => 4, 'option_values' => []]);
        VariantLocationStock::updateOrCreate(['product_variant_id' => $variant->id, 'location_id' => $this->locationA->id],
            ['organization_id' => $this->organization->id, 'quantity' => 4]);
        if ($column === 'location_id') {
            DB::table('product_locations')->where('id', $this->locationA->id)->update(['organization_id' => $foreignProduct->organization_id]);
        } elseif ($table === 'variant_location_stocks') {
            DB::table('product_variants')->where('id', $variant->id)->update(['organization_id' => $foreignProduct->organization_id]);
        } else {
            $id = DB::table($table)->where('organization_id', $this->organization->id)->min('id');
            DB::table($table)->where('id', $id)->update([$column => $foreignProduct->id]);
        }
        try {
            $this->enqueue();
            $this->fail('Expected ownership guard');
        } catch (ValidationException $error) {
            $this->assertSame(['reset' => ['stockReset.errors.integrity']], $error->errors());
        }
        $this->assertSame(100, $this->productA->fresh()->stock);
        $this->assertSame(17, $foreignProduct->fresh()->stock);
        $this->assertDatabaseCount('stock_adjustments', 0);
    }

    public static function malformedOwnership(): array
    {
        return [['product_variants', 'product_id'], ['product_location_stocks', 'product_id'],
            ['variant_location_stocks', 'product_variant_id'], ['products', 'location_id']];
    }

    public function test_record_budget_is_enforced_and_zero_only_products_do_not_seed_bins(): void
    {
        config(['inventory.stock_reset_max_records' => 1]);
        $this->assertTrue(app(InventoryResetService::class)->preview($this->admin)['blockers']['limit']);
        config(['inventory.stock_reset_max_records' => 5]);
        $empty = $this->makeProduct('Empty', 'ZERO-ONLY', null, 0);
        $this->assertSame('completed', $this->process($this->enqueue())->status);
        $this->assertDatabaseMissing('product_location_stocks', ['product_id' => $empty->id]);
    }
}
