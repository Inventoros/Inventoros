<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Inventory\Product;
use App\Models\Order\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\Feature\Concerns\CreatesPluginUiUsers;
use Tests\TestCase;

/**
 * Plugin `tabs` placements on the product and order detail pages: sent only
 * to users who hold their permission, with their tab label, and rendered by
 * a tab bar that appears only when there is at least one plugin tab.
 */
final class PluginDetailTabsTest extends TestCase
{
    use CreatesPluginUiUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createPluginUiUsers();
    }

    private function registerTabs(string $page): void
    {
        add_page_component($page, 'tabs', [
            'plugin' => 'fixture', 'component' => 'Notes', 'label' => 'Notes',
        ]);
        add_page_component($page, 'tabs', [
            'plugin' => 'fixture', 'component' => 'Margins', 'label' => 'Margins',
            'permission' => 'view_reports',
            'data' => fn ($user) => ['viewer' => $user->id],
        ]);
    }

    public function test_product_tabs_are_permission_gated_and_carry_their_label(): void
    {
        $product = Product::create([
            'organization_id' => $this->admin->organization_id, 'sku' => 'T-1', 'name' => 'Tabbed',
            'price' => 1, 'currency' => 'USD', 'stock' => 1, 'min_stock' => 0, 'is_active' => true,
        ]);
        $this->registerTabs('products.show');

        $this->actingAs($this->staff)->get(route('products.show', $product))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('pluginComponents.tabs', 1)
                ->where('pluginComponents.tabs.0.label', 'Notes'));

        $this->actingAs($this->admin)->get(route('products.show', $product))
            ->assertInertia(fn ($page) => $page
                ->has('pluginComponents.tabs', 2)
                ->where('pluginComponents.tabs.1.label', 'Margins')
                ->where('pluginComponents.tabs.1.data', ['viewer' => $this->admin->id]));
    }

    public function test_order_tabs_are_permission_gated_and_carry_their_label(): void
    {
        $order = Order::create([
            'organization_id' => $this->admin->organization_id,
            'order_number' => 'ORD-TABS-1',
            'source' => 'manual',
            'customer_name' => 'Customer',
            'status' => 'pending',
            'subtotal' => 10, 'tax' => 0, 'shipping' => 0, 'total' => 10,
            'currency' => 'USD',
        ]);
        $this->registerTabs('orders.show');

        $this->actingAs($this->staff)->get(route('orders.show', $order))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('pluginComponents.tabs', 1)
                ->where('pluginComponents.tabs.0.label', 'Notes'));

        $this->actingAs($this->admin)->get(route('orders.show', $order))
            ->assertInertia(fn ($page) => $page->has('pluginComponents.tabs', 2));
    }

    public function test_detail_pages_wrap_their_core_content_in_plugin_tabs(): void
    {
        foreach (['Products/Show.vue', 'Orders/Show.vue'] as $page) {
            $source = File::get(resource_path('js/Pages/'.$page));

            $this->assertMatchesRegularExpression(
                '/<PluginTabs[^>]*:components="pluginComponents\?\.tabs"/',
                $source,
                "{$page} must render its plugin tabs."
            );
        }

        // Without plugin tabs the component renders the core content alone.
        $tabs = File::get(resource_path('js/Components/PluginTabs.vue'));
        $this->assertStringContainsString('v-if="tabs.length === 0"', $tabs);
    }
}
