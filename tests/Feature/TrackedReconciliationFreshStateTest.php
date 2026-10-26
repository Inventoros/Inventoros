<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductBatch;
use App\Models\Inventory\ProductSerial;
use App\Models\User;
use App\Services\TrackedStockReconciliationService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TrackedReconciliationFreshStateTest extends TestCase
{
    use RefreshDatabase;

    public static function changes(): array
    {
        return [
            'batch cleared' => ['batch', 'cleared'], 'serial cleared' => ['serial', 'cleared'],
            'batch archived' => ['batch', 'archived'], 'serial archived' => ['serial', 'archived'],
            'batch tracking removed' => ['batch', 'untracked'], 'serial tracking removed' => ['serial', 'untracked'],
        ];
    }

    #[DataProvider('changes')]
    public function test_changes_after_candidate_discovery_are_re_read_before_correction(string $type, string $change): void
    {
        Mail::fake();
        Notification::fake();
        $org = Organization::factory()->create();
        $actor = User::factory()->admin()->forOrganization($org->id)->create();
        $product = Product::factory()->create(['organization_id' => $org->id, 'tracking_type' => $type, 'stock' => 5, 'min_stock' => 0]);
        if ($type === 'batch') {
            ProductBatch::create(['organization_id' => $org->id, 'product_id' => $product->id, 'batch_number' => 'FRESH', 'quantity' => 8]);
        } else {
            ProductSerial::create(['organization_id' => $org->id, 'product_id' => $product->id, 'serial_number' => 'FRESH', 'status' => 'available']);
        }
        $changed = false;
        // Simulate a committed change after discovery returned its rows, before
        // reconciliation acquires the target lock. No timing-dependent sleep.
        DB::listen(function (QueryExecuted $event) use (&$changed, $product, $change): void {
            $sql = strtolower(str_replace(['"', '`'], '', $event->sql));
            if ($changed || ! str_starts_with($sql, 'select ') || ! str_contains($sql, 'from products')) {
                return;
            }
            $changed = true;
            if ($change === 'cleared') {
                DB::table('products')->where('id', $product->id)->update(['stock' => 0]);
                DB::table('product_batches')->where('product_id', $product->id)->update(['quantity' => 0]);
                DB::table('product_serials')->where('product_id', $product->id)->update(['status' => 'sold']);
            } elseif ($change === 'archived') {
                DB::table('products')->where('id', $product->id)->update(['deleted_at' => now()]);
            } else {
                DB::table('products')->where('id', $product->id)->update(['tracking_type' => 'none', 'stock' => 4]);
            }
        });

        $this->assertSame(0, app(TrackedStockReconciliationService::class)->reconcile($org->id, $actor));
        $this->assertTrue($changed);
        $this->assertSame($change === 'cleared' ? 0 : ($change === 'archived' ? 5 : 4), Product::withTrashed()->findOrFail($product->id)->stock);
        $this->assertDatabaseCount('stock_adjustments', 0);
    }

    public function test_tracking_totals_ignore_records_with_another_organization(): void
    {
        $org = Organization::factory()->create();
        $other = Organization::factory()->create();
        $product = Product::factory()->create(['organization_id' => $org->id, 'tracking_type' => 'batch', 'stock' => 5]);
        ProductBatch::create(['organization_id' => $org->id, 'product_id' => $product->id, 'batch_number' => 'OWN', 'quantity' => 5]);
        ProductBatch::create(['organization_id' => $other->id, 'product_id' => $product->id, 'batch_number' => 'FOREIGN', 'quantity' => 99]);

        $service = app(TrackedStockReconciliationService::class);
        $this->assertCount(0, $service->discrepancies($org->id));
        $this->assertSame(0, $service->reconcile($org->id, User::factory()->admin()->forOrganization($org->id)->create()));
        $this->assertSame(5, $product->fresh()->stock);
    }
}
