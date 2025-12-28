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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ImportExportControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $importUser;
    protected User $exportUser;
    protected User $viewOnlyUser;
    protected Organization $organization;
    protected ProductCategory $category;
    protected ProductLocation $location;

    protected function setUp(): void
    {
        parent::setUp();

        // Mark system as installed
        SystemSetting::set('installed', true, 'boolean');

        // Create test organization
        $this->organization = Organization::create([
            'name' => 'Test Organization',
            'email' => 'test@organization.com',
            'phone' => '123-456-7890',
            'address' => '123 Test St',
            'currency' => 'USD',
            'timezone' => 'UTC',
        ]);

        // Create category and location
        $this->category = ProductCategory::create([
            'organization_id' => $this->organization->id,
            'name' => 'Electronics',
            'slug' => 'electronics',
            'is_active' => true,
        ]);

        $this->location = ProductLocation::create([
            'organization_id' => $this->organization->id,
            'name' => 'Warehouse A',
            'code' => 'WH-A',
            'is_active' => true,
        ]);

        // Create users with different permissions
        $this->admin = User::create([
            'name' => 'Admin User',
            'email' => 'admin@test.com',
            'password' => bcrypt('password'),
            'organization_id' => $this->organization->id,
            'role' => 'admin',
        ]);

        $this->importUser = User::create([
            'name' => 'Import User',
            'email' => 'import@test.com',
            'password' => bcrypt('password'),
            'organization_id' => $this->organization->id,
            'role' => 'member',
        ]);

        $this->exportUser = User::create([
            'name' => 'Export User',
            'email' => 'export@test.com',
            'password' => bcrypt('password'),
            'organization_id' => $this->organization->id,
            'role' => 'member',
        ]);

        $this->viewOnlyUser = User::create([
            'name' => 'View Only User',
            'email' => 'viewer@test.com',
            'password' => bcrypt('password'),
            'organization_id' => $this->organization->id,
            'role' => 'member',
        ]);

        // Create system roles
        $this->createSystemRoles();
    }

    protected function createSystemRoles(): void
    {
        // Admin role with full permissions
        $adminRole = Role::firstOrCreate(
            ['slug' => 'system-administrator'],
            [
                'name' => 'Administrator',
                'description' => 'Full system access',
                'is_system' => true,
                'permissions' => [
                    'import_data',
                    'export_data',
                ],
            ]
        );

        // Import only role
        $importRole = Role::firstOrCreate(
            ['slug' => 'import-role'],
            [
                'name' => 'Import Role',
                'description' => 'Import access only',
                'is_system' => true,
                'permissions' => ['import_data'],
            ]
        );

        // Export only role
        $exportRole = Role::firstOrCreate(
            ['slug' => 'export-role'],
            [
                'name' => 'Export Role',
                'description' => 'Export access only',
                'is_system' => true,
                'permissions' => ['export_data'],
            ]
        );

        // View-only role
        $viewerRole = Role::firstOrCreate(
            ['slug' => 'system-viewer'],
            [
                'name' => 'Viewer',
                'description' => 'View only access',
                'is_system' => true,
                'permissions' => ['view_products'],
            ]
        );

        // Assign roles to users
        $this->admin->roles()->syncWithoutDetaching([$adminRole->id]);
        $this->importUser->roles()->syncWithoutDetaching([$importRole->id]);
        $this->exportUser->roles()->syncWithoutDetaching([$exportRole->id]);
        $this->viewOnlyUser->roles()->syncWithoutDetaching([$viewerRole->id]);
    }

    protected function createProduct(array $attributes = []): Product
    {
        return Product::create(array_merge([
            'organization_id' => $this->organization->id,
            'sku' => 'TEST-' . uniqid(),
            'name' => 'Test Product',
            'description' => 'A test product',
            'price' => 99.99,
            'purchase_price' => 50.00,
            'currency' => 'USD',
            'stock' => 100,
            'min_stock' => 10,
            'is_active' => true,
            'category_id' => $this->category->id,
            'location_id' => $this->location->id,
        ], $attributes));
    }

    protected function createValidCsv(): UploadedFile
    {
        $content = "name,sku,barcode,description,category,location,price,currency,purchase_price,stock,min_stock,status,notes\n";
        $content .= "Imported Product,IMP-001,123456789,Test description,Electronics,Warehouse A,149.99,USD,75.00,50,5,active,Test notes\n";
        $content .= "Second Product,IMP-002,987654321,Another product,Electronics,Warehouse A,199.99,USD,100.00,25,3,active,More notes\n";

        return UploadedFile::fake()->createWithContent('products.csv', $content);
    }

    protected function createInvalidCsv(): UploadedFile
    {
        $content = "name,sku,barcode,description,category,location,price,currency,purchase_price,stock,min_stock,status,notes\n";
        $content .= ",MISSING-NAME,123456789,No name product,Electronics,Warehouse A,149.99,USD,75.00,50,5,active,Test notes\n"; // Missing name
        $content .= "Invalid Price,INV-001,123456789,Test,Electronics,Warehouse A,not-a-price,USD,75.00,50,5,active,Test\n"; // Invalid price

        return UploadedFile::fake()->createWithContent('invalid.csv', $content);
    }

    // ==================== INDEX TESTS ====================

    public function test_admin_can_view_import_export_page(): void
    {
        $response = $this->actingAs($this->admin)
            ->get(route('import-export.index'));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('ImportExport/Index')
            ->has('categories')
            ->has('locations')
        );
    }

    public function test_import_user_can_view_import_export_page(): void
    {
        $response = $this->actingAs($this->importUser)
            ->get(route('import-export.index'));

        $response->assertStatus(200);
    }

    public function test_export_user_can_view_import_export_page(): void
    {
        $response = $this->actingAs($this->exportUser)
            ->get(route('import-export.index'));

        $response->assertStatus(200);
    }

    public function test_view_only_user_cannot_view_import_export_page(): void
    {
        $response = $this->actingAs($this->viewOnlyUser)
            ->get(route('import-export.index'));

        $response->assertStatus(403);
    }

    public function test_guest_cannot_view_import_export_page(): void
    {
        $response = $this->get(route('import-export.index'));

        $response->assertRedirect(route('login'));
    }

    // ==================== EXPORT TESTS ====================

    public function test_admin_can_export_products(): void
    {
        $this->createProduct(['name' => 'Export Test Product']);

        $response = $this->actingAs($this->admin)
            ->get(route('import-export.export-products'));

        $response->assertStatus(200);
        $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_export_user_can_export_products(): void
    {
        $this->createProduct();

        $response = $this->actingAs($this->exportUser)
            ->get(route('import-export.export-products'));

        $response->assertStatus(200);
    }

    public function test_import_user_cannot_export_products(): void
    {
        $response = $this->actingAs($this->importUser)
            ->get(route('import-export.export-products'));

        $response->assertStatus(403);
    }

    public function test_export_can_filter_by_category(): void
    {
        $otherCategory = ProductCategory::create([
            'organization_id' => $this->organization->id,
            'name' => 'Other Category',
            'slug' => 'other-category',
            'is_active' => true,
        ]);

        $this->createProduct(['name' => 'Electronics Product', 'category_id' => $this->category->id]);
        $this->createProduct(['name' => 'Other Product', 'category_id' => $otherCategory->id]);

        $response = $this->actingAs($this->admin)
            ->get(route('import-export.export-products', ['category_id' => $this->category->id]));

        $response->assertStatus(200);
    }

    public function test_export_can_filter_by_location(): void
    {
        $otherLocation = ProductLocation::create([
            'organization_id' => $this->organization->id,
            'name' => 'Warehouse B',
            'code' => 'WH-B',
            'is_active' => true,
        ]);

        $this->createProduct(['name' => 'WH-A Product', 'location_id' => $this->location->id]);
        $this->createProduct(['name' => 'WH-B Product', 'location_id' => $otherLocation->id]);

        $response = $this->actingAs($this->admin)
            ->get(route('import-export.export-products', ['location_id' => $this->location->id]));

        $response->assertStatus(200);
    }

    public function test_export_can_filter_by_status(): void
    {
        $this->createProduct(['name' => 'Active Product', 'is_active' => true]);
        $this->createProduct(['name' => 'Inactive Product', 'is_active' => false]);

        $response = $this->actingAs($this->admin)
            ->get(route('import-export.export-products', ['status' => 'active']));

        $response->assertStatus(200);
    }

    public function test_export_can_filter_low_stock(): void
    {
        $this->createProduct(['name' => 'Normal Stock', 'stock' => 100, 'min_stock' => 10]);
        $this->createProduct(['name' => 'Low Stock', 'stock' => 5, 'min_stock' => 10]);

        $response = $this->actingAs($this->admin)
            ->get(route('import-export.export-products', ['low_stock' => true]));

        $response->assertStatus(200);
    }

    public function test_export_only_includes_organization_products(): void
    {
        $otherOrg = Organization::create([
            'name' => 'Other Organization',
            'email' => 'other@org.com',
        ]);

        // Create product for current organization
        $this->createProduct(['name' => 'Our Product']);

        // Create product for other organization
        Product::create([
            'organization_id' => $otherOrg->id,
            'sku' => 'OTHER-001',
            'name' => 'Their Product',
            'price' => 99.99,
            'currency' => 'USD',
            'stock' => 10,
            'min_stock' => 1,
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('import-export.export-products'));

        $response->assertStatus(200);
        // The export should only include products from the user's organization
    }

    // ==================== DOWNLOAD TEMPLATE TESTS ====================

    public function test_admin_can_download_import_template(): void
    {
        $response = $this->actingAs($this->admin)
            ->get(route('import-export.download-template'));

        $response->assertStatus(200);
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $response->assertHeader('content-disposition', 'attachment; filename="product_import_template.csv"');
    }

    public function test_import_user_can_download_template(): void
    {
        $response = $this->actingAs($this->importUser)
            ->get(route('import-export.download-template'));

        $response->assertStatus(200);
    }

    public function test_export_user_cannot_download_template(): void
    {
        $response = $this->actingAs($this->exportUser)
            ->get(route('import-export.download-template'));

        $response->assertStatus(403);
    }

    public function test_template_contains_required_headers(): void
    {
        $response = $this->actingAs($this->admin)
            ->get(route('import-export.download-template'));

        $content = $response->getContent();

        $this->assertStringContains('name', $content);
        $this->assertStringContains('sku', $content);
        $this->assertStringContains('price', $content);
        $this->assertStringContains('stock', $content);
    }

    // ==================== IMPORT TESTS ====================

    public function test_admin_can_import_products(): void
    {
        $file = $this->createValidCsv();

        $response = $this->actingAs($this->admin)
            ->post(route('import-export.import-products'), [
                'file' => $file,
            ]);

        $response->assertRedirect(route('import-export.index'));
        $response->assertSessionHas('success');

        // Verify products were created
        $this->assertDatabaseHas('products', [
            'sku' => 'IMP-001',
            'name' => 'Imported Product',
            'organization_id' => $this->organization->id,
        ]);

        $this->assertDatabaseHas('products', [
            'sku' => 'IMP-002',
            'name' => 'Second Product',
        ]);
    }

    public function test_import_user_can_import_products(): void
    {
        $file = $this->createValidCsv();

        $response = $this->actingAs($this->importUser)
            ->post(route('import-export.import-products'), [
                'file' => $file,
            ]);

        $response->assertRedirect(route('import-export.index'));
    }

    public function test_export_user_cannot_import_products(): void
    {
        $file = $this->createValidCsv();

        $response = $this->actingAs($this->exportUser)
            ->post(route('import-export.import-products'), [
                'file' => $file,
            ]);

        $response->assertStatus(403);
    }

    public function test_import_requires_file(): void
    {
        $response = $this->actingAs($this->admin)
            ->post(route('import-export.import-products'), []);

        $response->assertSessionHasErrors(['file']);
    }

    public function test_import_validates_file_type(): void
    {
        $file = UploadedFile::fake()->create('document.pdf', 100, 'application/pdf');

        $response = $this->actingAs($this->admin)
            ->post(route('import-export.import-products'), [
                'file' => $file,
            ]);

        $response->assertSessionHasErrors(['file']);
    }

    public function test_import_validates_file_size(): void
    {
        // Create a file larger than 10MB
        $file = UploadedFile::fake()->create('large.csv', 11000); // 11MB

        $response = $this->actingAs($this->admin)
            ->post(route('import-export.import-products'), [
                'file' => $file,
            ]);

        $response->assertSessionHasErrors(['file']);
    }

    public function test_import_updates_existing_products_by_sku(): void
    {
        // Create existing product
        $this->createProduct([
            'sku' => 'IMP-001',
            'name' => 'Original Name',
            'price' => 50.00,
            'stock' => 10,
        ]);

        $file = $this->createValidCsv();

        $response = $this->actingAs($this->admin)
            ->post(route('import-export.import-products'), [
                'file' => $file,
            ]);

        $response->assertRedirect(route('import-export.index'));

        // Verify product was updated (not duplicated)
        $this->assertDatabaseCount('products', 2); // IMP-001 updated + IMP-002 created

        $this->assertDatabaseHas('products', [
            'sku' => 'IMP-001',
            'name' => 'Imported Product', // Updated name
            'price' => 149.99, // Updated price
        ]);
    }

    public function test_import_creates_categories_if_not_exists(): void
    {
        $content = "name,sku,barcode,description,category,location,price,currency,purchase_price,stock,min_stock,status,notes\n";
        $content .= "New Category Product,CAT-001,123456789,Test,New Category,Warehouse A,99.99,USD,50.00,10,1,active,Test\n";

        $file = UploadedFile::fake()->createWithContent('products.csv', $content);

        $this->actingAs($this->admin)
            ->post(route('import-export.import-products'), [
                'file' => $file,
            ]);

        $this->assertDatabaseHas('product_categories', [
            'name' => 'New Category',
            'organization_id' => $this->organization->id,
        ]);
    }

    public function test_import_creates_locations_if_not_exists(): void
    {
        $content = "name,sku,barcode,description,category,location,price,currency,purchase_price,stock,min_stock,status,notes\n";
        $content .= "New Location Product,LOC-001,123456789,Test,Electronics,New Warehouse,99.99,USD,50.00,10,1,active,Test\n";

        $file = UploadedFile::fake()->createWithContent('products.csv', $content);

        $this->actingAs($this->admin)
            ->post(route('import-export.import-products'), [
                'file' => $file,
            ]);

        $this->assertDatabaseHas('product_locations', [
            'name' => 'New Warehouse',
            'organization_id' => $this->organization->id,
        ]);
    }

    public function test_import_converts_status_to_is_active(): void
    {
        $content = "name,sku,barcode,description,category,location,price,currency,purchase_price,stock,min_stock,status,notes\n";
        $content .= "Active Product,ACT-001,,,,,99.99,USD,,10,1,active,\n";
        $content .= "Inactive Product,INACT-001,,,,,99.99,USD,,10,1,inactive,\n";

        $file = UploadedFile::fake()->createWithContent('products.csv', $content);

        $this->actingAs($this->admin)
            ->post(route('import-export.import-products'), [
                'file' => $file,
            ]);

        $this->assertDatabaseHas('products', [
            'sku' => 'ACT-001',
            'is_active' => true,
        ]);

        $this->assertDatabaseHas('products', [
            'sku' => 'INACT-001',
            'is_active' => false,
        ]);
    }

    public function test_import_assigns_correct_organization(): void
    {
        $file = $this->createValidCsv();

        $this->actingAs($this->admin)
            ->post(route('import-export.import-products'), [
                'file' => $file,
            ]);

        // All imported products should belong to the user's organization
        $importedProducts = Product::whereIn('sku', ['IMP-001', 'IMP-002'])->get();

        foreach ($importedProducts as $product) {
            $this->assertEquals($this->organization->id, $product->organization_id);
        }
    }

    // ==================== HELPER METHOD ====================

    protected function assertStringContains(string $needle, string $haystack): void
    {
        $this->assertTrue(
            str_contains($haystack, $needle),
            "Failed asserting that '$haystack' contains '$needle'."
        );
    }
}
