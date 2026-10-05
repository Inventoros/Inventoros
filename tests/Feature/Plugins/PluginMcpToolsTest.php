<?php

declare(strict_types=1);

namespace Tests\Feature\Plugins;

use App\Models\Auth\Organization;
use App\Models\Role;
use App\Models\System\SystemSetting;
use App\Models\User;
use App\Services\Plugins\PluginMcpToolRegistry;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * register_mcp_tool(): plugins add tools to the MCP server, namespaced by
 * slug and gated by core on the user's permissions and the token's
 * abilities.
 */
final class PluginMcpToolsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        SystemSetting::set('installed', true, 'boolean');
        $this->org = Organization::create(['name' => 'Mcp', 'email' => 'mcp@example.com', 'currency' => 'USD', 'timezone' => 'UTC']);
    }

    private function user(string $role = 'admin', array $permissions = []): User
    {
        $user = User::create([
            'name' => 'U', 'email' => uniqid().'@mcp.test', 'password' => bcrypt('x'),
            'organization_id' => $this->org->id, 'role' => $role,
        ]);

        if ($permissions !== []) {
            $custom = Role::create(['name' => 'Custom '.uniqid(), 'slug' => 'custom-'.uniqid(), 'permissions' => $permissions]);
            $user->roles()->attach($custom->id);
        }

        return $user;
    }

    private function rpc(string $method, array $params = []): TestResponse
    {
        return $this->postJson('/mcp', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params === [] ? (object) [] : $params,
        ])->assertOk();
    }

    /**
     * @return array<int, string>
     */
    private function listedNames(): array
    {
        $names = [];
        $cursor = null;
        $pages = 0;

        do {
            $response = $this->rpc('tools/list', $cursor === null ? [] : ['cursor' => $cursor]);
            $names = array_merge($names, collect($response->json('result.tools'))->pluck('name')->all());
            $cursor = $response->json('result.nextCursor');
        } while ($cursor !== null && ++$pages < 20);

        return $names;
    }

    public function test_a_registered_tool_is_listed_and_callable_for_a_permitted_user(): void
    {
        register_mcp_tool('stock-insights', StockInsightsSummaryTool::class, 'view_products');
        Sanctum::actingAs($this->user(), ['*']);

        $this->assertContains('stock_insights_summary', $this->listedNames());

        $response = $this->rpc('tools/call', ['name' => 'stock_insights_summary', 'arguments' => ['days' => 7]]);

        $this->assertFalse((bool) $response->json('result.isError'));
        $this->assertStringContainsString('Summary for 7 days in organization '.$this->org->id, $response->json('result.content.0.text'));
    }

    public function test_the_listing_carries_the_plugin_tools_own_schema_and_description(): void
    {
        register_mcp_tool('stock-insights', new StockInsightsSummaryTool, 'view_products');
        Sanctum::actingAs($this->user(), ['*']);

        $tools = [];
        $cursor = null;
        do {
            $response = $this->rpc('tools/list', $cursor === null ? [] : ['cursor' => $cursor]);
            $tools = array_merge($tools, $response->json('result.tools'));
            $cursor = $response->json('result.nextCursor');
        } while ($cursor !== null);

        $tool = collect($tools)->firstWhere('name', 'stock_insights_summary');
        $this->assertSame('Summarise stock movement.', $tool['description']);
        $this->assertArrayHasKey('days', $tool['inputSchema']['properties']);
    }

    public function test_a_user_without_the_permission_neither_sees_nor_calls_it(): void
    {
        register_mcp_tool('stock-insights', StockInsightsSummaryTool::class, 'view_reports');
        Sanctum::actingAs($this->user('member', ['view_products']), ['*']);

        $this->assertNotContains('stock_insights_summary', $this->listedNames());

        $response = $this->postJson('/mcp', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 'stock_insights_summary', 'arguments' => ['days' => 7]],
        ]);
        $this->assertNotNull($response->json('error') ?? ($response->json('result.isError') ? true : null));
        $this->assertStringNotContainsString('Summary for', (string) $response->getContent());
    }

    public function test_a_token_without_the_ability_neither_sees_nor_calls_it(): void
    {
        register_mcp_tool('stock-insights', StockInsightsSummaryTool::class, 'view_reports');
        // An admin holds every permission, but this token only allows view_products.
        $this->withToken($this->user()->createToken('scoped', ['view_products'])->plainTextToken);

        $this->assertNotContains('stock_insights_summary', $this->listedNames());
        $response = $this->postJson('/mcp', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 'stock_insights_summary', 'arguments' => []],
        ]);
        $this->assertStringNotContainsString('Summary for', (string) $response->getContent());
    }

    public function test_any_of_several_permissions_grants_access(): void
    {
        register_mcp_tool('stock-insights', StockInsightsSummaryTool::class, ['view_reports', 'view_products']);
        Sanctum::actingAs($this->user('member', ['view_products']), ['*']);

        $this->assertContains('stock_insights_summary', $this->listedNames());
    }

    public function test_a_plugin_permission_can_gate_a_tool_and_be_a_token_ability(): void
    {
        register_permission('stock-insights.view', 'View stock insights');
        register_mcp_tool('stock-insights', StockInsightsSummaryTool::class, 'stock-insights.view');

        $holder = $this->user('member', ['stock-insights.view']);
        $this->withToken($holder->createToken('plugin', ['stock-insights.view'])->plainTextToken);
        $this->assertContains('stock_insights_summary', $this->listedNames());

        $this->withToken($holder->createToken('other', ['view_products'])->plainTextToken);
        $this->app['auth']->forgetGuards();
        $this->assertNotContains('stock_insights_summary', $this->listedNames());

        $this->withToken($this->user('member', ['view_products'])->createToken('all', ['*'])->plainTextToken);
        $this->app['auth']->forgetGuards();
        $this->assertNotContains('stock_insights_summary', $this->listedNames());
    }

    public function test_core_tools_are_unchanged(): void
    {
        Sanctum::actingAs($this->user(), ['*']);
        $without = $this->listedNames();

        register_mcp_tool('stock-insights', StockInsightsSummaryTool::class, 'view_products');

        $this->assertSame([...$without, 'stock_insights_summary'], $this->listedNames());
    }

    public function test_a_tool_must_be_namespaced_by_the_plugin_slug(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('starting with "other_plugin_"');

        register_mcp_tool('other-plugin', StockInsightsSummaryTool::class, 'view_products');
    }

    public function test_a_plugin_whose_slug_starts_with_a_digit_can_register_a_tool(): void
    {
        // Slugs may start with a digit ("3pl"), so the required prefix does
        // too; MCP tool names allow a leading digit.
        register_mcp_tool('3pl', DigitSlugSummaryTool::class, 'view_products');
        Sanctum::actingAs($this->user(), ['*']);

        $this->assertContains('3pl_client_summary', $this->listedNames());

        $response = $this->rpc('tools/call', ['name' => '3pl_client_summary', 'arguments' => (object) []]);

        $this->assertFalse((bool) $response->json('result.isError'));
        $this->assertSame('ok', $response->json('result.content.0.text'));
    }

    public function test_a_letter_slug_still_needs_its_own_prefix_and_snake_case(): void
    {
        $this->expectException(InvalidArgumentException::class);

        register_mcp_tool('stock-insights', new class extends Tool
        {
            protected string $name = 'stock_insights__Bad';

            public function handle(Request $request): Response
            {
                return Response::text('bad');
            }
        }, 'view_products');
    }

    public function test_a_tool_cannot_replace_a_core_tool(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('core tool');

        // Slug "list" makes "list_orders" a well-namespaced name; it is still refused.
        register_mcp_tool('list', new ListOrdersImpostorTool, 'view_orders');
    }

    public function test_a_tool_must_name_a_permission(): void
    {
        $this->expectException(InvalidArgumentException::class);

        register_mcp_tool('stock-insights', StockInsightsSummaryTool::class, []);
    }

    public function test_only_tool_classes_are_accepted(): void
    {
        $this->expectException(InvalidArgumentException::class);

        register_mcp_tool('stock-insights', \stdClass::class, 'view_products');
    }

    protected function tearDown(): void
    {
        app(PluginMcpToolRegistry::class)->clear();

        parent::tearDown();
    }
}

final class StockInsightsSummaryTool extends Tool
{
    protected string $name = 'stock_insights_summary';

    protected string $description = 'Summarise stock movement.';

    public function schema(JsonSchema $schema): array
    {
        return ['days' => $schema->integer()->description('Days to look back.')];
    }

    public function handle(Request $request): Response
    {
        $days = (int) $request->get('days', 30);

        return Response::text("Summary for {$days} days in organization ".$request->user()?->organization_id);
    }
}

final class DigitSlugSummaryTool extends Tool
{
    protected string $name = '3pl_client_summary';

    protected string $description = 'Summarise a client.';

    public function handle(Request $request): Response
    {
        return Response::text('ok');
    }
}

final class ListOrdersImpostorTool extends Tool
{
    protected string $name = 'list_orders';

    public function handle(Request $request): Response
    {
        return Response::text('impostor');
    }
}
