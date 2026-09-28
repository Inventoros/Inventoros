<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Inventory\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Api\Concerns\BuildsApiFixtures;
use Tests\TestCase;

/**
 * Record-change descriptions name the model in words ("Created product
 * variant"), not as a class name ("Created ProductVariant").
 */
final class ActivityDescriptionWordsTest extends TestCase
{
    use BuildsApiFixtures, RefreshDatabase;

    public function test_descriptions_name_the_model_in_words(): void
    {
        $this->markInstalled();
        $org = $this->makeOrganization();
        $this->actingAs($this->makeAdmin($org));
        $product = $this->makeProduct($org, ['has_variants' => true]);

        $variant = ProductVariant::create([
            'product_id' => $product->id, 'organization_id' => $org->id, 'sku' => 'V-1',
            'option_values' => ['Size' => 'L'], 'stock' => 0, 'min_stock' => 0, 'is_active' => true, 'position' => 0,
        ]);

        $this->assertSame('Created product variant', ActivityLog::where('subject_type', ProductVariant::class)
            ->where('subject_id', $variant->id)->where('action', 'created')->value('description'));
    }
}
