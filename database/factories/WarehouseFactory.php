<?php

namespace Database\Factories;

use App\Models\Auth\Organization;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

class WarehouseFactory extends Factory
{
    protected $model = Warehouse::class;

    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'name' => fake()->company() . ' Warehouse',
            'code' => strtoupper(fake()->unique()->lexify('WH-???')),
            'description' => fake()->sentence(),
            'address_line_1' => fake()->streetAddress(),
            'city' => fake()->city(),
            'province' => fake()->randomElement(['ON', 'BC', 'AB', 'QC', 'MB', 'SK', 'NS', 'NB']),
            'postal_code' => fake()->postcode(),
            'country' => 'CA',
            'phone' => fake()->phoneNumber(),
            'email' => fake()->companyEmail(),
            'manager_name' => fake()->name(),
            'is_default' => false,
            'is_active' => true,
            'priority' => 0,
        ];
    }

    public function default(): static
    {
        return $this->state(fn () => ['is_default' => true]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
