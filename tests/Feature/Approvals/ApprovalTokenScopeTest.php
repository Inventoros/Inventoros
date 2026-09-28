<?php

declare(strict_types=1);

namespace Tests\Feature\Approvals;

use App\Mcp\Servers\InventorosServer;
use App\Mcp\Tools\ListPendingApprovalsTool;
use App\Models\Inventory\StockAdjustmentRequest;
use App\Models\User;
use App\Services\ApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * A scoped personal access token must limit approvals on every surface, not
 * just REST: GraphQL decided and requested approvals on the user's role alone,
 * and the approval listings (REST, GraphQL, MCP) ignored the token entirely.
 */
class ApprovalTokenScopeTest extends TestCase
{
    use ApprovalFixtures, RefreshDatabase;

    private StockAdjustmentRequest $adjustment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpApprovalWorld();
        $this->enableApprovals(['stock_adjustments_enabled' => true]);

        $this->adjustment = app(ApprovalService::class)->requestStockAdjustment(
            $this->requester, $this->product, null, -5, 'damage', 'Dropped', null, null,
        );
    }

    /**
     * @param  array<int, string>  $abilities
     */
    private function tokenFor(User $user, array $abilities): string
    {
        return $user->createToken('scoped', $abilities)->plainTextToken;
    }

    private function graphql(string $token, string $query): \Illuminate\Testing\TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token)->postJson('/graphql', ['query' => $query]);
    }

    private function api(string $token, string $uri): \Illuminate\Testing\TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token)->getJson($uri);
    }

    private function decide(string $token): \Illuminate\Testing\TestResponse
    {
        return $this->graphql($token, "mutation { decideApproval(type: \"stock_adjustment\", id: {$this->adjustment->id}, decision: \"approve\") { status } }");
    }

    public function test_graphql_decide_refuses_a_token_without_any_approve_ability(): void
    {
        $response = $this->decide($this->tokenFor($this->approver, ['view_products']));

        $this->assertNotNull($response->json('errors'));
        $this->assertSame('pending', $this->adjustment->fresh()->status);
        $this->assertSame(100, $this->product->fresh()->stock);
    }

    public function test_graphql_decide_needs_the_ability_for_that_type(): void
    {
        $response = $this->decide($this->tokenFor($this->approver, ['approve_purchase_orders']));

        $this->assertNotNull($response->json('errors'));
        $this->assertSame('pending', $this->adjustment->fresh()->status);
    }

    public function test_graphql_decide_works_with_the_matching_ability(): void
    {
        $this->decide($this->tokenFor($this->approver, ['approve_stock_adjustments']))
            ->assertJsonPath('data.decideApproval.status', 'approved');
    }

    public function test_graphql_request_adjustment_approval_needs_manage_stock_on_the_token(): void
    {
        $query = sprintf('mutation { requestStockAdjustmentApproval(product_id: %d, quantity: -3, type: "damage") { status } }', $this->product->id);

        $this->assertNotNull($this->graphql($this->tokenFor($this->requester, ['view_products']), $query)->json('errors'));
        $this->assertSame(1, StockAdjustmentRequest::count());

        $this->graphql($this->tokenFor($this->requester, ['manage_stock']), $query)
            ->assertJsonPath('data.requestStockAdjustmentApproval.status', 'pending');
    }

    public function test_rest_listing_only_shows_types_the_token_may_decide(): void
    {
        $this->api($this->tokenFor($this->approver, ['view_products']), '/api/v1/approvals')
            ->assertOk()->assertJsonCount(0, 'data');

        $this->api($this->tokenFor($this->approver, ['approve_stock_adjustments']), '/api/v1/approvals')
            ->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_rest_own_requests_are_hidden_from_an_unrelated_token(): void
    {
        $this->api($this->tokenFor($this->requester, ['view_products']), '/api/v1/approvals/mine')
            ->assertOk()->assertJsonCount(0, 'data');

        $this->api($this->tokenFor($this->requester, ['manage_stock']), '/api/v1/approvals/mine')
            ->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_graphql_pending_approvals_respects_the_token(): void
    {
        $this->graphql($this->tokenFor($this->approver, ['view_products']), '{ pendingApprovals { id } }')
            ->assertJsonCount(0, 'data.pendingApprovals');

        $this->graphql($this->tokenFor($this->approver, ['approve_stock_adjustments']), '{ pendingApprovals { id } }')
            ->assertJsonCount(1, 'data.pendingApprovals');
    }

    public function test_mcp_pending_approvals_respects_the_token(): void
    {
        $plain = $this->tokenFor($this->approver, ['view_products']);
        $this->approver->withAccessToken(PersonalAccessToken::findToken($plain));
        $this->app['auth']->forgetGuards();

        InventorosServer::actingAs($this->approver, 'sanctum')
            ->tool(ListPendingApprovalsTool::class, [])
            ->assertOk()
            ->assertSee('"count":0');
    }
}
