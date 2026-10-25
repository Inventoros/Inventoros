<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductBatch;
use App\Models\Inventory\ProductLocation;
use App\Models\Inventory\ProductLocationStock;
use App\Models\Role;
use App\Models\System\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BatchExpiryReportTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-24 12:00:00', 'UTC'));
        SystemSetting::set('installed', true, 'boolean');
        $this->organization = Organization::create([
            'name' => 'Expiry', 'email' => 'org@expiry.test', 'timezone' => 'UTC',
        ]);
        $this->product = Product::create([
            'organization_id' => $this->organization->id, 'name' => 'Milk', 'sku' => 'MILK',
            'price' => 5, 'tracking_type' => 'batch', 'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function user(array $permissions = ['view_reports', 'view_products']): User
    {
        static $n = 0;
        $n++;
        $user = User::create([
            'name' => 'Analyst', 'email' => "analyst{$n}@expiry.test", 'password' => bcrypt('password'),
            'organization_id' => $this->organization->id, 'role' => 'member',
        ]);
        $role = Role::create(['name' => "Analyst {$n}", 'slug' => "analyst-{$n}", 'permissions' => $permissions]);
        $user->roles()->attach($role);

        return $user;
    }

    private function batch(string $number, ?string $expiry, int $quantity = 5, ?Product $product = null): ProductBatch
    {
        $product ??= $this->product;

        return ProductBatch::create([
            'organization_id' => $product->organization_id, 'product_id' => $product->id,
            'batch_number' => $number, 'expiry_date' => $expiry, 'quantity' => $quantity,
        ]);
    }

    private function url(array $filters = []): string
    {
        return route('reports.batch-expiry', $filters);
    }

    public function test_page_and_csv_require_reports_and_product_permissions(): void
    {
        foreach ([[], ['view_reports'], ['view_products']] as $permissions) {
            $this->actingAs($this->user($permissions))->get($this->url())->assertForbidden();
            $this->get($this->url(['export' => 'csv']))->assertForbidden();
        }
        $this->actingAs($this->user())->get($this->url())->assertOk();
    }

    public function test_remaining_dated_lots_have_inclusive_windows_and_correct_totals(): void
    {
        $this->batch('PAST', '2026-10-23', 3);
        $this->batch('TODAY', '2026-10-24', 4);
        $this->batch('DAY7', '2026-10-31', 5);
        $this->batch('DAY8', '2026-11-01', 7);
        $this->batch('DAY30', '2026-11-23', 11);
        $this->batch('DAY31', '2026-11-24', 13);
        $this->batch('DAY90', '2027-01-22', 17);
        $this->batch('DAY91', '2027-01-23', 19);
        $this->batch('UNDATED', null, 999);
        $this->batch('EMPTY', '2026-10-01', 0);
        $this->batch('NEGATIVE', '2026-10-01', -5);

        $this->actingAs($this->user())->get($this->url(['window' => '7']))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Reports/BatchExpiry')
                ->where('asOf', '2026-10-24')->has('batches.data', 3)
                ->where('summary', ['batches' => 3, 'units' => 12, 'expired_units' => 3, 'not_expired_units' => 9])
                ->where('batches.data.0.status', 'expired')->where('batches.data.1.status', 'today')
                ->where('batches.data.2.status', 'upcoming'));
        $this->get($this->url())->assertInertia(fn (Assert $page) => $page->where('summary.batches', 5)->where('summary.units', 30));
        $this->get($this->url(['window' => '90']))->assertInertia(fn (Assert $page) => $page->where('summary.batches', 7)->where('summary.units', 60));
        $this->get($this->url(['window' => 'all']))->assertInertia(fn (Assert $page) => $page->where('summary.batches', 8)->where('summary.units', 79));
        $this->get($this->url(['window' => 'expired']))->assertInertia(fn (Assert $page) => $page
            ->where('summary.batches', 1)->where('summary.units', 3)->where('summary.not_expired_units', 0));
    }

    public function test_report_uses_the_organization_day_across_midnight(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-25 01:00:00', 'UTC'));
        $this->organization->update(['timezone' => 'America/Toronto']);
        $this->batch('LOCAL-TODAY', '2026-10-24');
        $this->actingAs($this->user())->get($this->url())->assertInertia(fn (Assert $page) => $page
            ->where('asOf', '2026-10-24')->where('regional.today', '2026-10-24')
            ->where('batches.data.0.status', 'today')->where('summary.expired_units', 0));
    }

    public function test_archived_products_remain_visible_and_tenant_mismatches_do_not_leak(): void
    {
        $this->batch('LIVE', '2026-10-25', 2);
        $archived = $this->product->replicate()->fill(['name' => 'Archived milk', 'sku' => 'ARCHIVED', 'is_active' => false]);
        $archived->save();
        $this->batch('ARCHIVE', '2026-10-26', 7, $archived);
        $archived->delete();
        $other = Organization::create(['name' => 'Other', 'email' => 'other@expiry.test']);
        $foreign = $this->product->replicate()->fill(['organization_id' => $other->id, 'sku' => 'FOREIGN', 'name' => 'Secret product']);
        $foreign->save();
        $this->batch('FOREIGN-LOT', '2026-10-25', 500, $foreign);
        $bad = $this->batch('MISMATCH', '2026-10-25', 1000, $foreign);
        $bad->update(['organization_id' => $this->organization->id]);

        $this->actingAs($this->user())->get($this->url())->assertInertia(fn (Assert $page) => $page
            ->has('batches.data', 2)->where('summary.units', 9)
            ->where('batches.data.1.product_name', 'Archived milk')->where('batches.data.1.archived', true));
        $this->get($this->url(['search' => 'Secret']))->assertInertia(fn (Assert $page) => $page->has('batches.data', 0));
        $csv = $this->get($this->url(['export' => 'csv']))->assertOk()->streamedContent();
        $this->assertStringContainsString('Archived milk', $csv);
        $this->assertStringNotContainsString('Secret', $csv);
        $this->assertStringNotContainsString('MISMATCH', $csv);
    }

    public function test_search_matches_product_sku_and_batch_including_zero(): void
    {
        $this->batch('0', '2026-10-25', 2);
        $this->batch('OTHER', '2026-10-25', 7);
        $this->actingAs($this->user());
        foreach (['Milk' => 2, 'MILK' => 2, 'OTHER' => 1, '0' => 1, 'absent' => 0] as $search => $count) {
            $this->get($this->url(['search' => $search]))->assertInertia(fn (Assert $page) => $page
                ->has('batches.data', $count)->where('summary.batches', $count));
        }
    }

    public function test_lots_are_not_multiplied_by_product_bins(): void
    {
        foreach (['A', 'B'] as $code) {
            $location = ProductLocation::create(['organization_id' => $this->organization->id, 'name' => $code, 'code' => $code]);
            ProductLocationStock::create([
                'organization_id' => $this->organization->id, 'product_id' => $this->product->id,
                'location_id' => $location->id, 'quantity' => 10,
            ]);
        }
        $this->batch('A', '2026-10-25', 2);
        $this->batch('B', '2026-10-25', 7);
        $this->actingAs($this->user())->get($this->url())->assertInertia(fn (Assert $page) => $page
            ->has('batches.data', 2)->where('summary.units', 9));
    }

    public function test_sorting_precedes_pagination_and_keeps_the_full_summary(): void
    {
        for ($i = 1; $i <= 30; $i++) {
            $this->batch('LOT-'.$i, '2026-10-25', $i);
        }
        $filters = ['search' => 'LOT-', 'window' => '7', 'sort_by' => 'quantity', 'sort_dir' => 'desc'];
        $this->actingAs($this->user())->get($this->url($filters))->assertInertia(fn (Assert $page) => $page
            ->has('batches.data', 25)->where('batches.data.0.quantity', 30)->where('batches.data.24.quantity', 6)
            ->where('summary.batches', 30)->where('summary.units', 465)
            ->where('batches.next_page_url', fn (string $url) => str_contains($url, 'search=LOT-') && str_contains($url, 'sort_dir=desc') && str_contains($url, 'page=2')));
        $this->get($this->url($filters + ['page' => 2]))->assertInertia(fn (Assert $page) => $page
            ->has('batches.data', 5)->where('batches.data.0.quantity', 5)->where('summary.units', 465));
    }

    public function test_csv_preserves_numeric_units_neutralizes_text_and_advertises_the_cap(): void
    {
        $this->product->update(['name' => '=SUM(1,2)', 'sku' => '+SKU']);
        $this->batch('=LOT', '2026-10-24', 3);
        $this->batch('SECOND', '2026-10-25', 4);
        $this->batch('THIRD', '2026-10-26', 5);
        config(['reports.max_rows' => 2]);
        $this->actingAs($this->user())->get($this->url())->assertInertia(fn (Assert $page) => $page
            ->where('exportLimit', 2)->has('batches.data', 3)->where('summary.units', 12));
        $response = $this->get($this->url(['export' => 'csv']))->assertOk()
            ->assertHeader('X-Report-Row-Limit', '2')->assertHeader('X-Report-Truncated', 'true');
        $content = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, substr($content, 3));
        rewind($handle);
        fgetcsv($handle, escape: '');
        $first = fgetcsv($handle, escape: '');
        $second = fgetcsv($handle, escape: '');
        $this->assertSame(["'=SUM(1,2)", "'+SKU", "'=LOT", '3', '2026-10-24', 'today', 'no', '2026-10-24'], $first);
        $this->assertSame('SECOND', $second[2]);
        $this->assertFalse(fgetcsv($handle, escape: ''));
        fclose($handle);
        $this->get($this->url(['search' => 'SECOND', 'export' => 'csv']))->assertHeader('X-Report-Truncated', 'false');
    }

    public function test_invalid_filters_are_rejected_without_interpolating_sql(): void
    {
        $this->actingAs($this->user());
        foreach ([['window' => '365'], ['sort_by' => 'b.quantity; DROP TABLE products'], ['sort_dir' => 'sideways'], ['search' => str_repeat('x', 201)], ['export' => 'pdf']] as $filters) {
            $this->getJson($this->url($filters))->assertUnprocessable();
        }
    }
}
