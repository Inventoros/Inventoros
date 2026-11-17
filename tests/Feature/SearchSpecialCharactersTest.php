<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Imports\ProductsImport;
use App\Models\Auth\Organization;
use App\Models\Customer;
use App\Models\Inventory\Product;
use App\Models\Inventory\Supplier;
use App\Models\Order\Order;
use App\Models\Purchasing\PurchaseOrder;
use App\Models\System\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Maatwebsite\Excel\Facades\Excel;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Issue #430: a product named "Player's Handbook" could not be found by its
 * full name, while "Player" and "Handbook" each found it.
 */
class SearchSpecialCharactersTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        SystemSetting::set('installed', true, 'boolean');
        $this->org = Organization::create(['name' => 'Tabletop', 'email' => 'tabletop@org.com', 'currency' => 'USD', 'timezone' => 'UTC']);
        $this->admin = User::create([
            'name' => 'Admin', 'email' => 'admin@tabletop.test', 'password' => bcrypt('x'),
            'organization_id' => $this->org->id, 'role' => 'admin',
        ]);
    }

    private function product(string $name, string $sku): Product
    {
        return Product::create([
            'organization_id' => $this->org->id, 'sku' => $sku, 'name' => $name,
            'price' => 10, 'stock' => 5,
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function inventoryListNames(string $search): array
    {
        $names = [];

        $this->withoutVite()->actingAs($this->admin)
            ->get(route('products.index', ['search' => $search]))
            ->assertOk()
            ->assertInertia(function ($page) use (&$names) {
                $names = collect($page->toArray()['props']['products']['data'])->pluck('name')->sort()->values()->all();
            });

        return $names;
    }

    public function test_names_are_stored_verbatim_by_the_web_form_the_api_and_csv_import(): void
    {
        $name = 'Player\'s "Big" Handbook & <Co>';

        $this->actingAs($this->admin)->post(route('products.store'), [
            'sku' => 'WEB-1', 'name' => $name, 'price' => 10, 'currency' => 'USD', 'stock' => 0, 'min_stock' => 0,
        ])->assertSessionHasNoErrors();

        Sanctum::actingAs($this->admin, ['*']);
        $this->postJson('/api/v1/products', ['sku' => 'API-1', 'name' => $name, 'price' => 10])->assertCreated();

        Excel::import(new ProductsImport($this->org->id), UploadedFile::fake()->createWithContent(
            'products.csv',
            "name,sku,price,stock\n\"Player's \"\"Big\"\" Handbook & <Co>\",CSV-1,10,0\n",
        ));

        $stored = Product::withoutGlobalScopes()->whereIn('sku', ['WEB-1', 'API-1', 'CSV-1'])->pluck('name', 'sku')->all();

        $this->assertSame(['API-1' => $name, 'CSV-1' => $name, 'WEB-1' => $name], collect($stored)->sortKeys()->all());
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function namesAndSearches(): array
    {
        return [
            'ascii apostrophe' => ["Player's Handbook", "Player's Handbook"],
            'stored typographic, typed ascii' => ['Player’s Handbook', "Player's Handbook"],
            'stored ascii, typed typographic' => ["Player's Handbook", 'Player’s Handbook'],
            'left single quote' => ['‘Classic’ Edition', "'Classic' Edition"],
            'curly double quotes' => ['The “Deluxe” Box', 'The "Deluxe" Box'],
            'straight double quotes typed curly' => ['The "Deluxe" Box', 'The “Deluxe” Box'],
            'ampersand' => ['Dungeons & Dragons', 'Dungeons & Dragons'],
            'angle bracket' => ['Cable <5m>', 'Cable <5m'],
            'backslash' => ['Path C:\\Games', 'C:\\Games'],
            'percent' => ['100% Cotton', '100% Cotton'],
            'underscore' => ['snake_case Mug', 'snake_case'],
            'accented letters' => ['Crème Brûlée Kit', 'Crème Brûlée'],
            'accented letters, lowercase first letter typed' => ['Crème Brûlée Kit', 'crème brûlée'],
        ];
    }

    #[DataProvider('namesAndSearches')]
    public function test_the_inventory_list_finds_a_product_by_its_full_name(string $name, string $search): void
    {
        $this->product($name, 'MATCH-1');
        $this->product('Unrelated Item', 'OTHER-1');

        $this->assertSame([$name], $this->inventoryListNames($search));
    }

    public function test_percent_and_underscore_in_the_inventory_search_are_literal(): void
    {
        $this->product('100% Cotton', 'P-1');
        $this->product('1000 Thread Count', 'P-2');
        $this->product('a_b Widget', 'P-3');
        $this->product('axb Widget', 'P-4');

        $this->assertSame(['100% Cotton'], $this->inventoryListNames('100%'));
        $this->assertSame(['a_b Widget'], $this->inventoryListNames('a_b'));
        $this->assertSame(['100% Cotton'], $this->inventoryListNames('%'));
        $this->assertSame(['a_b Widget'], $this->inventoryListNames('_'));
    }

    public function test_a_variant_sku_with_an_apostrophe_still_finds_its_product(): void
    {
        $product = $this->product('Dice Set', 'DICE-1');
        $product->variants()->create(['organization_id' => $this->org->id, 'sku' => 'DICE’S-RED', 'title' => 'Red', 'option_values' => ['Colour' => 'Red'], 'price' => 10, 'stock' => 1, 'is_active' => true]);

        $this->assertSame(['Dice Set'], $this->inventoryListNames("DICE'S-RED"));
    }

    /**
     * The order and purchase order pickers filter in the browser (see
     * tests/js/searchText.test.js); the server must hand them the name as
     * stored, not HTML-encoded.
     */
    public function test_the_order_and_purchase_order_pickers_receive_the_name_unencoded(): void
    {
        $this->product('Player’s Handbook & <Co>', 'PHB-1');

        foreach (['orders.create', 'purchase-orders.create'] as $route) {
            $this->withoutVite()->actingAs($this->admin)->get(route($route))->assertOk()
                ->assertInertia(fn ($page) => $page->where('products', fn ($products) => collect($products)->pluck('name')->contains('Player’s Handbook & <Co>')));
        }
    }

    public function test_global_search_matches_typographic_and_ascii_quotes_in_every_group(): void
    {
        $this->product('Player’s Handbook', 'PHB-1');
        Customer::create(['organization_id' => $this->org->id, 'name' => 'O’Brien Games', 'email' => 'obrien@example.test']);
        Supplier::create(['organization_id' => $this->org->id, 'name' => "Wizard's Supply", 'code' => 'WIZ', 'is_active' => true]);
        Order::create(['organization_id' => $this->org->id, 'created_by' => $this->admin->id, 'order_number' => 'ORD-430',
            'customer_name' => 'O’Brien Games', 'status' => 'pending', 'currency' => 'USD', 'order_date' => now()->toDateString(),
            'subtotal' => 0, 'total' => 0]);

        $this->actingAs($this->admin)->getJson('/search?'.http_build_query(['q' => "Player's Handbook"]))
            ->assertOk()->assertJsonPath('products.0.title', 'Player’s Handbook');

        $this->getJson('/search?'.http_build_query(['q' => "O'Brien"]))
            ->assertOk()
            ->assertJsonPath('customers.0.title', 'O’Brien Games')
            ->assertJsonPath('orders.0.title', 'ORD-430');

        $this->getJson('/search?'.http_build_query(['q' => 'Wizard’s']))
            ->assertOk()->assertJsonPath('suppliers.0.title', "Wizard's Supply");
    }

    public function test_the_order_customer_picker_and_list_pages_match_quotes(): void
    {
        Customer::create(['organization_id' => $this->org->id, 'name' => 'O’Brien Games', 'is_active' => true]);
        Customer::create(['organization_id' => $this->org->id, 'name' => 'OBrien Other', 'is_active' => true]);
        $supplier = Supplier::create(['organization_id' => $this->org->id, 'name' => 'Wizard’s Supply', 'code' => 'WIZ', 'is_active' => true]);
        PurchaseOrder::create(['organization_id' => $this->org->id, 'supplier_id' => $supplier->id, 'po_number' => 'PO-430',
            'status' => 'draft', 'order_date' => now()->toDateString(), 'currency' => 'USD', 'subtotal' => 0, 'total' => 0, 'created_by' => $this->admin->id]);

        $this->actingAs($this->admin)->getJson(route('orders.customer-lookup', ['q' => "o'brien"]))
            ->assertOk()->assertJsonCount(1, 'customers')->assertJsonPath('customers.0.name', 'O’Brien Games');

        $this->withoutVite()->get(route('customers.index', ['search' => "O'Brien"]))->assertOk()
            ->assertInertia(fn ($page) => $page->has('customers.data', 1)->where('customers.data.0.name', 'O’Brien Games'));

        $this->get(route('suppliers.index', ['search' => "Wizard's"]))->assertOk()
            ->assertInertia(fn ($page) => $page->has('suppliers.data', 1));

        $this->get(route('purchase-orders.index', ['search' => "Wizard's"]))->assertOk()
            ->assertInertia(fn ($page) => $page->has('purchaseOrders.data', 1)->where('purchaseOrders.data.0.po_number', 'PO-430'));
    }
}
