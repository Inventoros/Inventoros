<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\System\SystemSetting;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The product filters the plugin guide documents must actually run:
 * `product_display_name` and `product_price_display` on every surface that
 * shows a product, and `product_search_query` on every product search.
 */
final class PluginProductFiltersTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $admin;

    private Product $widget;

    protected function setUp(): void
    {
        parent::setUp();

        SystemSetting::set('installed', true, 'boolean');

        $this->organization = Organization::create([
            'name' => 'Org', 'email' => 'org@example.com', 'currency' => 'USD', 'timezone' => 'UTC',
        ]);

        $this->admin = User::create([
            'name' => 'Admin', 'email' => 'admin@example.com', 'password' => bcrypt('password'),
            'organization_id' => $this->organization->id, 'role' => 'admin',
        ]);

        $this->widget = Product::create([
            'organization_id' => $this->organization->id, 'sku' => 'W-1', 'name' => 'Widget',
            'price' => 10, 'currency' => 'USD', 'stock' => 5, 'min_stock' => 0, 'is_active' => true,
            'notes' => 'hidden-keyword',
        ]);
    }

    private function registerDisplayFilters(): void
    {
        add_filter('product_display_name', fn ($name, $product) => '*'.$name.' ('.$product->sku.')', 10);
        add_filter('product_price_display', fn ($price, $product) => round((float) $price * 0.9, 2), 10);
    }

    public function test_display_fields_equal_raw_values_without_a_filter(): void
    {
        $this->actingAs($this->admin)
            ->get(route('products.show', $this->widget))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('product.name', 'Widget')
                ->where('product.display_name', 'Widget')
                ->where('product.display_price', 10));
    }

    public function test_display_filters_apply_to_the_product_index_props(): void
    {
        $this->registerDisplayFilters();

        $this->actingAs($this->admin)
            ->get(route('products.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('products.data.0.name', 'Widget')
                ->where('products.data.0.display_name', '*Widget (W-1)')
                ->where('products.data.0.display_price', 9));
    }

    public function test_display_filters_apply_to_the_product_show_props(): void
    {
        $this->registerDisplayFilters();

        $this->actingAs($this->admin)
            ->get(route('products.show', $this->widget))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('product.name', 'Widget')
                ->where('product.display_name', '*Widget (W-1)')
                ->where('product.display_price', 9));
    }

    public function test_display_filters_apply_to_the_api_product_resource(): void
    {
        $this->registerDisplayFilters();
        Sanctum::actingAs($this->admin, ['*']);

        $this->getJson('/api/v1/products')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Widget')
            ->assertJsonPath('data.0.display_name', '*Widget (W-1)')
            ->assertJsonPath('data.0.display_price', 9);

        $this->getJson('/api/v1/products/'.$this->widget->id)
            ->assertOk()
            ->assertJsonPath('data.display_name', '*Widget (W-1)');
    }

    public function test_display_filters_apply_to_global_search_titles(): void
    {
        $this->registerDisplayFilters();

        $this->actingAs($this->admin)
            ->getJson('/search?q=Widget')
            ->assertOk()
            ->assertJsonPath('products.0.title', '*Widget (W-1)');
    }

    /**
     * A plugin that also searches the notes column: the term only matches
     * notes, so the product is found only if the filter ran.
     */
    private function registerNotesSearchFilter(?array &$calls = null): void
    {
        add_filter('product_search_query', function (Builder $query, string $term) use (&$calls) {
            $calls[] = $term;

            return $query->orWhere('notes', 'like', '%'.$term.'%');
        }, 10);
    }

    public function test_search_filter_applies_to_the_product_index(): void
    {
        $calls = [];
        $this->registerNotesSearchFilter($calls);

        $this->actingAs($this->admin)
            ->get(route('products.index', ['search' => 'hidden-keyword']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('products.data', 1));

        $this->assertSame(['hidden-keyword'], $calls);
    }

    public function test_search_filter_applies_to_global_search(): void
    {
        $this->registerNotesSearchFilter();

        $this->actingAs($this->admin)
            ->getJson('/search?q=hidden-keyword')
            ->assertOk()
            ->assertJsonCount(1, 'products');
    }

    public function test_search_filter_applies_to_the_api_product_list(): void
    {
        $this->registerNotesSearchFilter();
        Sanctum::actingAs($this->admin, ['*']);

        $this->getJson('/api/v1/products?search=hidden-keyword')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_search_filter_is_not_called_without_a_search_term(): void
    {
        $calls = [];
        $this->registerNotesSearchFilter($calls);

        $this->actingAs($this->admin)->get(route('products.index'))->assertOk();

        $this->assertSame([], $calls);
    }

    public function test_search_filter_cannot_widen_the_query_to_another_tenant(): void
    {
        $other = Organization::create([
            'name' => 'Other', 'email' => 'other@example.com', 'currency' => 'USD', 'timezone' => 'UTC',
        ]);
        Product::withoutGlobalScopes()->create([
            'organization_id' => $other->id, 'sku' => 'O-1', 'name' => 'Other widget',
            'price' => 1, 'currency' => 'USD', 'stock' => 1, 'min_stock' => 0, 'is_active' => true,
        ]);

        // A hostile filter returns a fresh, unscoped query.
        add_filter('product_search_query', fn () => Product::withoutGlobalScopes(), 10);

        $this->actingAs($this->admin)
            ->get(route('products.index', ['search' => 'widget']))
            ->assertInertia(fn ($page) => $page->has('products.data', 1)
                ->where('products.data.0.sku', 'W-1'));

        $this->actingAs($this->admin)
            ->getJson('/search?q=widget')
            ->assertJsonCount(1, 'products');
    }
}
