<?php

namespace Database\Factories;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'sku' => 'SKU-' . fake()->unique()->numberBetween(10000, 99999),
            'name' => fake()->words(3, true),
            'description' => fake()->sentence(),
            'price' => fake()->randomFloat(2, 10, 1000),
            'purchase_price' => fake()->randomFloat(2, 5, 500),
            'currency' => 'USD',
            'stock' => fake()->numberBetween(0, 500),
            'min_stock' => fake()->numberBetween(5, 50),
            'max_stock' => fake()->numberBetween(100, 1000),
            'is_active' => true,
        ];
    }
}
