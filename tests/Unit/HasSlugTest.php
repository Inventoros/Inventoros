<?php

namespace Tests\Unit;

use App\Models\Auth\Organization;
use App\Models\Inventory\ProductCategory;
use App\Models\System\SystemSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HasSlugTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        SystemSetting::set('installed', true, 'boolean');

        $this->organization = Organization::create([
            'name' => 'Test Org',
            'email' => 'test@org.com',
        ]);
    }

    public function test_slug_is_auto_generated_on_create(): void
    {
        $category = ProductCategory::create([
            'organization_id' => $this->organization->id,
            'name' => 'Electronics',
        ]);

        $this->assertSame('electronics', $category->slug);
    }

    public function test_slug_handles_spaces_and_special_chars(): void
    {
        $category = ProductCategory::create([
            'organization_id' => $this->organization->id,
            'name' => 'Office Supplies & Tools',
        ]);

        $this->assertSame('office-supplies-tools', $category->slug);
    }

    public function test_slug_handles_unicode_characters(): void
    {
        $category = ProductCategory::create([
            'organization_id' => $this->organization->id,
            'name' => 'Café Equipment',
        ]);

        $this->assertNotEmpty($category->slug);
        $this->assertStringNotContainsString(' ', $category->slug);
    }

    public function test_slug_is_unique_within_organization(): void
    {
        ProductCategory::create([
            'organization_id' => $this->organization->id,
            'name' => 'Electronics',
        ]);

        $second = ProductCategory::create([
            'organization_id' => $this->organization->id,
            'name' => 'Electronics',
        ]);

        $this->assertSame('electronics-1', $second->slug);
    }

    public function test_slug_increments_suffix_for_multiple_duplicates(): void
    {
        ProductCategory::create([
            'organization_id' => $this->organization->id,
            'name' => 'Tools',
        ]);

        ProductCategory::create([
            'organization_id' => $this->organization->id,
            'name' => 'Tools',
        ]);

        $third = ProductCategory::create([
            'organization_id' => $this->organization->id,
            'name' => 'Tools',
        ]);

        $this->assertSame('tools-2', $third->slug);
    }

    public function test_same_slug_allowed_in_different_organizations(): void
    {
        $otherOrg = Organization::create([
            'name' => 'Other Org',
            'email' => 'other@org.com',
        ]);

        $cat1 = ProductCategory::create([
            'organization_id' => $this->organization->id,
            'name' => 'Electronics',
        ]);

        $cat2 = ProductCategory::create([
            'organization_id' => $otherOrg->id,
            'name' => 'Electronics',
        ]);

        // Both should have the base slug since they're in different orgs
        $this->assertSame('electronics', $cat1->slug);
        $this->assertSame('electronics', $cat2->slug);
    }

    public function test_existing_slug_is_preserved_on_create(): void
    {
        $category = ProductCategory::create([
            'organization_id' => $this->organization->id,
            'name' => 'Electronics',
            'slug' => 'custom-slug',
        ]);

        $this->assertSame('custom-slug', $category->slug);
    }

    public function test_slug_handles_empty_name(): void
    {
        $category = ProductCategory::create([
            'organization_id' => $this->organization->id,
            'name' => '',
        ]);

        $this->assertNotNull($category->slug);
    }

    public function test_organization_generates_slug(): void
    {
        $org = Organization::create([
            'name' => 'Acme Corporation',
            'email' => 'acme@corp.com',
        ]);

        $this->assertSame('acme-corporation', $org->slug);
    }
}
