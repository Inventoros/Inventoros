<?php

namespace Database\Factories;

use App\Models\Auth\Organization;
use App\Models\Inventory\ProductLocation;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProductLocationFactory extends Factory
{
    protected $model = ProductLocation::class;

    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'name' => 'Warehouse ' . fake()->randomLetter(),
            'code' => 'WH-' . fake()->unique()->randomLetter(),
            'description' => fake()->sentence(),
            'is_active' => true,
        ];
    }
}
