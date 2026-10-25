<?php

declare(strict_types=1);

namespace Tests\Feature\Plugins;

use App\Models\Auth\Organization;
use App\Models\Customer;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductVariant;
use App\Models\Order\Order;
use App\Models\Plugin;
use App\Models\Role;
use App\Models\System\SystemSetting;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Documents\DocumentPdfService;
use App\Services\PluginService;
use App\Services\SettingsService;
use App\Support\PluginPageRoutes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Inventoros\Plugins\TaxBilling\Invoices;
use Inventoros\Plugins\TaxBilling\Profile;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class TaxBillingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        SystemSetting::set('installed', true, 'boolean');
        $this->admin = User::factory()->create(['organization_id' => Organization::factory()->create()->id, 'role' => 'admin']);
        $this->actingAs($this->admin);
        app(PluginService::class)->activatePlugin('tax-billing');
        Route::middleware('web')->group(fn () => PluginPageRoutes::register());
        Route::getRoutes()->refreshNameLookups();
        $this->profile();
    }

    protected function tearDown(): void
    {
        app(PluginService::class)->deactivatePlugin('tax-billing');
        parent::tearDown();
    }

    private function profile(array $overrides = []): array
    {
        $profile = array_replace(Profile::DEFAULTS, [
            'country' => 'CA', 'issuer_name' => 'Example Supplier', 'issuer_address' => "100 Example Street\nToronto, CA",
            'issuer_tax_id' => 'CA-123', 'components' => [['label' => 'HST', 'rate' => '13']],
        ], $overrides);
        SettingsService::set(Profile::KEY, json_encode($profile, JSON_THROW_ON_ERROR));

        return $profile;
    }

    private function india(string $local = 'SGST'): void
    {
        $this->profile(['mode' => 'india_gst', 'country' => 'IN', 'region_code' => '27',
            'issuer_tax_id' => '27ABCDE1234F1Z5', 'gst_rate' => '18', 'local_tax_label' => $local]);
    }

    private function order(string $tax = '13.00', string $subtotal = '100.00', array $overrides = []): Order
    {
        $order = Order::create(array_replace([
            'organization_id' => $this->admin->organization_id, 'order_number' => 'ORDER-'.(Order::withTrashed()->count() + 1),
            'customer_name' => 'Example Customer', 'customer_address' => '1 Customer Street',
            'status' => 'pending', 'order_date' => now()->toDateString(), 'currency' => 'CAD',
            'subtotal' => $subtotal, 'tax' => $tax, 'discount_amount' => '0', 'shipping' => '0',
            'total' => bcadd($subtotal, $tax, 2),
        ], $overrides));
        $order->items()->create(['product_name' => 'Example Shirt', 'sku' => 'SHIRT', 'quantity' => 1,
            'unit_price' => $subtotal, 'subtotal' => $subtotal, 'discount_amount' => '0', 'tax' => '0', 'total' => $subtotal]);

        return $order;
    }

    private function payload(Order $order, array $overrides = []): array
    {
        return array_replace([
            'confirmed' => true, 'region_code' => '27', 'supply_country' => Profile::get((int) $order->organization_id)['country'],
            'items' => $order->items->mapWithKeys(fn ($item) => [$item->id => ['hsn_code' => '6109', 'unit' => 'NOS']])->all(),
        ], $overrides);
    }

    private function issue(Order $order, array $overrides = []): array
    {
        $this->postJson(route('plg.tax-billing.issue', ['orderId' => $order->id]), $this->payload($order, $overrides))
            ->assertRedirect()->assertSessionHasNoErrors();

        return $order->fresh()->metadata[Invoices::SNAPSHOT];
    }

    public function test_profiles_support_country_tax_labels_and_are_organization_scoped(): void
    {
        $profile = $this->profile(['country' => 'GB', 'tax_id_label' => 'VAT number', 'components' => [['label' => 'VAT', 'rate' => '20']]]);
        $this->postJson(route('plg.tax-billing.save'), $profile)->assertRedirect()->assertSessionHasNoErrors();
        $this->get(route('plg.tax-billing.settings'))->assertOk()->assertInertia(fn ($page) => $page
            ->component('Plugin::tax-billing/Settings')->where('profile.country', 'GB')->where('profile.components.0.label', 'VAT'));
        $other = Organization::factory()->create();
        $this->assertSame('', Profile::get($other->id)['country']);
        $snapshot = $this->issue($this->order('20.00'));
        $this->assertSame('VAT', $snapshot['components'][0]['label']);
        $this->assertSame('20.00', $snapshot['components'][0]['amount']);
        $this->assertSame('GB', $snapshot['profile']['country']);
    }

    public function test_india_uses_cgst_sgst_utgst_and_igst_and_reuses_the_core_number(): void
    {
        foreach (['SGST', 'UTGST'] as $index => $local) {
            $this->india($local);
            $number = 'INV-0000'.(17 + $index);
            $snapshot = $this->issue($this->order('18.00', overrides: ['invoice_number' => $number]));
            $this->assertSame(['CGST', $local], array_column($snapshot['components'], 'label'));
            $this->assertSame(['9.00', '9.00'], array_column($snapshot['components'], 'amount'));
            $this->assertSame($number, $snapshot['number']);
        }
        $snapshot = $this->issue($this->order('18.00'), ['region_code' => '29']);
        $this->assertSame(['IGST'], array_column($snapshot['components'], 'label'));
        $this->assertSame('18.00', $snapshot['components'][0]['amount']);
    }

    public function test_rounding_allocates_cents_without_negative_components(): void
    {
        $this->profile(['components' => array_map(fn ($label) => ['label' => $label, 'rate' => '1'], ['A', 'B', 'C', 'D'])]);
        $snapshot = $this->issue($this->order('0.02', '0.50'));
        $this->assertSame(['0.01', '0.01', '0.00', '0.00'], array_column($snapshot['components'], 'amount'));
        $this->profile(['components' => [['label' => 'Exempt', 'rate' => '0']]]);
        $this->assertSame('0.00', $this->issue($this->order('0'))['components'][0]['amount']);
    }

    public function test_size_matrix_preserves_archived_variants_and_allocates_discounts(): void
    {
        $product = Product::create(['organization_id' => $this->admin->organization_id, 'sku' => 'BASE', 'name' => 'Shirt', 'price' => 50, 'stock' => 10, 'is_active' => true]);
        $order = $this->order('11.70', overrides: ['discount_amount' => '10.00', 'total' => '101.70']);
        $order->items()->delete();
        foreach (['S', 'M'] as $size) {
            $variant = ProductVariant::create(['organization_id' => $this->admin->organization_id, 'product_id' => $product->id,
                'sku' => 'SHIRT-'.$size, 'option_values' => ['Size' => $size], 'price' => 50, 'stock' => 5, 'is_active' => true]);
            $order->items()->create(['product_id' => $product->id, 'product_variant_id' => $variant->id, 'product_name' => 'Shirt',
                'sku' => $variant->sku, 'quantity' => 1, 'unit_price' => '50.00', 'subtotal' => '50.00', 'discount_amount' => '0', 'tax' => '0', 'total' => '50.00']);
            $variant->delete();
        }
        $order->unsetRelation('items');
        $snapshot = $this->issue($order);
        $this->assertCount(1, $snapshot['rows']);
        $this->assertEquals(['S' => 1, 'M' => 1], $snapshot['rows'][0]['sizes']);
        $this->assertSame('90.00', $snapshot['rows'][0]['amount']);
        $this->assertSame('90.00', $snapshot['taxable_value']);
        $this->assertSame(10, $product->fresh()->stock);
    }

    public function test_shipping_taxable_base_and_component_sums_reconcile(): void
    {
        $this->profile(['shipping_taxable' => true]);
        $snapshot = $this->issue($this->order('14.30', overrides: ['shipping' => '10', 'total' => '124.30']));
        $this->assertSame('110.00', $snapshot['taxable_value']);
        $this->assertSame('14.30', $snapshot['components'][0]['amount']);
    }

    public function test_invalid_profiles_are_rejected_and_invoice_review_is_read_only(): void
    {
        $this->india();
        $profile = Profile::get((int) $this->admin->organization_id);
        $this->postJson(route('plg.tax-billing.save'), array_replace($profile, ['region_code' => '29']))
            ->assertUnprocessable()->assertJsonValidationErrors('issuer_tax_id');
        $this->postJson(route('plg.tax-billing.save'), array_replace($profile, ['components' => [['label' => 'Invalid', 'rate' => '-1']]]))
            ->assertUnprocessable()->assertJsonValidationErrors('components.0.rate');
        $order = $this->order('18.00');
        $this->get(route('plg.tax-billing.invoice', ['orderId' => $order->id]))->assertOk();
        $this->assertNull($order->fresh()->invoice_number);
        $this->assertNull($order->fresh()->metadata);
        $order->forceFill(['approval_status' => 'pending'])->save();
        $this->postJson(route('plg.tax-billing.issue', ['orderId' => $order->id]), $this->payload($order))
            ->assertUnprocessable()->assertJsonValidationErrors('invoice');
    }

    public function test_different_colours_and_hsn_codes_do_not_merge_into_one_size_group(): void
    {
        $product = Product::create(['organization_id' => $this->admin->organization_id, 'sku' => 'COLOUR', 'name' => 'Shirt', 'price' => 50, 'stock' => 10, 'is_active' => true]);
        $order = $this->order('19.50', '150.00');
        $order->items()->delete();
        foreach (['Red', 'Blue', 'Red'] as $index => $colour) {
            $variant = ProductVariant::create(['organization_id' => $this->admin->organization_id, 'product_id' => $product->id,
                'sku' => 'COLOUR-'.$index, 'option_values' => ['Size' => 'M', 'Colour' => $colour], 'price' => 50, 'stock' => 5, 'is_active' => true]);
            $order->items()->create(['product_id' => $product->id, 'product_variant_id' => $variant->id, 'product_name' => 'Shirt',
                'sku' => $variant->sku, 'quantity' => 1, 'unit_price' => '50.00', 'subtotal' => '50.00', 'discount_amount' => '0', 'tax' => '0', 'total' => '50.00']);
        }
        $order->unsetRelation('items');
        $payload = $this->payload($order);
        $payload['items'][$order->items->last()->id]['hsn_code'] = '6201';
        $snapshot = $this->issue($order, $payload);
        $this->assertCount(3, $snapshot['rows']);
        $this->assertSame(['Red', 'Blue', 'Red'], array_map(fn ($row) => $row['options']['Colour'], $snapshot['rows']));
    }

    public function test_invalid_tax_or_india_details_do_not_issue_or_allocate_a_number(): void
    {
        $order = $this->order('15.00');
        $uri = route('plg.tax-billing.issue', ['orderId' => $order->id]);
        $this->postJson($uri, $this->payload($order))->assertUnprocessable()->assertJsonValidationErrors('invoice');
        $this->assertNull($order->fresh()->invoice_number);
        $this->assertNull($order->fresh()->metadata);
        $this->india();
        $order->forceFill(['tax' => '18.00', 'total' => '118.00'])->save();
        foreach ([['supply_country' => 'US'], ['region_code' => 'bad'], ['items' => [$order->items->first()->id => ['hsn_code' => '12', 'unit' => 'NOS']]], ['confirmed' => false]] as $changes) {
            $this->postJson($uri, $this->payload($order, $changes))->assertUnprocessable();
        }
        $this->assertNull($order->fresh()->invoice_number);
        $this->assertNull($order->fresh()->metadata);
    }

    public function test_customer_gstin_is_validated_normalized_and_other_metadata_is_preserved(): void
    {
        $this->india();
        $customer = Customer::factory()->create(['organization_id' => $this->admin->organization_id, 'metadata' => ['other' => ['keep' => true]]]);
        $this->patchJson(route('customers.update', $customer), ['tax_id' => 'bad'])->assertUnprocessable()->assertJsonValidationErrors('tax_id');
        $this->patchJson(route('customers.update', $customer), ['tax_id' => '27abcde1234f1z5', 'plugin_fields' => ['tax_billing' => ['region_code' => '27']]])->assertOk();
        $this->assertSame('27ABCDE1234F1Z5', $customer->fresh()->tax_id);
        $this->assertSame('27', $customer->fresh()->metadata['tax_billing']['region_code']);
        $this->assertTrue($customer->fresh()->metadata['other']['keep']);
        $customer->delete();
        $snapshot = $this->issue($this->order('18.00', overrides: ['customer_id' => $customer->id]));
        $this->assertSame('27ABCDE1234F1Z5', $snapshot['customer_tax_id']);
    }

    public function test_issued_documents_are_idempotent_and_retained_across_profile_and_order_edits(): void
    {
        $order = $this->order();
        $original = $this->issue($order);
        $this->profile(['issuer_name' => 'Changed Supplier']);
        $order->forceFill(['customer_name' => 'Changed Customer', 'tax' => '100', 'total' => '200'])->save();
        $this->assertSame($original, $this->issue($order));
        $html = view('tax-billing::invoice', ['invoice' => $original])->render();
        $this->assertStringContainsString('Example Supplier', $html);
        $this->assertStringNotContainsString('Changed Supplier', $html);
        $this->assertStringNotContainsString('Changed Customer', $html);
        $this->assertStringContainsString('HST 13%', $html);
        $this->get(route('plg.tax-billing.download', ['orderId' => $order->id]))->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', app(DocumentPdfService::class)->orderInvoice($order->fresh(), true)->output());
        Plugin::where('slug', 'tax-billing')->update(['is_active' => false]);
        $this->get(route('plg.tax-billing.download', ['orderId' => $order->id]))->assertNotFound();
        try {
            app(DocumentPdfService::class)->orderInvoice($order->fresh());
            $this->fail('Inactive renderer must not fall back to a mutable invoice.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
    }

    public function test_read_manage_issue_and_tenant_permissions_are_enforced(): void
    {
        $order = $this->order();
        $profile = Profile::get((int) $this->admin->organization_id);
        $reader = User::factory()->create(['organization_id' => $this->admin->organization_id, 'role' => 'member']);
        $role = Role::create(['organization_id' => $reader->organization_id, 'name' => 'Reader', 'slug' => 'tax-reader', 'permissions' => ['view_orders']]);
        $reader->roles()->attach($role, ['organization_id' => $reader->organization_id]);
        $this->actingAs($reader);
        $this->get(route('plg.tax-billing.invoice', ['orderId' => $order->id]))->assertOk()->assertInertia(fn ($page) => $page->where('canIssue', false));
        $this->postJson(route('plg.tax-billing.issue', ['orderId' => $order->id]), $this->payload($order))->assertForbidden();
        $this->postJson(route('plg.tax-billing.save'), $profile)->assertForbidden();
        $this->get(route('plg.tax-billing.settings'))->assertForbidden();
        $warehouse = Warehouse::factory()->create(['organization_id' => $reader->organization_id]);
        $warehouse->users()->attach($reader->id);
        $this->get(route('plg.tax-billing.invoice', ['orderId' => $order->id]))->assertForbidden();
        $this->get(route('plg.tax-billing.download', ['orderId' => $order->id]))->assertForbidden();
        $this->actingAs($this->admin);
        $other = $this->order(overrides: ['organization_id' => Organization::factory()->create()->id]);
        foreach (['invoice', 'download'] as $route) {
            $this->get(route('plg.tax-billing.'.$route, ['orderId' => $other->id]))->assertNotFound();
        }
        $this->postJson(route('plg.tax-billing.issue', ['orderId' => $other->id]), $this->payload($order))->assertNotFound();
        $this->assertNull($other->fresh()->metadata);
    }
}
