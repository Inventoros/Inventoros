<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Database\PostgresConnection;
use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Order\Order;
use App\Models\User;
use App\Support\Search;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The shared "contains" search (issue #430). Runs against whichever database
 * the suite uses, so CI checks SQLite, MySQL and PostgreSQL.
 */
class SearchHelperTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create();
    }

    /**
     * @param  array<int, string>  $names
     * @return array<int, string>
     */
    private function matching(array $names, string $term, array $columns = ['name']): array
    {
        foreach ($names as $index => $name) {
            Product::create(['organization_id' => $this->org->id, 'sku' => 'S-'.$index, 'name' => $name, 'price' => 1, 'stock' => 0]);
        }

        return Search::apply(Product::query()->where('organization_id', $this->org->id), $columns, $term)
            ->pluck('name')->sort()->values()->all();
    }

    /**
     * @return array<string, array{0: array<int, string>, 1: string, 2: array<int, string>}>
     */
    public static function cases(): array
    {
        return [
            'ascii apostrophe' => [["Player's Handbook", 'Players Handbook'], "Player's Handbook", ["Player's Handbook"]],
            'typographic apostrophe stored' => [['Player’s Handbook', 'Players Handbook'], "Player's Handbook", ['Player’s Handbook']],
            'typographic apostrophe typed' => [["Player's Handbook"], 'Player’s Handbook', ["Player's Handbook"]],
            'both forms stored' => [["Player's Handbook", 'Player’s Handbook', 'Playerxs Handbook'], "player's handbook", ["Player's Handbook", 'Player’s Handbook']],
            'modifier letter apostrophe' => [["Player\u{02BC}s Handbook"], "Player's", ["Player\u{02BC}s Handbook"]],
            'mixed quotes in one name' => [['It’s the "Best"'], 'it\'s the “best”', ['It’s the "Best"']],
            'curly double quotes stored' => [['The “Deluxe” Box', 'The Deluxe Box'], 'The "Deluxe" Box', ['The “Deluxe” Box']],
            'ampersand' => [['Dungeons & Dragons', 'Dungeons and Dragons'], 'Dungeons & Dragons', ['Dungeons & Dragons']],
            'less-than sign' => [['Cable <5m>', 'Cable 5m'], 'Cable <5m', ['Cable <5m>']],
            'backslash' => [['Path C:\\Games', 'Path C:Games'], 'C:\\Games', ['Path C:\\Games']],
            'percent is literal' => [['100% Cotton', '1000 Thread Count'], '100%', ['100% Cotton']],
            'percent alone matches only a percent' => [['100% Cotton', 'Plain'], '%', ['100% Cotton']],
            'underscore is literal' => [['a_b Widget', 'axb Widget'], 'a_b', ['a_b Widget']],
            'escape character is literal' => [['Wow! Edition', 'Wow Edition'], 'Wow!', ['Wow! Edition']],
            'escape character before a wildcard' => [['50!% off', '50!x off'], '!%', ['50!% off']],
            'apostrophe next to a wildcard character' => [["it's 100%_done", 'it’s 100%_done', 'its 1000xdone'], "'s 100%_", ["it's 100%_done", 'it’s 100%_done']],
            // Whether "Creme" also finds "Crème" is left to the database collation.
            'accented letters' => [['Crème Brûlée Kit', 'Plain Kit'], 'Crème Brûlée', ['Crème Brûlée Kit']],
            'case-insensitive ascii' => [['Widget'], 'wIDGET', ['Widget']],
        ];
    }

    /**
     * @param  array<int, string>  $names
     * @param  array<int, string>  $expected
     */
    #[DataProvider('cases')]
    public function test_it_matches_what_was_typed(array $names, string $term, array $expected): void
    {
        sort($expected);

        $this->assertSame($expected, $this->matching($names, $term));
    }

    public function test_it_searches_every_column_with_or(): void
    {
        Product::create(['organization_id' => $this->org->id, 'sku' => "O'NEIL-1", 'name' => 'Lamp', 'price' => 1, 'stock' => 0]);
        Product::create(['organization_id' => $this->org->id, 'sku' => 'X-2', 'name' => 'O’Neil Chair', 'price' => 1, 'stock' => 0]);
        Product::create(['organization_id' => $this->org->id, 'sku' => 'X-3', 'name' => 'Oneil Table', 'price' => 1, 'stock' => 0]);

        $found = Search::apply(Product::query()->where('organization_id', $this->org->id), ['name', 'sku'], "o'neil")
            ->orderBy('sku')->pluck('sku')->all();

        $this->assertSame(["O'NEIL-1", 'X-2'], $found);
    }

    public function test_the_group_does_not_loosen_the_surrounding_conditions(): void
    {
        $other = Organization::factory()->create();
        Product::create(['organization_id' => $other->id, 'sku' => 'A', 'name' => "Player's Handbook", 'price' => 1, 'stock' => 0]);

        $this->assertSame([], $this->matching([], "Player's Handbook", ['name', 'sku']));
    }

    public function test_it_searches_json_selector_columns(): void
    {
        $admin = User::factory()->create(['organization_id' => $this->org->id]);
        Order::create(['organization_id' => $this->org->id, 'created_by' => $admin->id, 'order_number' => 'ORD-1',
            'customer_name' => 'Someone', 'status' => 'pending', 'currency' => 'USD', 'order_date' => now()->toDateString(),
            'subtotal' => 0, 'total' => 0, 'metadata' => ['shopify' => ['name' => 'Kid’s #1001']]]);

        $found = Search::apply(Order::query()->where('organization_id', $this->org->id), ['order_number', 'metadata->shopify->name'], "Kid's #1001")
            ->pluck('order_number')->all();

        $this->assertSame(['ORD-1'], $found);
    }

    public function test_it_works_on_a_plain_query_builder(): void
    {
        Product::create(['organization_id' => $this->org->id, 'sku' => 'Q-1', 'name' => 'Player’s Handbook', 'price' => 1, 'stock' => 0]);

        $found = Search::apply(DB::table('products')->where('organization_id', $this->org->id), ['products.name'], "Player's Handbook")
            ->pluck('sku')->all();

        $this->assertSame(['Q-1'], $found);
    }

    public function test_postgres_compiles_to_an_escaped_ilike_on_the_text_value(): void
    {
        $connection = new PostgresConnection(fn () => null, 'inventoros', '', ['driver' => 'pgsql', 'name' => 'pgsql']);

        $plain = Search::apply($connection->table('products'), ['name'], '50%_off');
        $this->assertSame('select * from "products" where ("name"::text ilike ? escape \'!\')', $plain->toSql());
        $this->assertSame(['%50!%!_off%'], $plain->getBindings());

        $quoted = Search::apply($connection->table('products'), ['name'], 'Player’s');
        $this->assertStringContainsString('"name"::text ilike ? escape \'!\' and replace(', $quoted->toSql());
        $this->assertSame('%Player_s%', $quoted->getBindings()[0]);
        $this->assertSame("%Player's%", last($quoted->getBindings()));
    }

    public function test_fold_maps_typographic_quotes_to_ascii(): void
    {
        $this->assertSame('Player\'s "Deluxe" \'Box\'', Search::fold('Player’s “Deluxe” ‘Box’'));
        $this->assertSame('Crème Brûlée', Search::fold('Crème Brûlée'));
    }
}
