<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\StockAudit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Api\Concerns\BuildsApiFixtures;
use Tests\TestCase;

/**
 * REST parity for the stock-audit write path: create, start, record counts
 * and complete, through the same StockAuditService as the web controller.
 */
class StockAuditWriteApiTest extends TestCase
{
    use BuildsApiFixtures, RefreshDatabase;

    private Organization $org;

    private User $admin;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->markInstalled();

        $this->org = $this->makeOrganization('Acme');
        $this->admin = $this->makeAdmin($this->org);
        $this->product = $this->makeProduct($this->org, ['stock' => 40]);
    }

    public function test_full_cycle_books_a_recount_adjustment(): void
    {
        Sanctum::actingAs($this->admin);

        $audit = $this->postJson('/api/v1/stock-audits', [
            'name' => 'Q3 spot check',
            'audit_type' => 'spot',
            'product_ids' => [$this->product->id],
        ])->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonCount(1, 'data.items')
            ->json('data');

        $itemId = $audit['items'][0]['id'];

        // Counting before the audit starts is refused.
        $this->postJson("/api/v1/stock-audits/{$audit['id']}/items/{$itemId}/count", ['counted_quantity' => 37])
            ->assertStatus(422);

        $this->postJson("/api/v1/stock-audits/{$audit['id']}/start")->assertOk()->assertJsonPath('data.status', 'in_progress');

        $this->postJson("/api/v1/stock-audits/{$audit['id']}/items/{$itemId}/count", ['counted_quantity' => 37, 'notes' => 'shelf 3'])
            ->assertOk()
            ->assertJsonPath('data.counted_quantity', 37)
            ->assertJsonPath('data.discrepancy', -3);

        $this->postJson("/api/v1/stock-audits/{$audit['id']}/complete")
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('adjustments_created', 1);

        $this->assertSame(37, $this->product->fresh()->stock);

        // Completing again must not re-apply the adjustment.
        $this->postJson("/api/v1/stock-audits/{$audit['id']}/complete")->assertStatus(422);
        $this->assertSame(37, $this->product->fresh()->stock);
    }

    public function test_starting_an_audit_without_items_is_refused(): void
    {
        Sanctum::actingAs($this->admin);
        $location = $this->makeLocation($this->org, 'Empty');

        $id = $this->postJson('/api/v1/stock-audits', [
            'name' => 'Empty', 'audit_type' => 'cycle', 'warehouse_location_id' => $location->id,
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/stock-audits/{$id}/start")
            ->assertStatus(422)
            ->assertJsonPath('error', 'no_items');
    }

    public function test_cross_tenant_ids_are_rejected(): void
    {
        $other = $this->makeOrganization('Other');
        $foreignProduct = $this->makeProduct($other);
        $foreignLocation = $this->makeLocation($other);

        Sanctum::actingAs($this->admin);

        $this->postJson('/api/v1/stock-audits', [
            'name' => 'x', 'audit_type' => 'spot', 'product_ids' => [$foreignProduct->id],
        ])->assertStatus(422)->assertJsonValidationErrors(['product_ids.0']);

        $this->postJson('/api/v1/stock-audits', [
            'name' => 'x', 'audit_type' => 'cycle', 'warehouse_location_id' => $foreignLocation->id,
        ])->assertStatus(422)->assertJsonValidationErrors(['warehouse_location_id']);

        $foreign = StockAudit::create([
            'organization_id' => $other->id,
            'audit_number' => 'AUD-X',
            'name' => 'Foreign',
            'status' => 'draft',
            'audit_type' => 'full',
            'created_by' => $this->admin->id,
        ]);

        $this->postJson("/api/v1/stock-audits/{$foreign->id}/start")->assertNotFound();
        $this->postJson("/api/v1/stock-audits/{$foreign->id}/complete")->assertNotFound();
    }

    public function test_an_item_from_another_audit_is_refused(): void
    {
        Sanctum::actingAs($this->admin);
        $first = $this->postJson('/api/v1/stock-audits', ['name' => 'A', 'audit_type' => 'spot', 'product_ids' => [$this->product->id]])->json('data');
        $second = $this->postJson('/api/v1/stock-audits', ['name' => 'B', 'audit_type' => 'spot', 'product_ids' => [$this->product->id]])->json('data');
        $this->postJson("/api/v1/stock-audits/{$first['id']}/start")->assertOk();

        $this->postJson("/api/v1/stock-audits/{$first['id']}/items/{$second['items'][0]['id']}/count", ['counted_quantity' => 1])
            ->assertStatus(422)
            ->assertJsonPath('error', 'item_mismatch');
    }

    public function test_permissions_split_create_and_manage(): void
    {
        $creator = $this->makeMember($this->org, ['view_stock_audits', 'create_stock_audits']);
        Sanctum::actingAs($creator);

        $id = $this->postJson('/api/v1/stock-audits', ['name' => 'A', 'audit_type' => 'spot', 'product_ids' => [$this->product->id]])
            ->assertCreated()->json('data.id');
        $this->postJson("/api/v1/stock-audits/{$id}/start")->assertForbidden();

        Sanctum::actingAs($this->makeMember($this->org, ['view_stock_audits']));
        $this->postJson('/api/v1/stock-audits', ['name' => 'A', 'audit_type' => 'spot'])->assertForbidden();
    }
}
