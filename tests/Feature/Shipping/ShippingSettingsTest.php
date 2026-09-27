<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\Models\Shipping\ShippingSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ShippingSettingsTest extends TestCase
{
    use RefreshDatabase;
    use ShippingTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpShipping();
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'easypost_enabled' => true,
            'easypost_test_mode' => true,
            'easypost_api_key' => '',
            'easypost_test_api_key' => 'EZTK_new_test_key',
            'easypost_webhook_secret' => 'whsec_new',
            'default_warehouse_id' => $this->warehouse->id,
            'from_address' => [
                'name' => 'Shipping Dept', 'company' => 'Ship Org', 'street1' => '1 Dock St', 'street2' => '',
                'city' => 'Portland', 'state' => 'OR', 'zip' => '97201', 'country' => 'US',
                'phone' => '5035550100', 'email' => 'ship@org.test',
            ],
            'default_parcel' => ['weight_oz' => 16, 'length_in' => 10, 'width_in' => 8, 'height_in' => 4],
            'notify_customers' => true,
        ], $overrides);
    }

    public function test_the_page_never_sends_secrets_to_the_browser(): void
    {
        $this->configureEasyPost();

        $response = $this->actingAs($this->admin)
            ->get(route('settings.shipping.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Settings/Shipping')
                ->where('settings.easypost_enabled', true)
                ->where('settings.easypost_test_api_key_set', true)
                ->where('settings.easypost_api_key_set', true)
                ->where('settings.easypost_webhook_secret_set', true)
                ->has('webhookUrl')
                ->has('warehouses', 1)
            );

        $this->assertStringNotContainsString('EZTK_test_key_123', $response->getContent());
        $this->assertStringNotContainsString('EZAK_live_key_456', $response->getContent());
        $this->assertStringNotContainsString('whsec_example_secret', $response->getContent());
    }

    public function test_settings_save_and_blank_secrets_keep_the_stored_ones(): void
    {
        $this->configureEasyPost();

        $this->actingAs($this->admin)
            ->patch(route('settings.shipping.update'), $this->payload())
            ->assertRedirect()
            ->assertSessionHas('success');

        $settings = ShippingSetting::forOrganization($this->organization->id);
        $this->assertSame('EZTK_new_test_key', $settings->easypost_test_api_key);
        $this->assertSame('EZAK_live_key_456', $settings->easypost_api_key);
        $this->assertSame('whsec_new', $settings->easypost_webhook_secret);
        $this->assertSame('Portland', $settings->from_address['city']);
        $this->assertEquals(16, $settings->default_parcel['weight_oz']);
        $this->assertTrue($settings->notify_customers);
    }

    public function test_enabling_easypost_needs_a_key_for_the_chosen_mode(): void
    {
        $this->actingAs($this->admin)
            ->patch(route('settings.shipping.update'), $this->payload([
                'easypost_test_mode' => false, 'easypost_api_key' => '',
            ]))
            ->assertSessionHasErrors('easypost_api_key');
    }

    public function test_a_warehouse_from_another_organization_is_rejected(): void
    {
        $other = \App\Models\Auth\Organization::create(['name' => 'O', 'email' => 'o@o.test', 'currency' => 'USD', 'timezone' => 'UTC']);
        $foreign = \App\Models\Warehouse::create(['organization_id' => $other->id, 'name' => 'X', 'code' => 'X', 'is_active' => true]);

        $this->actingAs($this->admin)
            ->patch(route('settings.shipping.update'), $this->payload(['default_warehouse_id' => $foreign->id]))
            ->assertSessionHasErrors('default_warehouse_id');
    }

    public function test_the_webhook_token_can_be_rotated(): void
    {
        $before = ShippingSetting::forOrganization($this->organization->id)->webhook_token;

        $this->actingAs($this->admin)
            ->post(route('settings.shipping.webhook-token'))
            ->assertRedirect();

        $this->assertNotSame($before, ShippingSetting::forOrganization($this->organization->id)->webhook_token);
    }

    public function test_shipping_settings_need_manage_organization(): void
    {
        $member = $this->memberWith(['view_settings', 'view_shipments', 'create_shipments']);

        $this->actingAs($member)->get(route('settings.shipping.index'))->assertForbidden();
        $this->actingAs($member)->patch(route('settings.shipping.update'), $this->payload())->assertForbidden();
    }
}
