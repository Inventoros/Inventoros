<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Imports\OrdersImport;
use App\Jobs\ProcessOrderImportJob;
use App\Models\Auth\Organization;
use App\Models\Customer;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductVariant;
use App\Models\Inventory\StockAdjustment;
use App\Models\Notification;
use App\Models\Order\Order;
use App\Models\Role;
use App\Models\System\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

/**
 * Order import: one CSV row per order line, grouped into orders by
 * external_reference, created through OrderService.
 */
final class OrdersImportTest extends TestCase
{
    use RefreshDatabase;

    private const HEADER = 'external_reference,order_date,status,customer_name,customer_email,product_sku,variant_sku,quantity,unit_price,order_tax,order_shipping,notes';

    private Organization $org;

    private User $admin;

    private Product $widget;

    private Product $shirt;

    private ProductVariant $large;

    protected function setUp(): void
    {
        parent::setUp();

        SystemSetting::set('installed', true, 'boolean');
        $this->org = Organization::create(['name' => 'Org', 'email' => 'o@org.com', 'currency' => 'USD', 'timezone' => 'UTC']);
        $this->admin = User::create([
            'name' => 'Admin', 'email' => 'admin@org.com', 'password' => bcrypt('x'),
            'organization_id' => $this->org->id, 'role' => 'admin',
        ]);

        $this->widget = Product::create([
            'organization_id' => $this->org->id, 'sku' => 'WID-1', 'name' => 'Widget',
            'price' => 10, 'currency' => 'USD', 'stock' => 20, 'min_stock' => 0,
        ]);
        $this->shirt = Product::create([
            'organization_id' => $this->org->id, 'sku' => 'SHIRT', 'name' => 'Shirt',
            'price' => 25, 'currency' => 'USD', 'stock' => 0, 'min_stock' => 0, 'has_variants' => true,
        ]);
        $this->large = ProductVariant::create([
            'organization_id' => $this->org->id, 'product_id' => $this->shirt->id, 'sku' => 'SHIRT-L',
            'title' => 'Large', 'option_values' => ['Size' => 'L'], 'price' => 25, 'stock' => 8,
        ]);
    }

    /**
     * @param  array<int, string>  $lines
     */
    private function csv(array $lines): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('orders.csv', self::HEADER."\n".implode("\n", $lines)."\n");
    }

    /**
     * @param  array<int, string>  $lines
     */
    private function import(array $lines, bool $historical = false, ?User $as = null): OrdersImport
    {
        $import = new OrdersImport($as ?? $this->admin, $historical);
        Excel::import($import, $this->csv($lines));

        return $import;
    }

    public function test_it_creates_orders_grouped_by_reference_and_applies_stock(): void
    {
        $import = $this->import([
            'SHOP-1,2026-01-05,processing,Acme Ltd,ap@acme.test,WID-1,,3,9.50,2.00,5.00,First order',
            'SHOP-1,,,,,,SHIRT-L,2,24.00,,,',
            'SHOP-2,2026-01-06,pending,Bob,,WID-1,,1,,,,',
        ]);

        $stats = $import->getStats();
        $this->assertSame([], $stats['errors']);
        $this->assertSame(2, $stats['imported']);

        $order = Order::where('external_reference', 'SHOP-1')->sole();
        $this->assertSame('processing', $order->status->value);
        $this->assertSame('2026-01-05', $order->order_date->toDateString());
        $this->assertSame('import', $order->source);
        $this->assertSame('First order', $order->notes);
        $this->assertCount(2, $order->items);
        $this->assertEquals(76.5, (float) $order->subtotal);
        $this->assertEquals(83.5, (float) $order->total);

        $variantLine = $order->items->firstWhere('product_variant_id', $this->large->id);
        $this->assertSame($this->shirt->id, $variantLine->product_id);
        $this->assertSame('SHIRT-L', $variantLine->sku);

        // Blank unit_price falls back to the product price.
        $this->assertEquals(10, (float) Order::where('external_reference', 'SHOP-2')->sole()->items->sole()->unit_price);

        // Stock applied: 3 + 1 widgets, 2 large shirts, with ledger rows.
        $this->assertSame(16, $this->widget->fresh()->stock);
        $this->assertSame(6, $this->large->fresh()->stock);
        $this->assertSame(3, StockAdjustment::where('reference_type', Order::class)->count());
    }

    public function test_historical_mode_records_orders_without_moving_stock(): void
    {
        $import = $this->import([
            'OLD-1,2024-06-01,delivered,Acme Ltd,ap@acme.test,WID-1,,50,10,,,',
            'OLD-1,,,,,,SHIRT-L,2,25,,,',
        ], historical: true);

        $this->assertSame([], $import->getStats()['errors']);
        $order = Order::where('external_reference', 'OLD-1')->sole();
        $this->assertSame('delivered', $order->status->value);
        $this->assertCount(2, $order->items);

        // 50 widgets exceeds stock, but nothing is checked or moved.
        $this->assertSame(20, $this->widget->fresh()->stock);
        $this->assertSame(8, $this->large->fresh()->stock);
        $this->assertSame(0, StockAdjustment::count());
    }

    public function test_historical_orders_import_even_when_the_organization_requires_order_approval(): void
    {
        // Historical rows are past sales: they are not held for approval, so
        // a delivered order imports as delivered.
        $this->org->forceFill(['settings' => ['approvals' => ['orders_enabled' => true]]])->save();

        $import = $this->import(['OLD-2,2024-06-01,delivered,Acme Ltd,,WID-1,,1,10,,,'], historical: true);

        $this->assertSame([], $import->getStats()['errors']);
        $order = Order::where('external_reference', 'OLD-2')->sole();
        $this->assertSame('delivered', $order->status->value);
        $this->assertSame('not_required', $order->approval_status->value);
    }

    public function test_a_cancelled_order_never_moves_stock(): void
    {
        $this->import(['C-1,2026-01-05,cancelled,Acme,,WID-1,,4,10,,,']);

        $this->assertSame('cancelled', Order::where('external_reference', 'C-1')->sole()->status->value);
        $this->assertSame(20, $this->widget->fresh()->stock);
    }

    public function test_it_matches_customers_by_email_and_creates_missing_ones(): void
    {
        $existing = Customer::create(['organization_id' => $this->org->id, 'name' => 'Acme Ltd', 'email' => 'ap@acme.test']);

        $this->import([
            'M-1,2026-01-05,pending,,AP@ACME.TEST,WID-1,,1,10,,,',
            'M-2,2026-01-05,pending,New Co,new@co.test,WID-1,,1,10,,,',
            'M-3,2026-01-05,pending,New Co,new@co.test,WID-1,,1,10,,,',
        ]);

        $this->assertSame($existing->id, Order::where('external_reference', 'M-1')->sole()->customer_id);
        $this->assertSame('Acme Ltd', Order::where('external_reference', 'M-1')->sole()->customer_name);

        $created = Customer::where('email', 'new@co.test')->sole();
        $this->assertSame($this->org->id, $created->organization_id);
        $this->assertSame('New Co', $created->name);
        $this->assertSame($created->id, Order::where('external_reference', 'M-3')->sole()->customer_id);
    }

    public function test_a_bad_sku_rejects_the_whole_order_and_reports_the_row(): void
    {
        $import = $this->import([
            'BAD-1,2026-01-05,pending,Acme,,WID-1,,1,10,,,',
            'BAD-1,,,,,NOPE-9,,1,10,,,',
            'GOOD-1,2026-01-05,pending,Acme,,WID-1,,1,10,,,',
        ]);

        $stats = $import->getStats();
        $this->assertSame(1, $stats['imported']);
        $this->assertSame(1, $stats['failed']);
        $this->assertCount(1, $stats['errors']);
        $this->assertSame(3, $stats['errors'][0]['row']);
        $this->assertStringContainsString('NOPE-9', implode(' ', $stats['errors'][0]['errors']));

        // All-or-nothing per order: the valid first line of BAD-1 is not kept.
        $this->assertFalse(Order::where('external_reference', 'BAD-1')->exists());
        $this->assertTrue(Order::where('external_reference', 'GOOD-1')->exists());
        $this->assertSame(19, $this->widget->fresh()->stock);
    }

    public function test_row_validation_errors_are_reported_per_row(): void
    {
        $import = $this->import([
            ',2026-01-05,pending,Acme,,WID-1,,1,10,,,',
            'V-1,not-a-date,pending,Acme,,WID-1,,1,10,,,',
            'V-2,2026-01-05,lost,Acme,,WID-1,,0,-3,,,',
            'V-3,2026-01-05,pending,Acme,,SHIRT,,1,10,,,',
        ]);

        $stats = $import->getStats();
        $this->assertSame(0, $stats['imported']);
        $this->assertSame([2, 3, 4, 5], array_column($stats['errors'], 'row'));
        $this->assertStringContainsString('variant', strtolower(implode(' ', $stats['errors'][3]['errors'])));
        $this->assertSame(0, Order::count());
    }

    public function test_insufficient_stock_fails_only_that_order(): void
    {
        $import = $this->import([
            'S-1,2026-01-05,pending,Acme,,WID-1,,500,10,,,',
            'S-2,2026-01-05,pending,Acme,,WID-1,,2,10,,,',
        ]);

        $stats = $import->getStats();
        $this->assertSame(1, $stats['imported']);
        $this->assertSame(1, $stats['failed']);
        $this->assertStringContainsString('Insufficient stock', implode(' ', $stats['errors'][0]['errors']));
        $this->assertSame(18, $this->widget->fresh()->stock);
    }

    public function test_a_duplicate_reference_is_skipped_with_a_warning(): void
    {
        $lines = ['DUP-1,2026-01-05,pending,Acme,,WID-1,,1,10,,,'];
        $this->import($lines);

        $second = $this->import($lines);

        $stats = $second->getStats();
        $this->assertSame(0, $stats['imported']);
        $this->assertSame(1, $stats['skipped']);
        $this->assertStringContainsString('DUP-1', $stats['warnings'][0]['warnings'][0]);
        $this->assertSame(1, Order::where('external_reference', 'DUP-1')->count());
        $this->assertSame(19, $this->widget->fresh()->stock);
    }

    public function test_it_never_touches_another_organizations_products_or_orders(): void
    {
        $other = Organization::create(['name' => 'Other', 'email' => 'x@org.com', 'currency' => 'USD', 'timezone' => 'UTC']);
        Product::create([
            'organization_id' => $other->id, 'sku' => 'FOREIGN-1', 'name' => 'Foreign',
            'price' => 10, 'currency' => 'USD', 'stock' => 5, 'min_stock' => 0,
        ]);
        Order::create([
            'organization_id' => $other->id, 'order_number' => 'ORD-0001', 'external_reference' => 'X-1',
            'status' => 'pending', 'subtotal' => 0, 'tax' => 0, 'shipping' => 0, 'total' => 0,
        ]);

        $import = $this->import([
            'X-1,2026-01-05,pending,Acme,,WID-1,,1,10,,,',
            'X-2,2026-01-05,pending,Acme,,FOREIGN-1,,1,10,,,',
        ]);

        $stats = $import->getStats();
        // Another org's X-1 is not a duplicate here; its product is unknown here.
        $this->assertSame(1, $stats['imported']);
        $this->assertSame(0, $stats['skipped']);
        $this->assertStringContainsString('FOREIGN-1', implode(' ', $stats['errors'][0]['errors']));
        $this->assertSame($this->org->id, Order::withoutGlobalScopes()->where('external_reference', 'X-1')->where('organization_id', $this->org->id)->sole()->organization_id);
    }

    public function test_the_import_does_not_send_a_new_order_notification_per_order(): void
    {
        $this->import(['N-1,2026-01-05,pending,Acme,,WID-1,,1,10,,,']);

        $this->assertSame(0, Notification::where('type', 'order_created')->count());
    }

    private function orderCreatedWebhook(): \App\Models\Webhook
    {
        return \App\Models\Webhook::create([
            'organization_id' => $this->org->id,
            'name' => 'Orders',
            'url' => 'https://example.com/hook',
            'secret' => 'shh',
            'events' => ['order.created'],
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);
    }

    private function orderCreatedDeliveries(): int
    {
        return \App\Models\WebhookDelivery::where('event', 'order.created')->count();
    }

    public function test_a_historical_import_fires_no_order_created_webhooks_or_hooks(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        $this->orderCreatedWebhook();
        $hookCalls = 0;
        add_action('order_created', function () use (&$hookCalls): void {
            $hookCalls++;
        });

        $this->import(['H-1,2024-01-05,delivered,Acme,,WID-1,,1,10,,,'], historical: true);

        $this->assertTrue(Order::where('external_reference', 'H-1')->exists());
        $this->assertSame(0, $this->orderCreatedDeliveries());
        $this->assertSame(0, $hookCalls);
    }

    public function test_a_stock_adjusting_import_fires_order_created_webhooks_by_default(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        $this->orderCreatedWebhook();

        $this->import([
            'W-1,2026-01-05,pending,Acme,,WID-1,,1,10,,,',
            'W-2,2026-01-05,pending,Acme,,WID-1,,1,10,,,',
        ]);

        $this->assertSame(2, $this->orderCreatedDeliveries());
    }

    public function test_a_stock_adjusting_import_can_skip_notifying_integrations(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        $this->orderCreatedWebhook();

        $import = new OrdersImport($this->admin, historical: false, notifyIntegrations: false);
        Excel::import($import, $this->csv(['W-3,2026-01-05,pending,Acme,,WID-1,,1,10,,,']));

        $this->assertTrue(Order::where('external_reference', 'W-3')->exists());
        $this->assertSame(19, $this->widget->fresh()->stock);
        $this->assertSame(0, $this->orderCreatedDeliveries());
    }

    public function test_the_endpoint_passes_the_webhook_option_and_the_queued_job_carries_it(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        $this->orderCreatedWebhook();

        $this->actingAs($this->admin)->post(route('import-export.import-orders'), [
            'file' => $this->csv(['W-4,2026-01-05,pending,Acme,,WID-1,,1,10,,,']),
            'notify_integrations' => '0',
        ])->assertRedirect(route('import-export.index'));
        $this->assertSame(0, $this->orderCreatedDeliveries());

        Bus::fake();
        Storage::fake(config('imports.disk'));
        config(['imports.sync_max_kb' => 0]);
        $this->actingAs($this->admin)->post(route('import-export.import-orders'), [
            'file' => $this->csv(['W-5,2026-01-05,pending,Acme,,WID-1,,1,10,,,']),
            'notify_integrations' => '0',
        ]);
        Bus::assertDispatched(ProcessOrderImportJob::class, fn ($job) => $job->notifyIntegrations === false);
    }

    public function test_the_endpoint_imports_and_honours_the_historical_flag(): void
    {
        $response = $this->actingAs($this->admin)->post(route('import-export.import-orders'), [
            'file' => $this->csv(['E-1,2026-01-05,delivered,Acme,,WID-1,,2,10,,,']),
            'historical' => '1',
        ]);

        $response->assertRedirect(route('import-export.index'));
        $this->assertIsString(session('success'));
        $this->assertTrue(Order::where('external_reference', 'E-1')->exists());
        $this->assertSame(20, $this->widget->fresh()->stock);
    }

    public function test_the_endpoint_flashes_row_errors(): void
    {
        $this->actingAs($this->admin)->post(route('import-export.import-orders'), [
            'file' => $this->csv(['E-2,2026-01-05,pending,Acme,,NOPE,,1,10,,,']),
        ]);

        $flash = session('warning');
        $this->assertSame('orders', $flash['type']);
        $this->assertSame(1, $flash['stats']['failed']);
    }

    public function test_the_endpoint_requires_import_data_and_create_orders(): void
    {
        $member = User::create([
            'name' => 'Member', 'email' => 'member@org.com', 'password' => bcrypt('x'),
            'organization_id' => $this->org->id, 'role' => 'member',
        ]);
        $role = Role::create([
            'name' => 'Importer', 'slug' => 'importer', 'organization_id' => $this->org->id,
            'permissions' => ['import_data'],
        ]);
        $member->roles()->attach($role->id);

        $this->actingAs($member)->post(route('import-export.import-orders'), [
            'file' => $this->csv(['P-1,2026-01-05,pending,Acme,,WID-1,,1,10,,,']),
        ])->assertForbidden();

        $role->update(['permissions' => ['import_data', 'create_orders']]);
        $this->actingAs($member->fresh())->post(route('import-export.import-orders'), [
            'file' => $this->csv(['P-1,2026-01-05,pending,Acme,,WID-1,,1,10,,,']),
        ])->assertRedirect(route('import-export.index'));

        $this->assertTrue(Order::where('external_reference', 'P-1')->exists());
    }

    public function test_a_large_file_is_queued(): void
    {
        Bus::fake();
        Storage::fake(config('imports.disk'));
        config(['imports.sync_max_kb' => 0]);

        $this->actingAs($this->admin)->post(route('import-export.import-orders'), [
            'file' => $this->csv(['Q-1,2026-01-05,pending,Acme,,WID-1,,1,10,,,']),
            'historical' => '1',
        ])->assertRedirect(route('import-export.index'));

        Bus::assertDispatched(ProcessOrderImportJob::class, fn ($job) => $job->historical === true
            && $job->organizationId === $this->org->id);
        $this->assertFalse(Order::exists());
    }

    public function test_the_queued_job_imports_and_notifies(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('imports/orders.csv', self::HEADER."\nJ-1,2026-01-05,pending,Acme,,WID-1,,1,10,,,\n");

        (new ProcessOrderImportJob($this->org->id, $this->admin->id, 'local', 'imports/orders.csv', false))->handle();

        $this->assertTrue(Order::where('external_reference', 'J-1')->exists());
        Storage::disk('local')->assertMissing('imports/orders.csv');
        $notification = Notification::where('type', 'import_complete')->sole();
        $this->assertStringContainsString('order import', $notification->message);
    }

    public function test_the_template_lists_the_order_columns(): void
    {
        $csv = $this->actingAs($this->admin)->get(route('import-export.download-order-template'))->streamedContent();
        $lines = preg_split('/\r?\n/', trim($csv));
        $header = str_getcsv($lines[0], escape: '');

        foreach (['external_reference', 'order_date', 'status', 'customer_email', 'product_sku', 'variant_sku', 'quantity', 'unit_price'] as $column) {
            $this->assertContains($column, $header);
        }
        $this->assertGreaterThanOrEqual(3, count($lines));
    }
}
