<?php

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductCategory;
use App\Models\Inventory\ProductLocation;
use App\Models\Role;
use App\Models\System\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BarcodeControllerTest extends TestCase
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

        $this->admin = User::create([
            'name' => 'Admin User',
            'email' => 'admin@test.com',
            'password' => bcrypt('password'),
            'organization_id' => $this->organization->id,
            'role' => 'admin',
        ]);

        $category = ProductCategory::create([
            'organization_id' => $this->organization->id,
            'name' => 'Test Category',
            'slug' => 'test-category',
        ]);

        $this->product = Product::create([
            'organization_id' => $this->organization->id,
            'sku' => 'TEST-001',
            'name' => 'Test Product',
            'price' => 99.99,
            'currency' => 'USD',
            'stock' => 100,
            'min_stock' => 10,
            'category_id' => $category->id,
            'barcode' => '1234567890128',
        ]);

        $adminRole = Role::firstOrCreate(
            ['slug' => 'system-administrator'],
            [
                'name' => 'Administrator',
                'is_system' => true,
                'permissions' => ['view_products', 'edit_products'],
            ]
        );

        $this->admin->roles()->syncWithoutDetaching([$adminRole->id]);
    }

    public function test_admin_can_generate_barcode(): void
    {
        $response = $this->actingAs($this->admin)
            ->get(route('products.barcode.generate', $this->product));

        $response->assertStatus(200);
    }

    public function test_admin_can_print_barcode(): void
    {
        $response = $this->actingAs($this->admin)
            ->get(route('products.barcode.print', $this->product));

        $response->assertStatus(200);
    }

    public function test_admin_can_generate_random_barcode(): void
    {
        $response = $this->actingAs($this->admin)
            ->post(route('products.barcode.generate-random', $this->product));

        $response->assertRedirect();
    }

    public function test_admin_can_bulk_print_barcodes(): void
    {
        $response = $this->actingAs($this->admin)
            ->get(route('products.barcode.bulk-print', ['products' => [$this->product->id]]));

        $response->assertStatus(200);
    }

    public function test_guest_cannot_access_barcode_routes(): void
    {
        $response = $this->get(route('products.barcode.generate', $this->product));

        $response->assertRedirect(route('login'));
    }
}
