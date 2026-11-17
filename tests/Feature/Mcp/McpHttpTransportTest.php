<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Models\Auth\Organization;
use App\Models\Role;
use App\Models\System\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The /mcp endpoint over real HTTP, as MCP clients reach it: clients on the
 * current protocol (2026-07-28, server/discover, per-request metadata and
 * mirrored headers) and clients that still open with `initialize`.
 */
class McpHttpTransportTest extends TestCase
{
    use RefreshDatabase;

    private const MODERN = '2026-07-28';

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        SystemSetting::set('installed', true, 'boolean');

        $organization = Organization::create([
            'name' => 'Transport Org',
            'email' => 'transport@org.test',
            'currency' => 'USD',
            'timezone' => 'UTC',
        ]);

        $role = Role::firstOrCreate(
            ['slug' => 'mcp-transport-viewer'],
            ['name' => 'Viewer', 'is_system' => false, 'permissions' => ['view_products']]
        );

        $user = User::create([
            'name' => 'Agent',
            'email' => 'agent@transport.test',
            'password' => bcrypt('password'),
            'organization_id' => $organization->id,
            'role' => 'member',
        ]);
        $user->roles()->syncWithoutDetaching([$role->id]);

        $this->token = $user->createToken('mcp')->plainTextToken;
    }

    public function test_legacy_client_can_initialize_and_list_tools(): void
    {
        $this->withToken($this->token)
            ->postJson('/mcp', [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'initialize',
                'params' => [
                    'protocolVersion' => '2025-11-25',
                    'capabilities' => [],
                    'clientInfo' => ['name' => 'legacy-client', 'version' => '1.0.0'],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('result.protocolVersion', '2025-11-25')
            ->assertJsonPath('result.serverInfo.name', 'Inventoros MCP');

        $tools = $this->withToken($this->token)
            ->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'], [
                'MCP-Protocol-Version' => '2025-11-25',
            ])
            ->assertOk()
            ->json('result.tools');

        $this->assertContains('who_am_i', array_column($tools, 'name'));
    }

    public function test_current_protocol_client_can_discover_and_call_a_tool(): void
    {
        $this->withToken($this->token)
            ->postJson('/mcp', $this->modern(1, 'server/discover'), $this->headers('server/discover'))
            ->assertOk()
            ->assertJsonPath('result.supportedVersions', fn (array $versions) => in_array(self::MODERN, $versions, true));

        $response = $this->withToken($this->token)
            ->postJson('/mcp', $this->modern(2, 'tools/call', ['name' => 'who_am_i', 'arguments' => []]), $this->headers('tools/call', 'who_am_i'))
            ->assertOk();

        $this->assertNotTrue($response->json('result.isError'));
        $this->assertStringContainsString('agent@transport.test', json_encode($response->json('result')));
    }

    public function test_current_protocol_request_with_mismatched_headers_is_rejected(): void
    {
        $this->withToken($this->token)
            ->postJson('/mcp', $this->modern(1, 'tools/call', ['name' => 'who_am_i', 'arguments' => []]), $this->headers('tools/call', 'list_products'))
            ->assertStatus(400)
            ->assertJsonPath('error.code', -32020);
    }

    public function test_requests_from_a_foreign_browser_origin_are_refused(): void
    {
        $this->withToken($this->token)
            ->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], ['Origin' => 'https://evil.example'])
            ->assertForbidden();
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function modern(int $id, string $method, array $params = []): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'method' => $method,
            'params' => [
                ...$params,
                '_meta' => [
                    'io.modelcontextprotocol/protocolVersion' => self::MODERN,
                    'io.modelcontextprotocol/clientCapabilities' => (object) [],
                ],
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function headers(string $method, ?string $name = null): array
    {
        return array_filter([
            'MCP-Protocol-Version' => self::MODERN,
            'Mcp-Method' => $method,
            'Mcp-Name' => $name,
        ]);
    }
}
