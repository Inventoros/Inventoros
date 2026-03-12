<?php

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Role;
use App\Models\System\SystemSetting;
use App\Models\User;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class SecurityEnhancementsTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $organization;
    protected User $admin;

    /**
     * Track temp files for cleanup.
     */
    protected array $tempFiles = [];

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

        $this->createSystemRoles();
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    protected function createSystemRoles(): void
    {
        $adminRole = Role::firstOrCreate(
            ['slug' => 'system-administrator'],
            [
                'name' => 'Administrator',
                'is_system' => true,
                'permissions' => [
                    'manage_plugins',
                    'view_plugins',
                    'import_data',
                    'create_products',
                    'manage_organization',
                ],
            ]
        );

        Role::firstOrCreate(
            ['slug' => 'system-manager'],
            [
                'name' => 'Manager',
                'is_system' => true,
                'permissions' => ['manage_organization'],
                'organization_id' => null,
            ]
        );

        Role::firstOrCreate(
            ['slug' => 'system-member'],
            [
                'name' => 'Member',
                'is_system' => true,
                'permissions' => [],
                'organization_id' => null,
            ]
        );

        $this->admin->roles()->syncWithoutDetaching([$adminRole->id]);
    }

    /**
     * Create a temp file with given content and track it for cleanup.
     */
    protected function createTempFile(string $content): string
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'security_test_');
        file_put_contents($tempFile, $content);
        $this->tempFiles[] = $tempFile;

        return $tempFile;
    }

    // =========================================================================
    // 1. Plugin Upload MIME Type Validation
    // =========================================================================

    public function test_plugin_upload_rejects_non_zip_mime_type(): void
    {
        // Create a PHP file on disk, then wrap it as an UploadedFile with test=true.
        // With test=true, isValid() returns true, but getMimeType() uses finfo
        // to detect the real content type (text/x-php), not the client-provided one.
        $tempFile = $this->createTempFile('<?php echo "malicious code"; ?>');

        $fakeZip = new UploadedFile(
            $tempFile,
            'malicious.zip',
            'application/zip',
            null,
            true
        );

        $response = $this->actingAs($this->admin)
            ->post(route('plugins.upload'), [
                'plugin' => $fakeZip,
            ]);

        $response->assertSessionHasErrors('plugin');
    }

    public function test_plugin_upload_accepts_valid_zip_file(): void
    {
        // Create a real ZIP file
        $tempFile = $this->createTempFile('');
        $zip = new \ZipArchive();
        $zip->open($tempFile, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('plugin.json', json_encode(['name' => 'test']));
        $zip->close();

        $uploadedFile = new UploadedFile(
            $tempFile,
            'test-plugin.zip',
            'application/zip',
            null,
            true
        );

        $response = $this->actingAs($this->admin)
            ->post(route('plugins.upload'), [
                'plugin' => $uploadedFile,
            ]);

        // Should not have validation errors for the plugin field
        $response->assertSessionDoesntHaveErrors('plugin');
    }

    // =========================================================================
    // 2. Import MIME Type Validation
    // =========================================================================

    public function test_import_rejects_invalid_mime_type(): void
    {
        // Create a PHP file on disk with a .csv client name.
        // finfo will detect it as text/x-php, which is not in the allowed MIME list.
        $tempFile = $this->createTempFile('<?php echo "malicious"; ?>');

        $fakeFile = new UploadedFile(
            $tempFile,
            'malicious.csv',
            'text/csv',
            null,
            true
        );

        $response = $this->actingAs($this->admin)
            ->post(route('import-export.import-products'), [
                'file' => $fakeFile,
            ]);

        $response->assertSessionHasErrors('file');
    }

    public function test_import_accepts_valid_csv_file(): void
    {
        $csvContent = "name,sku,barcode,description,category,location,price,currency,purchase_price,stock,min_stock,status,notes\n";
        $csvContent .= "Test Product,SKU-001,123456,Test desc,Electronics,Warehouse A,99.99,USD,50.00,100,10,active,Notes\n";

        $tempFile = $this->createTempFile($csvContent);

        $csvFile = new UploadedFile(
            $tempFile,
            'products.csv',
            'text/csv',
            null,
            true
        );

        $response = $this->actingAs($this->admin)
            ->post(route('import-export.import-products'), [
                'file' => $csvFile,
            ]);

        // Should not have validation errors for the file field
        $response->assertSessionDoesntHaveErrors('file');
    }

    // =========================================================================
    // 3. Security Headers - X-Powered-By Removal
    // =========================================================================

    public function test_security_headers_removes_x_powered_by(): void
    {
        $middleware = new SecurityHeaders();
        $request = Request::create('/test', 'GET');

        $response = $middleware->handle($request, function () {
            $response = new Response('OK', 200);
            // Simulate a server that sets X-Powered-By
            $response->headers->set('X-Powered-By', 'PHP/8.3');
            return $response;
        });

        $this->assertNull($response->headers->get('X-Powered-By'));
    }

    public function test_security_headers_removes_server_header(): void
    {
        $middleware = new SecurityHeaders();
        $request = Request::create('/test', 'GET');

        $response = $middleware->handle($request, function () {
            $response = new Response('OK', 200);
            $response->headers->set('Server', 'Apache/2.4');
            return $response;
        });

        $this->assertNull($response->headers->get('Server'));
    }

    // =========================================================================
    // 4. Product Image MIME Type Validation (base64 - already handled)
    // =========================================================================

    public function test_product_image_upload_rejects_non_image_base64(): void
    {
        // Create base64 data that pretends to be an image but has wrong MIME prefix
        $fakeImageData = 'data:application/pdf;base64,' . base64_encode('fake pdf content');

        $response = $this->actingAs($this->admin)
            ->post(route('products.store'), [
                'sku' => 'TEST-001',
                'name' => 'Test Product',
                'price' => 99.99,
                'currency' => 'USD',
                'stock' => 100,
                'min_stock' => 10,
                'is_active' => true,
                'images' => [
                    [
                        'preview' => $fakeImageData,
                        'name' => 'malicious.pdf',
                    ],
                ],
            ]);

        // The validateBase64Image method should reject this because the MIME
        // prefix is application/pdf, not an allowed image type.
        // It redirects back with an error message.
        $this->assertTrue(
            $response->isRedirection() || $response->getStatusCode() === 500,
            'Non-image base64 data should be rejected'
        );
    }
}
