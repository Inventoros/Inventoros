<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductLocation;
use App\Models\Inventory\StockTransfer;
use App\Models\User;
use App\Services\ProductLocationStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Api\Concerns\BuildsApiFixtures;
use Tests\TestCase;

/**
 * REST parity for stock transfers: create/ship/complete/cancel through the
 * same StockTransferService as the web controller, including the
 * ProductLocationStockService::move() bin semantics.
 */
class StockTransferApiTest extends TestCase
{
    use BuildsApiFixtures, RefreshDatabase;

    private Organization $org;

    private User $admin;

    private ProductLocation $from;

    private ProductLocation $to;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->markInstalled();

        $this->org = $this->makeOrganization('Acme');
        $this->admin = $this->makeAdmin($this->org);
        $this->from = $this->makeLocation($this->org, 'Front');
        $this->to = $this->makeLocation($this->org, 'Back');
        $this->product = $this->makeProduct($this->org, ['stock' => 30, 'location_id' => $this->from->id]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(int $quantity = 10): array
    {
        return [
            'from_location_id' => $this->from->id,
            'to_location_id' => $this->to->id,
            'notes' => 'Rebalance',
            'items' => [['product_id' => $this->product->id, 'quantity' => $quantity]],
        ];
    }

    public function test_create_ship_and_complete_moves_stock_between_bins(): void
    {
        Sanctum::actingAs($this->admin);

        $id = $this->postJson('/api/v1/stock-transfers', $this->payload(10))
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.items.0.quantity', 10)
            ->json('data.id');

        $this->postJson("/api/v1/stock-transfers/{$id}/ship", ['tracking_number' => 'TRK-1'])
            ->assertOk()
            ->assertJsonPath('data.status', 'in_transit')
            ->assertJsonPath('data.tracking_number', 'TRK-1');

        $this->postJson("/api/v1/stock-transfers/{$id}/complete")
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');

        $product = $this->product->fresh();
        $bins = app(ProductLocationStockService::class);

        // Total on hand is unchanged; the quantity moved between the bins.
        $this->assertSame(30, $product->stock);
        $this->assertSame(20, $bins->quantityAt($product, $this->from->id));
        $this->assertSame(10, $bins->quantityAt($product, $this->to->id));
        $this->assertSame($this->to->id, $product->location_id);

        // Completing twice must not move the goods twice.
        $this->postJson("/api/v1/stock-transfers/{$id}/complete")
            ->assertStatus(422)
            ->assertJsonPath('error', 'invalid_status');
        $this->assertSame(10, $bins->quantityAt($product->fresh(), $this->to->id));
    }

    public function test_completing_with_a_short_source_bin_is_refused(): void
    {
        Sanctum::actingAs($this->admin);
        $id = $this->postJson('/api/v1/stock-transfers', $this->payload(31))->json('data.id');

        $this->postJson("/api/v1/stock-transfers/{$id}/complete")->assertStatus(422);
        $this->assertSame('pending', StockTransfer::find($id)->status);
    }

    public function test_cancel(): void
    {
        Sanctum::actingAs($this->admin);
        $id = $this->postJson('/api/v1/stock-transfers', $this->payload())->json('data.id');

        $this->postJson("/api/v1/stock-transfers/{$id}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->postJson("/api/v1/stock-transfers/{$id}/ship")->assertStatus(422);
    }

    public function test_index_and_show(): void
    {
        Sanctum::actingAs($this->admin);
        $id = $this->postJson('/api/v1/stock-transfers', $this->payload())->json('data.id');

        $this->getJson('/api/v1/stock-transfers?status=pending')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.from_location.id', $this->from->id);

        $this->getJson("/api/v1/stock-transfers/{$id}")
            ->assertOk()
            ->assertJsonPath('data.items.0.product.id', $this->product->id);
    }

    public function test_cross_tenant_ids_are_rejected(): void
    {
        $other = $this->makeOrganization('Other');
        $foreignLocation = $this->makeLocation($other, 'Foreign');
        $foreignProduct = $this->makeProduct($other);

        Sanctum::actingAs($this->admin);

        $this->postJson('/api/v1/stock-transfers', array_merge($this->payload(), ['to_location_id' => $foreignLocation->id]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['to_location_id']);

        $this->postJson('/api/v1/stock-transfers', array_merge($this->payload(), [
            'items' => [['product_id' => $foreignProduct->id, 'quantity' => 1]],
        ]))->assertStatus(422)->assertJsonValidationErrors(['items.0.product_id']);

        $foreign = StockTransfer::create([
            'organization_id' => $other->id,
            'transfer_number' => 'TRF-X',
            'from_location_id' => $foreignLocation->id,
            'to_location_id' => $foreignLocation->id,
            'transferred_by' => $this->admin->id,
            'status' => 'pending',
        ]);

        $this->getJson("/api/v1/stock-transfers/{$foreign->id}")->assertNotFound();
        $this->postJson("/api/v1/stock-transfers/{$foreign->id}/cancel")->assertNotFound();
        $this->assertSame('pending', $foreign->fresh()->status);
    }

    public function test_requires_transfer_stock(): void
    {
        Sanctum::actingAs($this->makeMember($this->org, ['view_products']));

        $this->getJson('/api/v1/stock-transfers')->assertForbidden();
        $this->postJson('/api/v1/stock-transfers', $this->payload())->assertForbidden();
    }
}
