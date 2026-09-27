<?php

namespace Tests\Feature;

use App\Enums\BarcodeType;
use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Role;
use App\Models\System\SystemSetting;
use App\Models\User;
use App\Services\BarcodeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BarcodeTypeRenderingTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        SystemSetting::set('installed', true, 'boolean');

        $this->organization = Organization::create([
            'name' => 'Barcode Org',
            'email' => 'bc@example.com',
            'currency' => 'USD',
            'timezone' => 'UTC',
        ]);

        $this->admin = User::factory()->admin()->forOrganization($this->organization->id)->create();

        $role = Role::firstOrCreate(['slug' => 'system-administrator'], [
            'name' => 'Administrator',
            'is_system' => true,
            'permissions' => ['view_products', 'create_products', 'edit_products'],
        ]);
        $this->admin->roles()->syncWithoutDetaching([$role->id]);
    }

    private function product(array $attributes = []): Product
    {
        return Product::create(array_merge([
            'organization_id' => $this->organization->id,
            'sku' => 'SKU-'.uniqid(),
            'name' => 'Widget',
            'price' => 10,
            'currency' => 'USD',
            'stock' => 5,
            'min_stock' => 1,
        ], $attributes));
    }

    public function test_barcode_type_column_defaults_to_null_and_resolves_by_detection(): void
    {
        $ean = $this->product(['barcode' => '4006381333931']);
        $upc = $this->product(['barcode' => '036000291452']);
        $text = $this->product(['barcode' => 'ABC-123']);

        $this->assertNull($ean->fresh()->barcode_type);
        $this->assertSame(BarcodeType::EAN_13, $ean->resolvedBarcodeType());
        $this->assertSame(BarcodeType::UPC_A, $upc->resolvedBarcodeType());
        $this->assertSame(BarcodeType::CODE_128, $text->resolvedBarcodeType());
    }

    public function test_generate_renders_the_products_type(): void
    {
        $product = $this->product(['barcode' => '4006381333931']);

        $this->actingAs($this->admin)
            ->getJson(route('products.barcode.generate', $product))
            ->assertOk()
            ->assertJsonPath('type', 'ean13')
            ->assertJsonPath('code', '4006381333931');
    }

    public function test_print_uses_the_explicit_type_and_accepts_an_override(): void
    {
        $product = $this->product(['barcode' => '96385074', 'barcode_type' => 'ean8']);
        $service = new BarcodeService;

        $html = $this->actingAs($this->admin)
            ->get(route('products.barcode.print', $product))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString($service->generateSVG('96385074', 3, 80, BarcodeType::EAN_8), $html);
        $this->assertStringContainsString('EAN-8', $html);

        $override = $this->actingAs($this->admin)
            ->get(route('products.barcode.print', [$product, 'type' => 'code128']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString($service->generateSVG('96385074', 3, 80, BarcodeType::CODE_128), $override);
    }

    public function test_single_print_page_button_label_is_clean(): void
    {
        $product = $this->product(['barcode' => 'CLEAN-1']);

        $html = $this->actingAs($this->admin)
            ->get(route('products.barcode.print', $product))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression('/<svg[^>]*aria-hidden="true"[\s\S]*?<\/svg>\s*Print Barcode\s*<\/button>/', $html);
        $this->assertStringNotContainsString("\u{FFFD}", $html);
        $this->assertDoesNotMatchRegularExpression('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $html);
    }

    public function test_print_rejects_a_type_the_value_is_not_valid_for(): void
    {
        $product = $this->product(['barcode' => 'abc-lower']);

        $this->actingAs($this->admin)
            ->get(route('products.barcode.print', [$product, 'type' => 'ean13']))
            ->assertStatus(422);

        $this->actingAs($this->admin)
            ->get(route('products.barcode.print', [$product, 'type' => 'qr-nonsense']))
            ->assertStatus(422);
    }

    public function test_bulk_print_honours_a_chosen_type_and_falls_back_per_product(): void
    {
        $ean = $this->product(['barcode' => '4006381333931']);
        $text = $this->product(['barcode' => 'TEXT-1']);
        $service = new BarcodeService;

        $html = $this->actingAs($this->admin)
            ->get(route('products.barcode.bulk-print', ['ids' => $ean->id.','.$text->id, 'type' => 'ean13']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString($service->generateSVG('4006381333931', 2, 60, BarcodeType::EAN_13), $html);
        // TEXT-1 is not valid EAN-13, so it keeps its own (Code 128) symbology.
        $this->assertStringContainsString($service->generateSVG('TEXT-1', 2, 60, BarcodeType::CODE_128), $html);
    }

    public function test_generate_random_and_from_sku_mark_the_product_ean13(): void
    {
        $product = $this->product(['barcode' => null, 'barcode_type' => 'code39']);

        $this->actingAs($this->admin)
            ->postJson(route('products.barcode.generate-random', $product))
            ->assertOk();

        $this->assertSame('ean13', $product->fresh()->barcode_type);

        $product->update(['barcode_type' => 'code128']);
        $this->actingAs($this->admin)
            ->postJson(route('products.barcode.generate-from-sku', $product))
            ->assertOk();

        $this->assertSame('ean13', $product->fresh()->barcode_type);
    }

    public function test_product_update_validates_the_barcode_against_its_type(): void
    {
        $product = $this->product(['barcode' => 'OLD']);

        $payload = [
            'sku' => $product->sku,
            'name' => 'Widget',
            'price' => 10,
            'currency' => 'USD',
            'stock' => 5,
            'min_stock' => 1,
        ];

        $this->actingAs($this->admin)
            ->put(route('products.update', $product), $payload + ['barcode' => '4006381333932', 'barcode_type' => 'ean13'])
            ->assertSessionHasErrors('barcode');

        $this->actingAs($this->admin)
            ->put(route('products.update', $product), $payload + ['barcode' => '4006381333931', 'barcode_type' => 'ean13'])
            ->assertSessionHasNoErrors();

        $this->assertSame('ean13', $product->fresh()->barcode_type);

        $this->actingAs($this->admin)
            ->put(route('products.update', $product), $payload + ['barcode' => 'X', 'barcode_type' => 'pdf417'])
            ->assertSessionHasErrors('barcode_type');
    }

    public function test_api_product_create_validates_barcode_type(): void
    {
        $token = $this->admin->createToken('t')->plainTextToken;

        $this->withToken($token)->postJson('/api/v1/products', [
            'sku' => 'API-1',
            'name' => 'Api Widget',
            'price' => 10,
            'currency' => 'USD',
            'stock' => 1,
            'min_stock' => 0,
            'barcode' => '036000291453',
            'barcode_type' => 'upca',
        ])->assertStatus(422)->assertJsonValidationErrors('barcode');

        $this->withToken($token)->postJson('/api/v1/products', [
            'sku' => 'API-2',
            'name' => 'Api Widget',
            'price' => 10,
            'currency' => 'USD',
            'stock' => 1,
            'min_stock' => 0,
            'barcode' => '036000291452',
            'barcode_type' => 'upca',
        ])->assertCreated()->assertJsonPath('data.barcode_type', 'upca');
    }
}
