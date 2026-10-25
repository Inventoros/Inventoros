<?php

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Order\Order;
use App\Models\System\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ChannelOrderNumberTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_and_generic_channel_numbers_are_visible_and_searchable(): void
    {
        SystemSetting::set('installed', true, 'boolean');
        $organization = Organization::factory()->create();
        $admin = User::factory()->create(['organization_id' => $organization->id]);
        $admin->forceFill(['role' => 'admin'])->save();
        foreach ([['shopify' => ['name' => '#SHOP-1001']], ['channel_order_number' => 'MARKET-2002']] as $index => $metadata) {
            $order = Order::create(['organization_id' => $organization->id, 'created_by' => $admin->id,
                'order_number' => 'INTERNAL-'.$index, 'customer_name' => 'Channel customer', 'status' => 'pending', 'currency' => 'USD',
                'order_date' => now()->toDateString(), 'subtotal' => 0, 'total' => 0, 'metadata' => $metadata]);
            $label = $order->channel_order_number;
            $this->assertNotEmpty($label);
            $this->actingAs($admin)->get(route('orders.index', ['search' => $label]))->assertOk()->assertInertia(fn ($page) => $page
                ->has('orders.data', 1)->where('orders.data.0.channel_order_number', $label));
            $this->get(route('orders.show', $order))->assertOk()->assertInertia(fn ($page) => $page->where('order.channel_order_number', $label));
            Sanctum::actingAs($admin, ['*']);
            $this->getJson('/api/v1/orders?'.http_build_query(['search' => $label]))->assertOk()
                ->assertJsonCount(1, 'data')->assertJsonPath('data.0.channel_order_number', $label);
            $this->getJson('/search?'.http_build_query(['q' => $label]))->assertOk()->assertSee($order->order_number);
        }
        $other = User::factory()->create(['organization_id' => Organization::factory()->create()->id]);
        $other->forceFill(['role' => 'admin'])->save();
        Sanctum::actingAs($other, ['*']);
        $this->getJson('/api/v1/orders?search=MARKET-2002')->assertOk()->assertJsonCount(0, 'data');
    }
}
