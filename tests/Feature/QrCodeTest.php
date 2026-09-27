<?php

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductLocation;
use App\Models\Role;
use App\Models\System\SystemSetting;
use App\Models\User;
use App\Services\QrCodeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QrCodeTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $picker;

    protected Organization $organization;

    protected Product $product;

    protected ProductLocation $location;

    protected function setUp(): void
    {
        parent::setUp();

        SystemSetting::set('installed', true, 'boolean');

        $this->organization = Organization::create([
            'name' => 'QR Org',
            'email' => 'qr@example.com',
            'currency' => 'USD',
            'timezone' => 'UTC',
        ]);

        $this->admin = User::factory()->admin()->forOrganization($this->organization->id)->create();
        $adminRole = Role::firstOrCreate(['slug' => 'system-administrator'], [
            'name' => 'Administrator',
            'is_system' => true,
            'permissions' => ['view_products', 'edit_products', 'manage_locations'],
        ]);
        $this->admin->roles()->syncWithoutDetaching([$adminRole->id]);

        $this->picker = User::factory()->forOrganization($this->organization->id)->create();
        $pickerRole = Role::create([
            'name' => 'Picker',
            'organization_id' => $this->organization->id,
            'permissions' => ['view_products'],
            'is_system' => false,
        ]);
        $this->picker->roles()->sync([$pickerRole->id]);

        $this->location = ProductLocation::create([
            'organization_id' => $this->organization->id,
            'name' => 'Aisle 4 Bin 2',
            'code' => 'A4-B2',
            'aisle' => '4',
            'bin' => '2',
            'is_active' => true,
        ]);

        $this->product = Product::create([
            'organization_id' => $this->organization->id,
            'sku' => 'QR-SKU-1',
            'name' => 'QR Widget',
            'price' => 10,
            'currency' => 'USD',
            'stock' => 3,
            'min_stock' => 1,
            'location_id' => $this->location->id,
        ]);
    }

    public function test_service_builds_payloads_and_svg(): void
    {
        $service = app(QrCodeService::class);

        $this->assertSame('QR-SKU-1', $service->productPayload($this->product, 'sku'));
        $this->assertSame(route('products.show', $this->product), $service->productPayload($this->product, 'url'));
        $this->assertSame('LOC:A4-B2', $service->locationPayload($this->location));

        $noCode = ProductLocation::create([
            'organization_id' => $this->organization->id,
            'name' => 'Overflow',
            'is_active' => true,
        ]);
        $this->assertSame('LOC:#'.$noCode->id, $service->locationPayload($noCode));

        $svg = $service->svg('LOC:A4-B2');
        $this->assertStringContainsString('<svg', $svg);
    }

    public function test_product_qr_json_defaults_to_sku_and_supports_url_mode(): void
    {
        $this->actingAs($this->admin)
            ->getJson(route('products.qr.generate', $this->product))
            ->assertOk()
            ->assertJsonPath('payload', 'QR-SKU-1')
            ->assertJsonPath('mode', 'sku')
            ->assertJson(fn ($json) => $json->where('qr', fn ($qr) => str_starts_with($qr, 'data:image/svg+xml;base64,'))->etc());

        $this->actingAs($this->admin)
            ->getJson(route('products.qr.generate', [$this->product, 'mode' => 'url']))
            ->assertOk()
            ->assertJsonPath('payload', route('products.show', $this->product));

        $this->actingAs($this->admin)
            ->getJson(route('products.qr.generate', [$this->product, 'mode' => 'bogus']))
            ->assertStatus(422);
    }

    public function test_product_qr_label_prints_alongside_the_barcode(): void
    {
        $html = $this->actingAs($this->admin)
            ->get(route('products.qr.print', [$this->product, 'mode' => 'url']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('<svg', $html);
        $this->assertStringContainsString('QR Widget', $html);
        $this->assertStringContainsString(e(route('products.show', $this->product)), $html);

        $bulk = $this->actingAs($this->admin)
            ->get(route('products.qr.bulk-print', ['ids' => (string) $this->product->id]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('QR-SKU-1', $bulk);
    }

    public function test_product_qr_is_org_scoped(): void
    {
        $otherOrg = Organization::create(['name' => 'Other', 'email' => 'o@example.com', 'currency' => 'USD', 'timezone' => 'UTC']);
        $outsider = User::factory()->admin()->forOrganization($otherOrg->id)->create();
        $outsider->roles()->sync([Role::where('slug', 'system-administrator')->first()->id]);

        // Org scoping hides the record (404); a controller check would 403.
        $status = $this->actingAs($outsider)->getJson(route('products.qr.generate', $this->product))->status();
        $this->assertContains($status, [403, 404]);

        $status = $this->actingAs($outsider)->get(route('locations.qr.print', $this->location))->status();
        $this->assertContains($status, [403, 404]);
    }

    public function test_location_qr_labels_print(): void
    {
        $html = $this->actingAs($this->admin)
            ->get(route('locations.qr.print', $this->location))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Aisle 4 Bin 2', $html);
        $this->assertStringContainsString('A4-B2', $html);
        $this->assertStringContainsString('<svg', $html);

        $this->actingAs($this->admin)
            ->get(route('locations.qr.bulk-print', ['ids' => (string) $this->location->id]))
            ->assertOk()
            ->assertSee('A4-B2');

        $this->actingAs($this->picker)
            ->get(route('locations.qr.print', $this->location))
            ->assertForbidden();
    }

    public function test_lookup_resolves_a_scanned_location_qr_code(): void
    {
        $this->actingAs($this->picker)
            ->getJson(route('barcode.lookup', ['code' => 'LOC:A4-B2']))
            ->assertOk()
            ->assertJsonPath('found', true)
            ->assertJsonPath('type', 'location')
            ->assertJsonPath('location.id', $this->location->id)
            ->assertJsonPath('location.code', 'A4-B2')
            ->assertJsonPath('location.product_count', 1);
    }

    public function test_lookup_resolves_a_plain_location_code_and_an_id_payload(): void
    {
        $this->actingAs($this->picker)
            ->getJson(route('barcode.lookup', ['code' => 'A4-B2']))
            ->assertOk()
            ->assertJsonPath('type', 'location');

        $noCode = ProductLocation::create([
            'organization_id' => $this->organization->id,
            'name' => 'Overflow',
            'is_active' => true,
        ]);

        $this->actingAs($this->picker)
            ->getJson(route('barcode.lookup', ['code' => 'LOC:#'.$noCode->id]))
            ->assertOk()
            ->assertJsonPath('location.id', $noCode->id);
    }

    public function test_lookup_prefers_a_product_and_resolves_product_deep_links(): void
    {
        $this->actingAs($this->picker)
            ->getJson(route('barcode.lookup', ['code' => 'QR-SKU-1']))
            ->assertOk()
            ->assertJsonPath('type', 'product')
            ->assertJsonPath('product.id', $this->product->id);

        $this->actingAs($this->picker)
            ->getJson(route('barcode.lookup', ['code' => route('products.show', $this->product)]))
            ->assertOk()
            ->assertJsonPath('type', 'product')
            ->assertJsonPath('product.id', $this->product->id);
    }

    public function test_lookup_does_not_resolve_another_orgs_location_or_product_link(): void
    {
        $otherOrg = Organization::create(['name' => 'Other', 'email' => 'o@example.com', 'currency' => 'USD', 'timezone' => 'UTC']);
        $theirLocation = ProductLocation::create([
            'organization_id' => $otherOrg->id,
            'name' => 'Theirs',
            'code' => 'THEIRS-1',
            'is_active' => true,
        ]);
        $theirProduct = Product::create([
            'organization_id' => $otherOrg->id,
            'sku' => 'THEIR-SKU',
            'name' => 'Theirs',
            'price' => 1,
            'currency' => 'USD',
            'stock' => 1,
            'min_stock' => 0,
        ]);

        $this->actingAs($this->picker)
            ->getJson(route('barcode.lookup', ['code' => 'LOC:THEIRS-1']))
            ->assertNotFound();

        $this->actingAs($this->picker)
            ->getJson(route('barcode.lookup', ['code' => 'LOC:#'.$theirLocation->id]))
            ->assertNotFound();

        $this->actingAs($this->picker)
            ->getJson(route('barcode.lookup', ['code' => route('products.show', $theirProduct)]))
            ->assertNotFound();

        $this->actingAs($this->picker)
            ->getJson(route('barcode.lookup', ['code' => 'https://evil.example/products/'.$this->product->id]))
            ->assertNotFound();
    }

    public function test_api_lookup_resolves_location_codes(): void
    {
        $token = $this->picker->createToken('scanner')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/v1/barcode/'.rawurlencode('LOC:A4-B2'))
            ->assertOk()
            ->assertJsonPath('type', 'location');
    }
}
