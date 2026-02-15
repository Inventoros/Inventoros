<?php

namespace Database\Factories;

use App\Models\Auth\Organization;
use App\Models\Inventory\ProductCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProductCategoryFactory extends Factory
{
    protected $model = ProductCategory::class;

    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'name' => fake()->words(2, true),
            'slug' => fake()->unique()->slug(),
            'description' => fake()->sentence(),
            'is_active' => true,
        ];
    }
}
