<?php

namespace Tests\Unit;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductCategory;
use App\Models\System\SystemSetting;
use App\Services\SKUGeneratorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SKUGeneratorServiceTest extends TestCase
{
    use RefreshDatabase;

    protected SKUGeneratorService $service;
    protected Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new SKUGeneratorService();

        SystemSetting::set('installed', true, 'boolean');

        $this->organization = Organization::create([
            'name' => 'Test Org',
            'email' => 'test@org.com',
        ]);
    }

    public function test_generate_replaces_category_placeholder(): void
    {
        $category = ProductCategory::create([
            'organization_id' => $this->organization->id,
            'name' => 'Electronics',
        ]);

        $sku = $this->service->generate('{category}-001', $this->organization->id, null, $category->id);

        $this->assertStringStartsWith('ELE-', $sku);
    }

    public function test_generate_uses_unc_when_no_category(): void
    {
        $sku = $this->service->generate('{category}-001', $this->organization->id);

        $this->assertSame('UNC-001', $sku);
    }

    public function test_generate_uses_unc_for_invalid_category_id(): void
    {
        $sku = $this->service->generate('{category}-001', $this->organization->id, null, 99999);

        $this->assertSame('UNC-001', $sku);
    }

    public function test_generate_replaces_category_id_placeholder(): void
    {
        $sku = $this->service->generate('{category_id}XXX', $this->organization->id, null, 5);

        $this->assertSame('05XXX', $sku);
    }

    public function test_generate_category_id_defaults_to_00(): void
    {
        $sku = $this->service->generate('{category_id}XXX', $this->organization->id);

        $this->assertSame('00XXX', $sku);
    }

    public function test_generate_replaces_name_placeholder(): void
    {
        $sku = $this->service->generate('{name}-001', $this->organization->id, 'Laptop');

        $this->assertSame('LAP-001', $sku);
    }

    public function test_generate_name_strips_non_alpha(): void
    {
        $sku = $this->service->generate('{name}', $this->organization->id, '123-Product');

        $this->assertSame('PRO', $sku);
    }

    public function test_generate_name_defaults_to_prd(): void
    {
        $sku = $this->service->generate('{name}-001', $this->organization->id);

        $this->assertSame('PRD-001', $sku);
    }

    public function test_generate_replaces_random_placeholder(): void
    {
        $sku = $this->service->generate('PRD-{random}', $this->organization->id);

        $this->assertMatchesRegularExpression('/^PRD-[A-Z0-9]{6}$/', $sku);
    }

    public function test_generate_replaces_number_placeholder(): void
    {
        $sku = $this->service->generate('PRD-{number}', $this->organization->id);

        $this->assertMatchesRegularExpression('/^PRD-\d{6}$/', $sku);
    }

    public function test_generate_number_increments_from_last_product(): void
    {
        Product::create([
            'organization_id' => $this->organization->id,
            'name' => 'Existing',
            'sku' => 'EXIST-001',
            'price' => 10,
        ]);

        $lastProduct = Product::where('organization_id', $this->organization->id)
            ->orderBy('id', 'desc')
            ->first();
        $expectedNumber = str_pad($lastProduct->id + 1, 6, '0', STR_PAD_LEFT);

        $sku = $this->service->generate('{number}', $this->organization->id);

        $this->assertSame($expectedNumber, $sku);
    }

    public function test_generate_replaces_date_placeholder(): void
    {
        $sku = $this->service->generate('PRD-{date}', $this->organization->id);
        $expected = 'PRD-' . date('ymd');

        $this->assertSame($expected, $sku);
    }

    public function test_generate_replaces_year_placeholder(): void
    {
        $sku = $this->service->generate('{year}-PRD', $this->organization->id);
        $expected = date('Y') . '-PRD';

        $this->assertSame($expected, $sku);
    }

    public function test_generate_replaces_month_placeholder(): void
    {
        $sku = $this->service->generate('PRD-{month}', $this->organization->id);
        $expected = 'PRD-' . date('m');

        $this->assertSame($expected, $sku);
    }

    public function test_generate_replaces_timestamp_placeholder(): void
    {
        $before = time();
        $sku = $this->service->generate('PRD-{timestamp}', $this->organization->id);
        $after = time();

        $this->assertMatchesRegularExpression('/^PRD-\d{10,}$/', $sku);

        // Extract timestamp and verify it's in valid range
        $ts = (int) str_replace('PRD-', '', $sku);
        $this->assertGreaterThanOrEqual($before, $ts);
        $this->assertLessThanOrEqual($after, $ts);
    }

    public function test_generate_replaces_multiple_placeholders(): void
    {
        $category = ProductCategory::create([
            'organization_id' => $this->organization->id,
            'name' => 'Tools',
        ]);

        $sku = $this->service->generate(
            '{category}-{name}-{year}',
            $this->organization->id,
            'Hammer',
            $category->id
        );

        $expected = 'TOO-HAM-' . date('Y');
        $this->assertSame($expected, $sku);
    }

    public function test_generate_returns_literal_when_no_placeholders(): void
    {
        $sku = $this->service->generate('STATIC-SKU', $this->organization->id);

        $this->assertSame('STATIC-SKU', $sku);
    }

    // ========================================
    // isUnique
    // ========================================

    public function test_is_unique_returns_true_when_no_duplicate(): void
    {
        $this->assertTrue($this->service->isUnique('NEW-SKU', $this->organization->id));
    }

    public function test_is_unique_returns_false_when_duplicate_exists(): void
    {
        Product::create([
            'organization_id' => $this->organization->id,
            'name' => 'Test',
            'sku' => 'DUPE-SKU',
            'price' => 10,
        ]);

        $this->assertFalse($this->service->isUnique('DUPE-SKU', $this->organization->id));
    }

    public function test_is_unique_excludes_given_product_id(): void
    {
        $product = Product::create([
            'organization_id' => $this->organization->id,
            'name' => 'Test',
            'sku' => 'MY-SKU',
            'price' => 10,
        ]);

        $this->assertTrue($this->service->isUnique('MY-SKU', $this->organization->id, $product->id));
    }

    public function test_is_unique_is_scoped_by_organization(): void
    {
        $otherOrg = Organization::create([
            'name' => 'Other Org',
            'email' => 'other@org.com',
        ]);

        Product::create([
            'organization_id' => $otherOrg->id,
            'name' => 'Test',
            'sku' => 'CROSS-ORG',
            'price' => 10,
        ]);

        // Same SKU should be unique in our organization
        $this->assertTrue($this->service->isUnique('CROSS-ORG', $this->organization->id));
    }

    // ========================================
    // generateUnique
    // ========================================

    public function test_generate_unique_returns_base_when_unique(): void
    {
        $sku = $this->service->generateUnique('STATIC-SKU', $this->organization->id);

        $this->assertSame('STATIC-SKU', $sku);
    }

    public function test_generate_unique_adds_suffix_when_duplicate(): void
    {
        Product::create([
            'organization_id' => $this->organization->id,
            'name' => 'Test',
            'sku' => 'STATIC-SKU',
            'price' => 10,
        ]);

        $sku = $this->service->generateUnique('STATIC-SKU', $this->organization->id);

        $this->assertSame('STATIC-SKU-1', $sku);
    }

    public function test_generate_unique_increments_suffix_until_unique(): void
    {
        Product::create([
            'organization_id' => $this->organization->id,
            'name' => 'Test 1',
            'sku' => 'STATIC-SKU',
            'price' => 10,
        ]);
        Product::create([
            'organization_id' => $this->organization->id,
            'name' => 'Test 2',
            'sku' => 'STATIC-SKU-1',
            'price' => 10,
        ]);

        $sku = $this->service->generateUnique('STATIC-SKU', $this->organization->id);

        $this->assertSame('STATIC-SKU-2', $sku);
    }

    // ========================================
    // Static methods
    // ========================================

    public function test_get_available_patterns_returns_array(): void
    {
        $patterns = SKUGeneratorService::getAvailablePatterns();

        $this->assertIsArray($patterns);
        $this->assertNotEmpty($patterns);

        foreach ($patterns as $pattern) {
            $this->assertArrayHasKey('key', $pattern);
            $this->assertArrayHasKey('description', $pattern);
            $this->assertArrayHasKey('example', $pattern);
        }
    }

    public function test_get_available_patterns_contains_expected_keys(): void
    {
        $patterns = SKUGeneratorService::getAvailablePatterns();
        $keys = array_column($patterns, 'key');

        $expected = ['{category}', '{category_id}', '{name}', '{random}', '{number}', '{date}', '{year}', '{month}', '{timestamp}'];

        foreach ($expected as $key) {
            $this->assertContains($key, $keys);
        }
    }

    public function test_get_preset_patterns_returns_array(): void
    {
        $presets = SKUGeneratorService::getPresetPatterns();

        $this->assertIsArray($presets);
        $this->assertNotEmpty($presets);

        foreach ($presets as $preset) {
            $this->assertArrayHasKey('name', $preset);
            $this->assertArrayHasKey('pattern', $preset);
            $this->assertArrayHasKey('example', $preset);
        }
    }
}
