<?php

namespace Database\Factories;

use App\Models\Auth\Organization;
use App\Models\Order\Order;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

class OrderFactory extends Factory
{
    protected $model = Order::class;

    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'order_number' => 'ORD-' . fake()->unique()->numberBetween(10000, 99999),
            'customer_id' => Customer::factory(),
            'status' => 'pending',
            'total_amount' => fake()->randomFloat(2, 50, 2000),
            'currency' => 'USD',
        ];
    }
}
