<?php

namespace Database\Factories;

use App\Models\Auth\Organization;
use App\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;

class RoleFactory extends Factory
{
    protected $model = Role::class;

    public function definition(): array
    {
        return [
            'name' => fake()->jobTitle(),
            'slug' => fake()->unique()->slug(),
            'description' => fake()->sentence(),
            'organization_id' => Organization::factory(),
            'is_system' => false,
            'permissions' => ['view_products'],
        ];
    }

    public function system(): static
    {
        return $this->state(fn (array $attributes) => [
            'organization_id' => null,
            'is_system' => true,
        ]);
    }
}
