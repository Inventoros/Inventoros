<?php

declare(strict_types=1);

namespace Tests\Feature\Plugins;

use App\Models\Auth\Organization;
use App\Models\Customer;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductLocation;
use App\Models\Inventory\Supplier;
use App\Models\Inventory\WorkOrder;
use App\Models\Role;
use App\Models\System\SystemSetting;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\OrderService;
use App\Services\PluginUIService;
use App\Services\PurchaseOrderService;
use App\Services\ReturnOrderService;
use App\Services\StockAuditService;
use App\Services\StockTransferService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * The page slots added for plugins (customers, warehouses, stock audits,
 * cycle counts, transfers, returns, work orders, the settings hub, and the
 * actions slot of the order, purchase order and product pages): each page
 * passes the placements the user may see, server side, and its Vue page
 * renders a PluginSlot for each slot.
 */
final class PluginPageSlotsTest extends TestCase
{
    use RefreshDatabase;

    /** page id => [Vue page, slots] */
    private const PAGES = [
        'customers.index' => ['Customers/Index', ['header', 'footer']],
        'customers.show' => ['Customers/Show', ['header', 'actions', 'footer']],
        'warehouses.index' => ['Warehouses/Index', ['header', 'footer']],
        'warehouses.show' => ['Warehouses/Show', ['header', 'actions', 'footer']],
        'stock-audits.index' => ['StockAudits/Index', ['header', 'footer']],
        'stock-audits.show' => ['StockAudits/Show', ['header', 'actions', 'footer']],
        'cycle-counts.index' => ['StockAudits/CycleCounts/Index', ['header', 'footer']],
        'stock-transfers.index' => ['StockTransfers/Index', ['header', 'footer']],
        'stock-transfers.show' => ['StockTransfers/Show', ['header', 'actions', 'footer']],
        'returns.index' => ['Returns/Index', ['header', 'footer']],
        'returns.show' => ['Returns/Show', ['header', 'actions', 'footer']],
        'work-orders.index' => ['WorkOrders/Index', ['header', 'footer']],
        'work-orders.show' => ['WorkOrders/Show', ['header', 'actions', 'footer']],
        'settings.index' => ['Settings/Index', ['header', 'sections', 'footer']],
        'orders.show' => ['Orders/Show', ['actions']],
        'purchase-orders.show' => ['PurchaseOrders/Show', ['actions']],
        'products.show' => ['Products/Show', ['actions']],
    ];

    private Organization $org;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Notification::fake();
        SystemSetting::set('installed', true, 'boolean');
        $this->org = Organization::create(['name' => 'Slots', 'email' => 's@example.com', 'currency' => 'USD', 'timezone' => 'UTC']);
        $this->admin = User::create([
            'name' => 'Admin', 'email' => 'admin@slots.test', 'password' => bcrypt('x'),
            'organization_id' => $this->org->id, 'role' => 'admin',
        ]);
        $this->actingAs($this->admin);
    }

    protected function tearDown(): void
    {
        app(PluginUIService::class)->clear();

        parent::tearDown();
    }

    /**
     * The URL of each page, with the records its detail pages need.
     *
     * @return array<string, string>
     */
    private function urls(): array
    {
        $from = ProductLocation::create(['organization_id' => $this->org->id, 'name' => 'A', 'code' => 'A', 'is_active' => true]);
        $to = ProductLocation::create(['organization_id' => $this->org->id, 'name' => 'B', 'code' => 'B', 'is_active' => true]);
        $product = Product::create([
            'organization_id' => $this->org->id, 'sku' => 'SLOT-1', 'name' => 'Slot', 'price' => 10,
            'currency' => 'USD', 'stock' => 50, 'location_id' => $from->id, 'is_active' => true,
        ]);
        $supplier = Supplier::create(['organization_id' => $this->org->id, 'name' => 'Sup', 'email' => 'sup@example.com', 'is_active' => true]);
        $customer = Customer::create(['organization_id' => $this->org->id, 'name' => 'Cust']);
        $warehouse = Warehouse::create(['organization_id' => $this->org->id, 'name' => 'Main', 'code' => 'MAIN']);
        $order = app(OrderService::class)->create([
            'customer_name' => 'Buyer', 'status' => 'delivered', 'order_date' => now()->toDateString(),
            'items' => [['product_id' => $product->id, 'quantity' => 2, 'unit_price' => 10]],
        ], $this->admin);
        $return = app(ReturnOrderService::class)->create($this->org->id, $this->admin, [
            'order_id' => $order->id, 'type' => 'return', 'reason' => 'Broken',
            'items' => [['order_item_id' => $order->items()->first()->id, 'quantity' => 1, 'condition' => 'damaged', 'restock' => false]],
        ]);
        $transfer = app(StockTransferService::class)->create($this->org->id, $this->admin, [
            'from_location_id' => $from->id, 'to_location_id' => $to->id,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ]);
        $audit = app(StockAuditService::class)->create($this->org->id, $this->admin, [
            'name' => 'Spot', 'audit_type' => 'spot', 'product_ids' => [$product->id],
        ]);
        $workOrder = WorkOrder::create([
            'organization_id' => $this->org->id, 'product_id' => $product->id, 'created_by' => $this->admin->id,
            'work_order_number' => 'WO-1', 'quantity' => 2, 'status' => 'draft',
        ]);
        $po = app(PurchaseOrderService::class)->create($this->org->id, $this->admin, [
            'supplier_id' => $supplier->id, 'order_date' => now()->toDateString(), 'currency' => 'USD',
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_cost' => '1.00']],
        ]);

        return [
            'customers.index' => route('customers.index'),
            'customers.show' => route('customers.show', $customer),
            'warehouses.index' => route('warehouses.index'),
            'warehouses.show' => route('warehouses.show', $warehouse),
            'stock-audits.index' => route('stock-audits.index'),
            'stock-audits.show' => route('stock-audits.show', $audit),
            'cycle-counts.index' => route('cycle-counts.index'),
            'stock-transfers.index' => route('stock-transfers.index'),
            'stock-transfers.show' => route('stock-transfers.show', $transfer),
            'returns.index' => route('returns.index'),
            'returns.show' => route('returns.show', $return),
            'work-orders.index' => route('work-orders.index'),
            'work-orders.show' => route('work-orders.show', $workOrder),
            'settings.index' => route('settings.index'),
            'orders.show' => route('orders.show', $order),
            'purchase-orders.show' => route('purchase-orders.show', $po),
            'products.show' => route('products.show', $product),
        ];
    }

    private function place(string $page, string $slot, ?string $permission = null): void
    {
        add_page_component($page, $slot, array_filter([
            'plugin' => 'slot-test',
            'component' => 'Probe',
            'data' => ['at' => $page.'/'.$slot],
            'permission' => $permission,
        ]));
    }

    public function test_every_new_slot_passes_its_placements_to_the_page(): void
    {
        foreach (self::PAGES as $page => [, $slots]) {
            foreach ($slots as $slot) {
                $this->place($page, $slot);
            }
        }

        foreach ($this->urls() as $page => $url) {
            [$component, $slots] = self::PAGES[$page];

            $this->get($url)->assertOk()->assertInertia(function (AssertableInertia $inertia) use ($component, $slots, $page) {
                $inertia->component($component);

                foreach ($slots as $slot) {
                    $inertia->where('pluginComponents.'.Str::camel($slot).'.0.data.at', $page.'/'.$slot);
                }

                return $inertia;
            });
        }
    }

    public function test_placements_are_permission_gated_server_side(): void
    {
        $this->place('customers.show', 'actions', 'view_reports');
        $this->place('settings.index', 'sections', 'view_reports');
        $urls = $this->urls();

        $role = Role::create(['name' => 'Clerk', 'slug' => 'clerk', 'permissions' => ['view_customers']]);
        $clerk = User::create([
            'name' => 'Clerk', 'email' => 'clerk@slots.test', 'password' => bcrypt('x'),
            'organization_id' => $this->org->id, 'role' => 'member',
        ]);
        $clerk->roles()->attach($role->id);

        $this->actingAs($clerk)->get($urls['customers.show'])->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('pluginComponents.actions', []));
        $this->actingAs($clerk)->get($urls['settings.index'])->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('pluginComponents.sections', []));

        $this->actingAs($this->admin)->get($urls['customers.show'])
            ->assertInertia(fn (AssertableInertia $page) => $page->has('pluginComponents.actions', 1));
    }

    public function test_without_plugins_the_slots_are_empty(): void
    {
        $this->get($this->urls()['customers.show'])
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('pluginComponents', ['header' => [], 'actions' => [], 'footer' => []]));
    }

    public function test_every_vue_page_renders_its_slots(): void
    {
        foreach (self::PAGES as $page => [$component, $slots]) {
            $source = (string) file_get_contents(resource_path('js/Pages/'.$component.'.vue'));

            foreach ($slots as $slot) {
                $this->assertMatchesRegularExpression(
                    '/<PluginSlot slot="'.$slot.'"[^>]*:components="pluginComponents\?\.'.Str::camel($slot).'"/',
                    $source,
                    "{$component}.vue does not render the {$slot} slot of {$page}."
                );
            }
        }
    }

    public function test_the_guide_lists_every_page_and_slot(): void
    {
        $guide = (string) file_get_contents(base_path('docs/PLUGIN_DEVELOPMENT.md'));

        foreach (self::PAGES as $page => [, $slots]) {
            $this->assertMatchesRegularExpression('/^\|[^\n]*`'.preg_quote($page, '/').'`[^\n]*\|/m', $guide, "The slot table does not list {$page}.");

            preg_match('/^\|[^\n]*`'.preg_quote($page, '/').'`[^\n]*$/m', $guide, $row);
            foreach ($slots as $slot) {
                $this->assertStringContainsString('`'.$slot.'`', $row[0], "The slot table row for {$page} does not list {$slot}.");
            }
        }
    }
}
