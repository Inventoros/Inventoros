<?php

namespace Database\Factories;

use App\Models\Auth\Organization;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'name' => fake()->name(),
            'code' => 'CUST-' . fake()->unique()->numberBetween(1000, 9999),
            'company_name' => fake()->company(),
            'contact_name' => fake()->name(),
            'email' => fake()->email(),
            'phone' => fake()->phoneNumber(),
            'billing_address' => fake()->streetAddress(),
            'billing_city' => fake()->city(),
            'billing_state' => fake()->state(),
            'billing_zip_code' => fake()->postcode(),
            'billing_country' => fake()->country(),
            'is_active' => true,
        ];
    }
}
