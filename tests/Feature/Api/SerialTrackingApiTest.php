<?php

namespace Tests\Feature\Api;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductSerial;
use App\Models\Inventory\ProductCategory;
use App\Models\Inventory\ProductLocation;
use App\Models\Role;
use App\Models\System\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SerialTrackingApiTest extends TestCase
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
            'sku' => 'SERIAL-PROD-001',
            'name' => 'Serial Tracked Product',
            'price' => 499.99,
            'currency' => 'USD',
            'stock' => 100,
            'min_stock' => 10,
            'is_active' => true,
            'tracking_type' => 'serial',
            'category_id' => $category->id,
            'location_id' => $location->id,
        ]);
    }

    public function test_can_create_serial_for_product(): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this->postJson("/api/v1/products/{$this->product->id}/serials", [
            'serial_number' => 'SN-2026-00001',
            'status' => 'available',
            'notes' => 'Factory sealed',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.serial_number', 'SN-2026-00001')
            ->assertJsonPath('data.status', 'available')
            ->assertJsonPath('data.notes', 'Factory sealed');

        $this->assertDatabaseHas('product_serials', [
            'product_id' => $this->product->id,
            'organization_id' => $this->organization->id,
            'serial_number' => 'SN-2026-00001',
            'status' => 'available',
        ]);
    }

    public function test_serial_number_must_be_unique_within_organization(): void
    {
        Sanctum::actingAs($this->admin);

        ProductSerial::create([
            'organization_id' => $this->organization->id,
            'product_id' => $this->product->id,
            'serial_number' => 'SN-DUPLICATE',
            'status' => 'available',
        ]);

        $response = $this->postJson("/api/v1/products/{$this->product->id}/serials", [
            'serial_number' => 'SN-DUPLICATE',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['serial_number']);
    }

    public function test_same_serial_number_allowed_in_different_organization(): void
    {
        Sanctum::actingAs($this->admin);

        $otherOrg = Organization::create([
            'name' => 'Other Org',
            'email' => 'other@org.com',
        ]);

        $otherProduct = Product::create([
            'organization_id' => $otherOrg->id,
            'sku' => 'OTHER-SERIAL-001',
            'name' => 'Other Serial Product',
            'price' => 10.00,
            'stock' => 50,
            'min_stock' => 5,
            'tracking_type' => 'serial',
        ]);

        // Create serial in the other organization
        ProductSerial::create([
            'organization_id' => $otherOrg->id,
            'product_id' => $otherProduct->id,
            'serial_number' => 'SN-SHARED',
            'status' => 'available',
        ]);

        // Should succeed - same serial number, different org
        $response = $this->postJson("/api/v1/products/{$this->product->id}/serials", [
            'serial_number' => 'SN-SHARED',
        ]);

        $response->assertStatus(201);
    }

    public function test_can_list_serials_for_product(): void
    {
        Sanctum::actingAs($this->admin);

        ProductSerial::create([
            'organization_id' => $this->organization->id,
            'product_id' => $this->product->id,
            'serial_number' => 'SN-001',
            'status' => 'available',
        ]);

        ProductSerial::create([
            'organization_id' => $this->organization->id,
            'product_id' => $this->product->id,
            'serial_number' => 'SN-002',
            'status' => 'sold',
        ]);

        $response = $this->getJson("/api/v1/products/{$this->product->id}/serials");

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data');
    }

    public function test_can_view_single_serial(): void
    {
        Sanctum::actingAs($this->admin);

        $serial = ProductSerial::create([
            'organization_id' => $this->organization->id,
            'product_id' => $this->product->id,
            'serial_number' => 'SN-SHOW-001',
            'status' => 'available',
        ]);

        $response = $this->getJson("/api/v1/products/{$this->product->id}/serials/{$serial->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.serial_number', 'SN-SHOW-001');
    }

    public function test_can_update_serial_status(): void
    {
        Sanctum::actingAs($this->admin);

        $serial = ProductSerial::create([
            'organization_id' => $this->organization->id,
            'product_id' => $this->product->id,
            'serial_number' => 'SN-STATUS-001',
            'status' => 'available',
        ]);

        $response = $this->putJson("/api/v1/products/{$this->product->id}/serials/{$serial->id}", [
            'status' => 'sold',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'sold');

        $this->assertDatabaseHas('product_serials', [
            'id' => $serial->id,
            'status' => 'sold',
        ]);
    }

    public function test_serial_status_must_be_valid(): void
    {
        Sanctum::actingAs($this->admin);

        $serial = ProductSerial::create([
            'organization_id' => $this->organization->id,
            'product_id' => $this->product->id,
            'serial_number' => 'SN-INVALID-STATUS',
            'status' => 'available',
        ]);

        $response = $this->putJson("/api/v1/products/{$this->product->id}/serials/{$serial->id}", [
            'status' => 'invalid_status',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    }

    public function test_cannot_create_serial_for_non_serial_tracked_product(): void
    {
        Sanctum::actingAs($this->admin);

        $nonSerialProduct = Product::create([
            'organization_id' => $this->organization->id,
            'sku' => 'NONE-PROD-001',
            'name' => 'Non-Tracked Product',
            'price' => 10.00,
            'stock' => 50,
            'min_stock' => 5,
            'tracking_type' => 'none',
        ]);

        $response = $this->postJson("/api/v1/products/{$nonSerialProduct->id}/serials", [
            'serial_number' => 'SN-INVALID',
        ]);

        $response->assertStatus(422);
    }

    public function test_cannot_access_serial_from_different_organization(): void
    {
        Sanctum::actingAs($this->admin);

        $otherOrg = Organization::create([
            'name' => 'Other Org',
            'email' => 'other@org.com',
        ]);

        $otherProduct = Product::create([
            'organization_id' => $otherOrg->id,
            'sku' => 'OTHER-SER-001',
            'name' => 'Other Serial Product',
            'price' => 10.00,
            'stock' => 50,
            'min_stock' => 5,
            'tracking_type' => 'serial',
        ]);

        $response = $this->getJson("/api/v1/products/{$otherProduct->id}/serials");

        $response->assertStatus(404);
    }

    public function test_can_filter_serials_by_status(): void
    {
        Sanctum::actingAs($this->admin);

        ProductSerial::create([
            'organization_id' => $this->organization->id,
            'product_id' => $this->product->id,
            'serial_number' => 'SN-AVAIL-001',
            'status' => 'available',
        ]);

        ProductSerial::create([
            'organization_id' => $this->organization->id,
            'product_id' => $this->product->id,
            'serial_number' => 'SN-SOLD-001',
            'status' => 'sold',
        ]);

        ProductSerial::create([
            'organization_id' => $this->organization->id,
            'product_id' => $this->product->id,
            'serial_number' => 'SN-DMG-001',
            'status' => 'damaged',
        ]);

        $response = $this->getJson("/api/v1/products/{$this->product->id}/serials?status=available");

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.serial_number', 'SN-AVAIL-001');
    }
}
