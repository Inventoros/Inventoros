<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\InventorosServer;
use App\Models\Auth\Organization;
use App\Models\System\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The tool names documented in docs/site/sections/mcp-server.md are the
 * contract AI clients are configured against. Every registered tool must
 * carry exactly a documented name, and every documented tool must exist.
 */
class McpToolNamesMatchDocsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, string> class => tool name
     */
    private function registeredToolNames(): array
    {
        $property = new \ReflectionProperty(InventorosServer::class, 'tools');
        $server = (new \ReflectionClass(InventorosServer::class))->newInstanceWithoutConstructor();

        $names = [];
        foreach ($property->getValue($server) as $class) {
            $names[$class] = app($class)->name();
        }

        return $names;
    }

    private function catalog(): string
    {
        $markdown = (string) file_get_contents(base_path('docs/site/sections/mcp-server.md'));

        $this->assertMatchesRegularExpression('/^### Tool catalog\s*$/m', $markdown, 'mcp-server.md needs a "### Tool catalog" section.');

        $section = preg_split('/^### Tool catalog\s*$/m', $markdown)[1];

        return preg_split('/^### /m', $section)[0];
    }

    /**
     * @return array<int, string>
     */
    private function documentedToolNames(): array
    {
        preg_match_all('/^- `([a-z0-9_]+)`/m', $this->catalog(), $matches);

        return $matches[1];
    }

    public function test_every_registered_tool_has_an_explicit_snake_case_name(): void
    {
        foreach ($this->registeredToolNames() as $class => $name) {
            $this->assertMatchesRegularExpression(
                '/^[a-z][a-z0-9]*(_[a-z0-9]+)*$/',
                $name,
                "{$class} is exposed as \"{$name}\"; give it an explicit snake_case \$name."
            );
        }
    }

    public function test_registered_tools_and_documented_tools_are_the_same_set(): void
    {
        $registered = array_values($this->registeredToolNames());
        $documented = $this->documentedToolNames();

        $this->assertSame([], array_values(array_diff($registered, $documented)), 'Registered tools missing from mcp-server.md');
        $this->assertSame([], array_values(array_diff($documented, $registered)), 'Documented tools that are not registered');
        $this->assertSame(count($registered), count(array_unique($registered)), 'Duplicate registered tool names');
        $this->assertSame(count($documented), count(array_unique($documented)), 'A tool is documented twice');
    }

    public function test_prompts_carry_their_documented_names(): void
    {
        $markdown = (string) file_get_contents(base_path('docs/site/sections/mcp-server.md'));
        $section = preg_split('/^### /m', preg_split('/^### Prompts\s*$/m', $markdown)[1])[0];
        preg_match_all('/^- `([a-z0-9_]+)`/m', $section, $matches);

        $property = new \ReflectionProperty(InventorosServer::class, 'prompts');
        $server = (new \ReflectionClass(InventorosServer::class))->newInstanceWithoutConstructor();
        $registered = array_map(fn (string $class) => app($class)->name(), $property->getValue($server));

        sort($registered);
        $documented = $matches[1];
        sort($documented);

        $this->assertSame($documented, $registered);
    }

    public function test_the_stated_tool_count_matches(): void
    {
        preg_match('/(\d+) tools across/', $this->catalog(), $m);

        $this->assertSame(count($this->registeredToolNames()), (int) ($m[1] ?? 0));
    }

    /**
     * docs/mcp/README.md is the developer-facing copy of the catalog; it
     * drifted to 24 of the 30 tools once, so hold it to the same contract.
     *
     * @return array{0: string, 1: array<int, string>}
     */
    private function developerReadmeCatalog(): array
    {
        $markdown = (string) file_get_contents(base_path('docs/mcp/README.md'));

        $this->assertMatchesRegularExpression('/^## Tool catalog\s*$/m', $markdown, 'docs/mcp/README.md needs a "## Tool catalog" section.');

        $section = preg_split('/^## /m', preg_split('/^## Tool catalog\s*$/m', $markdown)[1])[0];
        preg_match_all('/^\| `([a-z0-9_]+)` \|/m', $section, $matches);

        return [$section, $matches[1]];
    }

    public function test_the_developer_readme_lists_exactly_the_registered_tools(): void
    {
        [, $documented] = $this->developerReadmeCatalog();
        $registered = array_values($this->registeredToolNames());

        $this->assertSame([], array_values(array_diff($registered, $documented)), 'Registered tools missing from docs/mcp/README.md');
        $this->assertSame([], array_values(array_diff($documented, $registered)), 'docs/mcp/README.md documents tools that are not registered');
        $this->assertSame(count($documented), count(array_unique($documented)), 'A tool is documented twice in docs/mcp/README.md');
    }

    public function test_the_developer_readme_states_the_tool_count(): void
    {
        [$section] = $this->developerReadmeCatalog();

        preg_match('/(\d+) tools across/', $section, $m);

        $this->assertSame(count($this->registeredToolNames()), (int) ($m[1] ?? 0));
    }

    public function test_tools_list_over_http_returns_exactly_the_documented_names(): void
    {
        SystemSetting::set('installed', true, 'boolean');
        $org = Organization::create(['name' => 'Docs Org', 'email' => 'd@o.test', 'currency' => 'USD', 'timezone' => 'UTC']);
        $admin = User::create([
            'name' => 'Admin', 'email' => 'docs-admin@o.test', 'password' => bcrypt('x'),
            'organization_id' => $org->id, 'role' => 'admin',
        ]);
        Sanctum::actingAs($admin, ['*']);

        // tools/list is paginated; follow nextCursor to the end.
        $listed = [];
        $cursor = null;
        $pages = 0;
        do {
            $response = $this->postJson('/mcp', [
                'jsonrpc' => '2.0', 'id' => ++$pages, 'method' => 'tools/list',
                'params' => $cursor === null ? (object) [] : ['cursor' => $cursor],
            ])->assertOk();

            $listed = array_merge($listed, collect($response->json('result.tools'))->pluck('name')->all());
            $cursor = $response->json('result.nextCursor');
        } while ($cursor !== null && $pages < 20);

        $listed = collect($listed)->sort()->values()->all();
        $documented = collect($this->documentedToolNames())->sort()->values()->all();

        $this->assertSame($documented, $listed);
    }
}
