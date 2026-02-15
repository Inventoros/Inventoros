<?php

namespace Database\Factories;

use App\Models\Auth\Organization;
use App\Models\Purchasing\PurchaseOrder;
use App\Models\Inventory\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;

class PurchaseOrderFactory extends Factory
{
    protected $model = PurchaseOrder::class;

    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'supplier_id' => Supplier::factory(),
            'order_number' => 'PO-' . fake()->unique()->numberBetween(10000, 99999),
            'status' => 'pending',
            'total_amount' => fake()->randomFloat(2, 100, 5000),
            'currency' => 'USD',
        ];
    }
}
