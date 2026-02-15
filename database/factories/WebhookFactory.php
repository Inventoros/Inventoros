<?php

namespace Database\Factories;

use App\Models\Webhook;
use App\Models\Auth\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class WebhookFactory extends Factory
{
    protected $model = Webhook::class;

    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'name' => fake()->words(2, true) . ' Webhook',
            'url' => fake()->url(),
            'secret' => Str::random(32),
            'events' => ['order.created', 'product.updated'],
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
