<?php

declare(strict_types=1);

namespace Tests\Feature\Warehouses;

use App\Models\Inventory\StockAudit;
use App\Models\User;
use App\Services\StockAuditReadService;
use App\Services\StockAuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class StockAuditPaginationTest extends TestCase
{
    use RefreshDatabase, WarehouseAccessFixture;

    private StockAudit $audit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWarehouseAccess();
        $this->audit = $this->makeAudit($this->locationA, 'Paged audit');
        $this->audit->update(['status' => 'in_progress', 'require_variance_approval' => true]);
        $this->seedLines(60);
    }

    private function seedLines(int $count, int $offset = 0): void
    {
        $rows = [];
        for ($i = $offset + 1; $i <= $offset + $count; $i++) {
            $rows[] = ['organization_id' => $this->organization->id, 'sku' => 'PAGE-'.str_pad((string) $i, 5, '0', STR_PAD_LEFT),
                'name' => 'Paged item '.$i, 'stock' => 0, 'min_stock' => 0, 'price' => 10, 'currency' => 'USD', 'is_active' => true];
        }
        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('products')->insert($chunk);
        }
        $lines = DB::table('products')->where('organization_id', $this->organization->id)->whereIn('sku', array_column($rows, 'sku'))->orderBy('id')->pluck('id')
            ->map(fn ($id) => ['stock_audit_id' => $this->audit->id, 'product_id' => $id, 'location_id' => $this->locationA->id,
                'system_quantity' => 1, 'status' => 'pending'])->all();
        foreach (array_chunk($lines, 100) as $chunk) {
            DB::table('stock_audit_items')->insert($chunk);
        }
    }

    public function test_pages_are_bounded_and_summary_covers_lines_on_other_pages(): void
    {
        $last = $this->audit->items()->latest('id')->firstOrFail();
        $last->forceFill(['counted_quantity' => 2, 'discrepancy' => 1, 'status' => 'counted', 'review_status' => 'pending'])->save();
        $this->actingAs($this->admin)->get(route('stock-audits.show', $this->audit))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('audit.items', 25)->where('itemPagination.total', 60)->where('itemPagination.last_page', 3)
            ->where('summary.total_items', 60)->where('summary.counted_items', 1)->where('summary.discrepancies', 1)
            ->where('summary.review_required', 1)->missing('audit.items.0.product.stock')->missing('audit.items.0.product.price'));
        $this->get(route('stock-audits.show', $this->audit).'?page=3')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('audit.items', 10)->where('itemPagination.current_page', 3));
    }

    public function test_filters_do_not_change_whole_audit_completion_totals(): void
    {
        $last = $this->audit->items()->latest('id')->firstOrFail();
        $last->forceFill(['counted_quantity' => 2, 'status' => 'counted', 'review_status' => 'pending'])->save();
        $this->actingAs($this->admin)->get(route('stock-audits.show', $this->audit).'?search=PAGE-00060')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('audit.items', 1)->where('itemPagination.total', 1)->where('summary.total_items', 60)->where('summary.counted_items', 1));
        $this->get(route('stock-audits.show', $this->audit).'?item_status=review')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('audit.items', 1)->where('audit.items.0.id', $last->id)->where('summary.total_items', 60));
    }

    public function test_lookup_finds_an_off_page_line_and_returns_its_default_page(): void
    {
        $url = route('stock-audits.lookup', $this->audit);
        $this->actingAs($this->admin)->getJson($url.'?code=page-00060')->assertOk()
            ->assertJsonPath('found', true)->assertJsonPath('ambiguous', false)->assertJsonPath('page', 3)
            ->assertJsonPath('data.0.product.sku', 'PAGE-00060')->assertJsonCount(1, 'data');
        $this->getJson($url.'?code='.urlencode(route('products.show', $this->audit->items()->latest('id')->firstOrFail()->product_id)))
            ->assertOk()->assertJsonPath('found', true)->assertJsonPath('page', 3);
    }

    public function test_duplicate_matches_are_ambiguous_until_a_location_is_selected(): void
    {
        $first = $this->audit->items()->oldest('id')->firstOrFail();
        $this->audit->items()->create(['product_id' => $first->product_id, 'location_id' => $this->locationB->id,
            'system_quantity' => 3, 'status' => 'pending']);
        $url = route('stock-audits.lookup', $this->audit).'?code=PAGE-00001';
        $this->actingAs($this->admin)->getJson($url)->assertOk()->assertJsonPath('ambiguous', true)->assertJsonCount(2, 'data');
        $this->getJson($url.'&location_id='.$this->locationA->id)->assertOk()->assertJsonPath('ambiguous', false)
            ->assertJsonPath('data.0.id', $first->id);
    }

    public function test_lookup_cannot_match_stock_or_locations_outside_this_audit(): void
    {
        $url = route('stock-audits.lookup', $this->audit);
        $this->actingAs($this->admin)->getJson($url.'?code=BRAVO-1')->assertOk()->assertJsonPath('found', false)->assertJsonCount(0, 'data');
        $this->getJson($url.'?code=LOC:%23'.$this->locationB->id)->assertOk()->assertJsonPath('found', false);
        $this->getJson($url.'?code=LOC:%23'.$this->locationA->id.'&location_id='.$this->locationB->id)
            ->assertOk()->assertJsonPath('found', true)->assertJsonPath('location.id', $this->locationA->id);
    }

    public function test_off_page_item_reads_and_acknowledgements_return_current_whole_audit_totals(): void
    {
        $last = $this->audit->items()->latest('id')->firstOrFail();
        $url = route('stock-audits.items.show', ['stockAudit' => $this->audit->id, 'item' => $last->id]);
        $this->actingAs($this->admin)->getJson($url)->assertOk()->assertJsonPath('data.id', $last->id)->assertJsonPath('summary.total_items', 60);
        $this->postJson(route('stock-audits.items.count', ['stockAudit' => $this->audit->id, 'item' => $last->id]), [
            'counted_quantity' => 2, 'expected_revision' => $last->count_revision,
        ])->assertOk()->assertJsonPath('item.counted_quantity', 2)->assertJsonPath('summary.counted_items', 1)->assertJsonPath('summary.review_required', 1);
    }

    public function test_new_read_endpoints_enforce_organization_warehouse_and_item_ownership(): void
    {
        $last = $this->audit->items()->latest('id')->firstOrFail();
        $foreign = User::factory()->admin()->create();
        $this->actingAs($foreign)->getJson(route('stock-audits.lookup', $this->audit).'?code=PAGE-00060')->assertNotFound();
        $other = $this->makeAudit($this->locationA, 'Other audit');
        $this->actingAs($this->admin)->getJson(route('stock-audits.items.show', ['stockAudit' => $other->id, 'item' => $last->id]))->assertNotFound();
        $this->audit->update(['warehouse_location_id' => $this->locationB->id]);
        $this->actingAs($this->restricted)->getJson(route('stock-audits.lookup', $this->audit).'?code=PAGE-00060')->assertForbidden();
    }

    public function test_rest_pagination_and_limits_are_explicit_without_changing_the_legacy_detail_contract(): void
    {
        $token = $this->admin->createToken('audits', ['view_stock_audits'])->plainTextToken;
        $url = '/api/v1/stock-audits/'.$this->audit->id.'/items';
        $this->withToken($token)->getJson($url.'?page=3')->assertOk()->assertJsonCount(10, 'data')->assertJsonPath('total', 60)
            ->assertJsonPath('summary.total_items', 60);
        $this->getJson($url.'?per_page=10000')->assertUnprocessable()->assertJsonValidationErrors('per_page');
        $this->getJson('/api/v1/stock-audits/'.$this->audit->id)->assertOk()->assertJsonCount(60, 'data.items');
        $this->getJson('/api/v1/stock-audits/'.$this->audit->id.'?include_items=0')->assertOk()
            ->assertJsonMissingPath('data.items')->assertJsonPath('summary.total_items', 60);
    }

    public function test_large_audit_read_cost_and_payload_stay_bounded(): void
    {
        DB::table('stock_audit_items')->where('stock_audit_id', $this->audit->id)->delete();
        DB::table('products')->where('organization_id', $this->organization->id)->where('sku', 'like', 'PAGE-%')->delete();
        $this->seedLines(2000);
        $read = app(StockAuditReadService::class);
        DB::enableQueryLog();
        $page = $read->paginate($this->audit, []);
        $data = $page->getCollection()->map(fn ($line) => $read->present($line));
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertCount(25, $data);
        $this->assertSame(2000, $page->total());
        $this->assertLessThanOrEqual(8, $queries);
        $this->assertLessThan(50000, strlen(json_encode($data, JSON_THROW_ON_ERROR)));
    }

    public function test_completion_streams_lines_in_bounded_pages_and_keeps_the_normal_result(): void
    {
        $this->seedLines(190, 60);
        DB::table('stock_audit_items')->where('stock_audit_id', $this->audit->id)
            ->update(['counted_quantity' => 1, 'status' => 'counted']);
        $reads = [];
        DB::listen(static function ($query) use (&$reads): void {
            if (preg_match('/^select \* from ["`]stock_audit_items["`]/i', $query->sql)) {
                $reads[] = $query->sql;
            }
        });
        $this->assertSame(0, app(StockAuditService::class)->complete($this->audit, $this->admin));
        $this->assertSame(250, $this->audit->items()->where('status', 'adjusted')->count());
        $this->assertNotEmpty($reads);
        foreach ($reads as $sql) {
            $this->assertSame(1, preg_match('/limit (\d+)/i', $sql, $matches), $sql);
            $this->assertLessThanOrEqual(500, (int) $matches[1]);
        }
    }
}
