<?php

declare(strict_types=1);

namespace Tests\Feature\Kits;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductComponent;
use App\Models\Order\Order;
use App\Models\User;
use App\Services\Documents\FulfillmentDocumentService;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Api\Concerns\BuildsApiFixtures;
use Tests\TestCase;

/**
 * Kits show how many can be made on the order form and product pages, and
 * their components under the kit line on an order.
 */
final class KitDisplayTest extends TestCase
{
    use BuildsApiFixtures, RefreshDatabase;

    private Organization $org;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Notification::fake();
        $this->markInstalled();
        $this->org = $this->makeOrganization();
        $this->admin = $this->makeAdmin($this->org);
        $this->actingAs($this->admin);
    }

    /**
     * @param  array<int, array{0: Product, 1: string|int}>  $parts
     * @param  array<string, mixed>  $attributes
     */
    private function makeKit(array $parts, array $attributes = []): Product
    {
        $kit = $this->makeProduct($this->org, array_merge(
            ['type' => 'kit', 'name' => 'Starter Kit', 'stock' => 0, 'price' => 30.00],
            $attributes,
        ));
        foreach ($parts as $i => [$component, $qty]) {
            ProductComponent::create([
                'parent_product_id' => $kit->id,
                'component_product_id' => $component->id,
                'quantity' => $qty,
                'sort_order' => $i,
            ]);
        }

        return $kit;
    }

    public function test_the_order_form_offers_a_kit_with_its_makeable_count(): void
    {
        $a = $this->makeProduct($this->org, ['name' => 'Part A', 'stock' => 7]);
        $b = $this->makeProduct($this->org, ['name' => 'Part B', 'stock' => 20]);
        $kit = $this->makeKit([[$a, 2], [$b, 1]]);

        $this->get(route('orders.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('products', fn ($products) => collect($products)->contains(
                    fn ($p) => $p['id'] === $kit->id && $p['stock'] === 3 && $p['is_kit'] === true
                ) && collect($products)->contains(
                    fn ($p) => $p['id'] === $a->id && $p['is_kit'] === false
                )));
    }

    public function test_an_unsellable_kit_shows_zero_on_the_order_form(): void
    {
        $a = $this->makeProduct($this->org, ['name' => 'Part A', 'stock' => 10]);
        $kit = $this->makeKit([[$a, '1.5']]);

        $this->get(route('orders.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('products', fn ($products) => collect($products)->contains(
                    fn ($p) => $p['id'] === $kit->id && $p['stock'] === 0 && $p['is_kit'] === true
                )));
    }

    public function test_the_product_pages_show_makeable_counts_and_the_reason_a_kit_cannot_be_sold(): void
    {
        $a = $this->makeProduct($this->org, ['name' => 'Part A', 'stock' => 9]);
        $good = $this->makeKit([[$a, 3]], ['name' => 'Good Kit']);
        $bad = $this->makeKit([[$a, '1.5']], ['name' => 'Bad Kit']);

        $this->get(route('products.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('kitAvailable', fn ($map) => collect($map)->all() == [$good->id => 3, $bad->id => 0]));

        $this->get(route('products.show', $good))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('kitAvailable', 3)->where('kitProblem', null));

        $this->get(route('products.show', $bad))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('kitAvailable', 0)->where('kitProblem', fn ($v) => is_string($v) && $v !== ''));

        $this->get(route('products.show', $a))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('kitAvailable', null)->where('kitProblem', null));
    }

    public function test_order_show_lists_kit_components(): void
    {
        $a = $this->makeProduct($this->org, ['name' => 'Part A', 'sku' => 'PA', 'stock' => 10]);
        $b = $this->makeProduct($this->org, ['name' => 'Part B', 'sku' => 'PB', 'stock' => 10]);
        $kit = $this->makeKit([[$a, 2], [$b, 1]]);
        $plain = $this->makeProduct($this->org, ['name' => 'Plain', 'stock' => 5]);

        $order = app(OrderService::class)->create([
            'customer_name' => 'Acme',
            'status' => 'pending',
            'order_date' => now()->toDateString(),
            'items' => [
                ['product_id' => $kit->id, 'quantity' => 2, 'unit_price' => 30.00],
                ['product_id' => $plain->id, 'quantity' => 1, 'unit_price' => 5.00],
            ],
        ], $this->admin);

        $this->get(route('orders.show', $order))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('order.items.0.kit_components', 2)
                ->where('order.items.0.kit_components.0.name', 'Part A')
                ->where('order.items.0.kit_components.0.sku', 'PA')
                ->where('order.items.0.kit_components.0.quantity_taken', 4)
                ->where('order.items.0.kit_components.1.quantity_taken', 2)
                ->has('order.items.1.kit_components', 0));
    }

    public function test_a_kit_sold_and_cancelled_through_the_web_routes_moves_component_stock_and_back(): void
    {
        $a = $this->makeProduct($this->org, ['name' => 'Part A', 'stock' => 10]);
        $b = $this->makeProduct($this->org, ['name' => 'Part B', 'stock' => 10]);
        $kit = $this->makeKit([[$a, 2], [$b, 1]]);

        $this->post(route('orders.store'), [
            'customer_name' => 'Acme',
            'status' => 'pending',
            'order_date' => now()->toDateString(),
            'items' => [['product_id' => $kit->id, 'quantity' => 1, 'unit_price' => 30.00]],
        ])->assertRedirect(route('orders.index'));

        $order = Order::withoutGlobalScopes()->where('organization_id', $this->org->id)->firstOrFail()->load('items');
        $this->assertSame(8, (int) $a->fresh()->stock);
        $this->assertSame(9, (int) $b->fresh()->stock);

        $this->put(route('orders.update', $order), [
            'customer_name' => 'Acme',
            'order_date' => now()->toDateString(),
            'status' => 'cancelled',
            'items' => $order->items->map(fn ($i) => [
                'id' => $i->id,
                'product_id' => $i->product_id,
                'quantity' => $i->quantity,
                'unit_price' => $i->unit_price,
            ])->all(),
        ])->assertSessionHasNoErrors();

        $this->assertSame(10, (int) $a->fresh()->stock);
        $this->assertSame(10, (int) $b->fresh()->stock);
        $this->assertSame(0, (int) $kit->fresh()->stock);
    }

    public function test_the_pick_list_lists_each_kits_components_for_the_kits_to_pick(): void
    {
        $a = $this->makeProduct($this->org, ['name' => 'Part A', 'sku' => 'PA', 'stock' => 10]);
        $b = $this->makeProduct($this->org, ['name' => 'Part B', 'sku' => 'PB', 'stock' => 10]);
        $kit = $this->makeKit([[$a, 2], [$b, 1]], ['sku' => 'KIT-1']);
        $plain = $this->makeProduct($this->org, ['name' => 'Plain', 'sku' => 'PL', 'stock' => 5]);

        $order = app(OrderService::class)->create([
            'customer_name' => 'Acme',
            'status' => 'pending',
            'order_date' => now()->toDateString(),
            'items' => [
                ['product_id' => $kit->id, 'quantity' => 3, 'unit_price' => 30.00],
                ['product_id' => $plain->id, 'quantity' => 1, 'unit_price' => 5.00],
            ],
        ], $this->admin);

        // The pick list follows what the sale took, not a later BOM edit.
        ProductComponent::where('parent_product_id', $kit->id)->where('component_product_id', $a->id)->update(['quantity' => 5]);

        $data = app(FulfillmentDocumentService::class)->pickListData($order->fresh());

        $this->assertSame('Starter Kit', $data['lines'][0]['name']);
        $this->assertSame(3, $data['lines'][0]['quantity']);
        $this->assertSame([
            ['name' => 'Part A', 'sku' => 'PA', 'quantity' => 6],
            ['name' => 'Part B', 'sku' => 'PB', 'quantity' => 3],
        ], $data['lines'][0]['components']);
        $this->assertArrayNotHasKey('components', $data['lines'][1], 'A plain line has no contents.');

        $html = view('pdf.fulfillment', $data)->render();
        $this->assertStringContainsString('Contains', $html);
        $this->assertMatchesRegularExpression('#<td class="sku">PA</td>\s*<td class="component-name">.*Part A</td>\s*<td class="quantity">6</td>#s', $html);
        $this->assertMatchesRegularExpression('#<td class="sku">PB</td>\s*<td class="component-name">Part B</td>\s*<td class="quantity">3</td>#s', $html);

        App::setLocale('de');
        $this->assertStringContainsString('Enthält', view('pdf.fulfillment', $data)->render());
        App::setLocale('en');

        $this->get(route('orders.pick-list', $order))->assertOk();
    }

    public function test_every_php_locale_translates_the_pick_list_contents_label(): void
    {
        $files = glob(base_path('lang/*.json'));
        $this->assertCount(14, $files);

        foreach ($files as $file) {
            $data = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
            $this->assertNotEmpty($data['Contains'] ?? null, basename($file).' is missing "Contains"');
        }
    }

    public function test_every_locale_has_the_kit_keys(): void
    {
        $files = glob(base_path('resources/js/i18n/locales/*.json'));
        $this->assertCount(14, $files);

        foreach ($files as $file) {
            $data = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
            foreach (['products.kit.canMake', 'products.kit.notSellable', 'orders.show.kitContains'] as $key) {
                $this->assertNotEmpty(data_get($data, $key), basename($file).' is missing '.$key);
            }
        }
    }

    public function test_the_product_list_names_the_component_holding_each_kit_back(): void
    {
        $a = $this->makeProduct($this->org, ['name' => 'Part A', 'stock' => 4]);
        $b = $this->makeProduct($this->org, ['name' => 'Part B', 'stock' => 6]);
        $c = $this->makeProduct($this->org, ['name' => 'Part C', 'stock' => 50]);
        $single = $this->makeKit([[$a, 2], [$c, 1]], ['name' => 'Single Kit']);
        $tied = $this->makeKit([[$a, 2], [$b, 3], [$c, 1]], ['name' => 'Tied Kit']);
        $bad = $this->makeKit([[$a, '1.5']], ['name' => 'Bad Kit']);

        $this->get(route('products.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('kitLimitedBy', fn ($map) => collect($map)->map(fn ($v) => (array) $v)->all() == [
                    $single->id => ['name' => 'Part A', 'others' => 0],
                    $tied->id => ['name' => 'Part A', 'others' => 1],
                ])
                ->where('kitAvailable', fn ($map) => collect($map)->all() == [$single->id => 2, $tied->id => 2, $bad->id => 0]));
    }

    public function test_the_product_page_lists_what_each_component_allows_and_what_one_more_kit_needs(): void
    {
        $a = $this->makeProduct($this->org, ['name' => 'Part A', 'sku' => 'PA', 'stock' => 7]);
        $b = $this->makeProduct($this->org, ['name' => 'Part B', 'sku' => 'PB', 'stock' => 20]);
        $kit = $this->makeKit([[$a, 2], [$b, 1]]);

        $this->get(route('products.show', $kit))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('kitAvailable', 3)
                ->has('kitComponents', 2)
                ->where('kitComponents.0.product_id', $a->id)
                ->where('kitComponents.0.allows', 3)
                ->where('kitComponents.0.limiting', true)
                ->where('kitComponents.0.needed_for_next', 1)
                ->where('kitComponents.1.allows', 20)
                ->where('kitComponents.1.limiting', false));

        $this->get(route('products.show', $a))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('kitComponents', []));
    }

    public function test_the_order_form_says_which_component_limits_a_kit(): void
    {
        $a = $this->makeProduct($this->org, ['name' => 'Part A', 'stock' => 1]);
        $b = $this->makeProduct($this->org, ['name' => 'Part B', 'stock' => 20]);
        $kit = $this->makeKit([[$a, 2], [$b, 1]]);

        $this->get(route('orders.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('products', fn ($products) => collect($products)->contains(
                    fn ($p) => $p['id'] === $kit->id && $p['stock'] === 0 && $p['limited_by'] === 'Part A'
                ) && collect($products)->contains(
                    fn ($p) => $p['id'] === $a->id && $p['limited_by'] === null
                )));
    }
}
