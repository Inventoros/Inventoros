<?php

namespace Tests\Feature\Api;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductBatch;
use App\Models\Inventory\ProductCategory;
use App\Models\Inventory\ProductLocation;
use App\Models\Role;
use App\Models\System\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BatchTrackingApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected Organization $organization;
    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        SystemSetting::set('installed', true, 'boolean');

        $this->organization = Organization::create([
            'name' => 'Test Organization',
            'email' => 'test@organization.com',
            'currency' => 'USD',
            'timezone' => 'UTC',
        ]);

        $category = ProductCategory::create([
            'organization_id' => $this->organization->id,
            'name' => 'Electronics',
            'slug' => 'electronics',
            'is_active' => true,
        ]);

        $location = ProductLocation::create([
            'organization_id' => $this->organization->id,
            'name' => 'Warehouse A',
            'code' => 'WH-A',
            'is_active' => true,
        ]);

        $this->admin = User::create([
            'name' => 'Admin User',
            'email' => 'admin@test.com',
            'password' => bcrypt('password'),
            'organization_id' => $this->organization->id,
            'role' => 'admin',
        ]);

        $adminRole = Role::firstOrCreate(
            ['slug' => 'system-administrator'],
            [
                'name' => 'Administrator',
                'is_system' => true,
                'permissions' => ['view_products', 'manage_products'],
            ]
        );
        $this->admin->roles()->syncWithoutDetaching([$adminRole->id]);

        $this->product = Product::create([
            'organization_id' => $this->organization->id,
            'sku' => 'BATCH-PROD-001',
            'name' => 'Batch Tracked Product',
            'price' => 29.99,
            'currency' => 'USD',
            'stock' => 100,
            'min_stock' => 10,
            'is_active' => true,
            'tracking_type' => 'batch',
            'category_id' => $category->id,
            'location_id' => $location->id,
        ]);
    }

    public function test_can_create_batch_for_product(): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this->postJson("/api/v1/products/{$this->product->id}/batches", [
            'batch_number' => 'BATCH-20260312-0001',
            'quantity' => 50,
            'manufactured_date' => '2026-03-01',
            'expiry_date' => '2027-03-01',
            'notes' => 'First batch',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.batch_number', 'BATCH-20260312-0001')
            ->assertJsonPath('data.quantity', 50)
            ->assertJsonPath('data.notes', 'First batch');

        $this->assertDatabaseHas('product_batches', [
            'product_id' => $this->product->id,
            'organization_id' => $this->organization->id,
            'batch_number' => 'BATCH-20260312-0001',
            'quantity' => 50,
        ]);
    }

    public function test_can_auto_generate_batch_number(): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this->postJson("/api/v1/products/{$this->product->id}/batches", [
            'quantity' => 25,
        ]);

        $response->assertStatus(201);
        $batchNumber = $response->json('data.batch_number');
        $this->assertStringStartsWith('BATCH-', $batchNumber);
    }

    public function test_can_list_batches_for_product(): void
    {
        Sanctum::actingAs($this->admin);

        ProductBatch::create([
            'organization_id' => $this->organization->id,
            'product_id' => $this->product->id,
            'batch_number' => 'BATCH-001',
            'quantity' => 10,
        ]);

        ProductBatch::create([
            'organization_id' => $this->organization->id,
            'product_id' => $this->product->id,
            'batch_number' => 'BATCH-002',
            'quantity' => 20,
        ]);

        $response = $this->getJson("/api/v1/products/{$this->product->id}/batches");

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data');
    }

    public function test_can_view_single_batch(): void
    {
        Sanctum::actingAs($this->admin);

        $batch = ProductBatch::create([
            'organization_id' => $this->organization->id,
            'product_id' => $this->product->id,
            'batch_number' => 'BATCH-SHOW-001',
            'quantity' => 30,
        ]);

        $response = $this->getJson("/api/v1/products/{$this->product->id}/batches/{$batch->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.batch_number', 'BATCH-SHOW-001')
            ->assertJsonPath('data.quantity', 30);
    }

    public function test_cannot_create_batch_for_non_batch_tracked_product(): void
    {
        Sanctum::actingAs($this->admin);

        $nonBatchProduct = Product::create([
            'organization_id' => $this->organization->id,
            'sku' => 'NONE-PROD-001',
            'name' => 'Non-Tracked Product',
            'price' => 10.00,
            'stock' => 50,
            'min_stock' => 5,
            'tracking_type' => 'none',
        ]);

        $response = $this->postJson("/api/v1/products/{$nonBatchProduct->id}/batches", [
            'quantity' => 10,
        ]);

        $response->assertStatus(422);
    }

    public function test_cannot_access_batch_from_different_organization(): void
    {
        Sanctum::actingAs($this->admin);

        $otherOrg = Organization::create([
            'name' => 'Other Org',
            'email' => 'other@org.com',
        ]);

        $otherProduct = Product::create([
            'organization_id' => $otherOrg->id,
            'sku' => 'OTHER-BATCH-001',
            'name' => 'Other Batch Product',
            'price' => 10.00,
            'stock' => 50,
            'min_stock' => 5,
            'tracking_type' => 'batch',
        ]);

        $response = $this->getJson("/api/v1/products/{$otherProduct->id}/batches");

        $response->assertStatus(404);
    }
}
