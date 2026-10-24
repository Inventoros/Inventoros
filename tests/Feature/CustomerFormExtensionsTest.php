<?php

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Customer;
use App\Models\System\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CustomerFormExtensionsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        SystemSetting::set('installed', true, 'boolean');
        $this->admin = User::factory()->create(['organization_id' => Organization::factory()->create()->id]);
        $this->admin->forceFill(['role' => 'admin'])->save();
        $this->actingAs($this->admin);
    }

    private function registerFields(): void
    {
        foreach (['customer_store_validation_rules', 'customer_update_validation_rules'] as $hook) {
            add_filter($hook, fn ($rules) => $rules + ['plugin_fields.example.reference' => ['nullable', 'string', 'max:20']]);
        }
        add_filter('customer_store_data', function ($data) {
            $data['metadata'] = ['example' => $data['plugin_fields']['example'] ?? []];

            return $data;
        });
        add_filter('customer_update_data', function ($data, $customer) {
            if (isset($data['plugin_fields']['example'])) {
                $data['metadata'] = array_replace($customer->metadata ?? [], ['example' => $data['plugin_fields']['example']]);
            }

            return $data;
        });
        add_filter('customer_form_data', fn ($values, $customer) => $values + ['example' => $customer?->metadata['example'] ?? ['reference' => 'default']]);
    }

    public function test_create_and_edit_pages_supply_extension_values(): void
    {
        $this->registerFields();
        foreach (['customers.create', 'customers.edit'] as $page) {
            add_page_component($page, 'business-details', ['plugin' => 'example', 'component' => 'CustomerFields']);
        }
        $this->get(route('customers.create'))->assertOk()->assertInertia(fn ($page) => $page
            ->where('pluginFields.example.reference', 'default')->has('pluginComponents.businessDetails'));
        $customer = Customer::factory()->create(['organization_id' => $this->admin->organization_id, 'metadata' => ['example' => ['reference' => 'saved']]]);
        $this->get(route('customers.edit', $customer))->assertOk()->assertInertia(fn ($page) => $page
            ->where('pluginFields.example.reference', 'saved')->has('pluginComponents.businessDetails'));
    }

    public function test_web_and_rest_use_the_same_validation_and_persistence_hooks(): void
    {
        $this->registerFields();
        foreach (['/customers', '/api/v1/customers'] as $uri) {
            Sanctum::actingAs($this->admin, ['*']);
            $response = $this->postJson($uri, ['name' => 'Extended', 'plugin_fields' => ['example' => ['reference' => 'REF-123', 'unvalidated' => 'drop']]]);
            $response->assertCreated();
            $customer = Customer::latest('id')->firstOrFail();
            $this->assertSame(['reference' => 'REF-123'], $customer->metadata['example']);
            $this->assertSame($this->admin->organization_id, $customer->organization_id);
            $this->postJson($uri, ['name' => 'Invalid', 'plugin_fields' => ['example' => ['reference' => str_repeat('a', 21)]]])
                ->assertUnprocessable()->assertJsonValidationErrors('plugin_fields.example.reference');
        }
    }

    public function test_updates_preserve_other_plugin_data_and_enforce_tenant_ownership(): void
    {
        $this->registerFields();
        $customer = Customer::factory()->create(['organization_id' => $this->admin->organization_id, 'metadata' => ['other' => ['keep' => true]]]);
        $this->patchJson(route('customers.update', $customer), ['plugin_fields' => ['example' => ['reference' => 'NEW']]])->assertOk();
        $this->assertTrue($customer->fresh()->metadata['other']['keep']);
        $this->assertSame('NEW', $customer->fresh()->metadata['example']['reference']);
        $other = Customer::factory()->create(['organization_id' => Organization::factory()->create()->id]);
        $this->patchJson(route('customers.update', $other), ['plugin_fields' => ['example' => ['reference' => 'NO']]])->assertNotFound();
        $this->assertNull($other->fresh()->metadata);
    }

    public function test_unknown_extension_data_is_not_persisted(): void
    {
        $this->postJson(route('customers.store'), ['name' => 'Plain', 'plugin_fields' => ['unregistered' => ['admin' => true]]])->assertCreated();
        $this->assertNull(Customer::firstOrFail()->metadata);
    }
}
