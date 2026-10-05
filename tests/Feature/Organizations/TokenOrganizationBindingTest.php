<?php

declare(strict_types=1);

namespace Tests\Feature\Organizations;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Services\Organizations\ActiveOrganization;
use App\Services\Organizations\OrganizationMembershipService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Organizations\Concerns\BuildsOrganizations;
use Tests\TestCase;

/**
 * API tokens work in exactly one organization (the one active when they were
 * created) on every token surface: REST, GraphQL and MCP. The browser
 * session's organization never leaks into a token request, and a token of a
 * withdrawn membership stops authenticating.
 */
final class TokenOrganizationBindingTest extends TestCase
{
    use BuildsOrganizations, RefreshDatabase;

    private Organization $alpha;

    private Organization $beta;

    private User $user;

    private Product $alphaProduct;

    private Product $betaProduct;

    protected function setUp(): void
    {
        parent::setUp();
        $this->markInstalled();

        $this->alpha = $this->organization('Alpha');
        $this->beta = $this->organization('Beta');
        $this->user = $this->homeUser($this->alpha, 'admin');
        $this->homeUser($this->beta, 'admin', 'Owner');
        $this->addMember($this->beta, $this->user, 'admin');

        $this->alphaProduct = $this->product($this->alpha, 'ALPHA-1');
        $this->betaProduct = $this->product($this->beta, 'BETA-1');
    }

    /** A token minted while the user works in $organization. */
    private function tokenFor(Organization $organization, array $abilities = ['*']): string
    {
        return app(ActiveOrganization::class)->userIn($this->user, $organization->id)
            ->createToken('t-'.$organization->name, $abilities)->plainTextToken;
    }

    /** A request with only the token: a fresh API client. */
    private function api(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    private function mcp(string $token, string $tool, array $arguments = []): TestResponse
    {
        return $this->api($token)->postJson('/mcp', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => $tool, 'arguments' => $arguments === [] ? (object) [] : $arguments],
        ]);
    }

    public function test_a_token_is_bound_to_the_organization_it_was_created_in(): void
    {
        $this->tokenFor($this->beta);
        $this->user->createToken('home');

        $this->assertSame(
            [$this->beta->id, $this->alpha->id],
            PersonalAccessToken::orderBy('id')->pluck('organization_id')->map(fn ($id) => (int) $id)->all(),
        );
    }

    public function test_rest_requests_work_in_the_token_organization_only(): void
    {
        $beta = $this->tokenFor($this->beta);

        $skus = collect($this->api($beta)->getJson('/api/v1/products')->assertOk()->json('data'))->pluck('sku')->all();
        $this->assertSame(['BETA-1'], $skus);

        $this->api($beta)->getJson('/api/v1/products/'.$this->alphaProduct->id)->assertNotFound();
        $this->api($beta)->putJson('/api/v1/products/'.$this->alphaProduct->id, ['name' => 'Hijacked'])->assertNotFound();
        $this->api($beta)->deleteJson('/api/v1/products/'.$this->alphaProduct->id)->assertNotFound();
        $this->assertDatabaseHas('products', ['id' => $this->alphaProduct->id, 'name' => 'Product ALPHA-1', 'deleted_at' => null]);

        $this->api($beta)->postJson('/api/v1/products', ['sku' => 'API-1', 'name' => 'Via API', 'price' => 1])->assertCreated();
        $this->assertDatabaseHas('products', ['sku' => 'API-1', 'organization_id' => $this->beta->id]);

        $this->api($beta)->getJson('/api/v1/user')
            ->assertOk()
            ->assertJsonPath('organization_id', $this->beta->id)
            ->assertJsonPath('organization.id', $this->beta->id)
            ->assertJsonPath('organizations.0.id', $this->alpha->id)
            ->assertJsonPath('organizations.1.id', $this->beta->id);

        // The home token still works at home.
        $alpha = $this->tokenFor($this->alpha);
        $skus = collect($this->api($alpha)->getJson('/api/v1/products')->assertOk()->json('data'))->pluck('sku')->all();
        $this->assertSame(['ALPHA-1'], $skus);
    }

    public function test_the_browser_session_organization_does_not_reach_token_requests(): void
    {
        $alpha = $this->tokenFor($this->alpha);

        // The same user switched the browser to Beta.
        $this->actingAs($this->user)->post(route('organizations.switch'), ['organization_id' => $this->beta->id])->assertRedirect();

        $skus = collect($this->api($alpha)->getJson('/api/v1/products')->assertOk()->json('data'))->pluck('sku')->all();
        $this->assertSame(['ALPHA-1'], $skus);
    }

    public function test_permissions_are_those_held_in_the_token_organization(): void
    {
        $gamma = $this->organization('Gamma');
        $this->addMember($gamma, $this->user, 'member');
        $this->grantInOrganization($this->user, $gamma, ['view_products']);
        $gammaProduct = $this->product($gamma, 'GAMMA-1');
        $token = $this->tokenFor($gamma);

        $this->api($token)->getJson('/api/v1/products')->assertOk();
        $this->api($token)->deleteJson('/api/v1/products/'.$gammaProduct->id)->assertForbidden();
        $this->api($token)->getJson('/api/v1/users')->assertForbidden();
        $this->assertDatabaseHas('products', ['id' => $gammaProduct->id, 'deleted_at' => null]);
    }

    public function test_a_token_of_a_withdrawn_membership_stops_authenticating(): void
    {
        $beta = $this->tokenFor($this->beta);
        $this->api($beta)->getJson('/api/v1/user')->assertOk();

        // Withdrawn behind the service's back: the token row survives, but is refused.
        DB::table('organization_user')->where('organization_id', $this->beta->id)->where('user_id', $this->user->id)->delete();
        $this->api($beta)->getJson('/api/v1/user')->assertUnauthorized();
        $this->api($beta)->getJson('/api/v1/products')->assertUnauthorized();

        // Withdrawn through the service: the tokens are deleted as well.
        $other = $this->tokenFor($this->alpha);
        $this->addMember($this->beta, $this->user, 'admin');
        $again = $this->tokenFor($this->beta);
        app(OrganizationMembershipService::class)->remove($this->beta, $this->user);
        $this->api($again)->getJson('/api/v1/user')->assertUnauthorized();
        $this->api($other)->getJson('/api/v1/user')->assertOk();
    }

    public function test_a_token_of_a_disabled_organization_stops_authenticating(): void
    {
        $beta = $this->tokenFor($this->beta);
        $this->beta->update(['is_active' => false]);

        $this->api($beta)->getJson('/api/v1/user')->assertUnauthorized();
    }

    public function test_a_legacy_token_without_an_organization_works_in_the_home_organization(): void
    {
        $token = $this->user->createToken('legacy');
        DB::table('personal_access_tokens')->where('id', $token->accessToken->id)->update(['organization_id' => null]);

        $skus = collect($this->api($token->plainTextToken)->getJson('/api/v1/products')->assertOk()->json('data'))->pluck('sku')->all();
        $this->assertSame(['ALPHA-1'], $skus);
    }

    public function test_signing_in_over_the_api_can_bind_the_token_to_a_membership(): void
    {
        $response = $this->postJson('/api/v1/login', [
            'email' => $this->user->email, 'password' => 'password', 'organization_id' => $this->beta->id,
        ])->assertOk()->assertJsonPath('user.organization_id', $this->beta->id);

        $skus = collect($this->api($response->json('token'))->getJson('/api/v1/products')->assertOk()->json('data'))->pluck('sku')->all();
        $this->assertSame(['BETA-1'], $skus);

        $home = $this->postJson('/api/v1/login', ['email' => $this->user->email, 'password' => 'password'])
            ->assertOk()->assertJsonPath('user.organization_id', $this->alpha->id);
        $this->assertSame(
            $this->alpha->id,
            (int) PersonalAccessToken::findToken($home->json('token'))->organization_id,
        );
    }

    public function test_signing_in_over_the_api_to_a_foreign_organization_is_refused(): void
    {
        $gamma = $this->organization('Gamma');

        $this->postJson('/api/v1/login', [
            'email' => $this->user->email, 'password' => 'password', 'organization_id' => $gamma->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('organization_id');

        // A wrong password never reveals membership.
        $this->postJson('/api/v1/login', [
            'email' => $this->user->email, 'password' => 'wrong', 'organization_id' => $this->beta->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('email')->assertJsonMissingValidationErrors('organization_id');

        $this->assertSame(0, PersonalAccessToken::count());
    }

    public function test_tokens_are_listed_and_revoked_per_organization(): void
    {
        $betaToken = $this->tokenFor($this->beta);
        $alphaToken = app(ActiveOrganization::class)->userIn($this->user, $this->alpha->id)->createToken('alpha-only');

        // Over the API, a Beta token cannot revoke an Alpha token.
        $this->api($betaToken)->deleteJson('/api/v1/tokens/'.$alphaToken->accessToken->id)->assertNotFound();
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $alphaToken->accessToken->id]);

        // On the settings page, each organization lists its own tokens.
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->user)
            ->get(route('settings.api-tokens.index'))
            ->assertInertia(fn ($page) => $page->has('tokens', 1)->where('tokens.0.name', 'alpha-only'));

        app(ActiveOrganization::class)->activate(auth()->user(), $this->beta->id);
        $this->delete(route('settings.api-tokens.destroy', $alphaToken->accessToken->id))->assertNotFound();
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $alphaToken->accessToken->id]);
    }

    public function test_tokens_minted_over_the_api_stay_in_the_calling_organization(): void
    {
        $beta = $this->tokenFor($this->beta);

        $minted = $this->api($beta)->postJson('/api/v1/tokens', ['name' => 'child'])->assertCreated()->json('token');

        $this->assertSame($this->beta->id, (int) PersonalAccessToken::findToken($minted)->organization_id);
    }

    public function test_graphql_works_in_the_token_organization_only(): void
    {
        $beta = $this->tokenFor($this->beta);

        $response = $this->api($beta)->postJson('/graphql', ['query' => '{ products { id sku } }'])->assertOk();
        $this->assertSame(['BETA-1'], collect($response->json('data.products'))->pluck('sku')->all());

        $foreign = $this->api($beta)->postJson('/graphql', ['query' => sprintf('{ product(id: %d) { id sku } }', $this->alphaProduct->id)]);
        $this->assertNull($foreign->json('data.product'));
        $this->assertStringNotContainsString('ALPHA-1', $foreign->getContent());

        $this->api($beta)->postJson('/graphql', ['query' => sprintf('mutation { updateProduct(id: %d, name: "Hijacked") { id } }', $this->alphaProduct->id)]);
        $this->assertDatabaseHas('products', ['id' => $this->alphaProduct->id, 'name' => 'Product ALPHA-1']);
    }

    public function test_mcp_works_in_the_token_organization_only(): void
    {
        $beta = $this->tokenFor($this->beta);

        $whoami = $this->mcp($beta, 'who_am_i')->assertOk();
        $this->assertSame($this->beta->id, json_decode($whoami->json('result.content.0.text'), true)['organization_id']);

        $listed = $this->mcp($beta, 'list_products')->assertOk()->json('result.content.0.text');
        $this->assertStringContainsString('BETA-1', $listed);
        $this->assertStringNotContainsString('ALPHA-1', $listed);

        $foreign = $this->mcp($beta, 'get_product', ['id' => $this->alphaProduct->id]);
        $this->assertStringNotContainsString('ALPHA-1', (string) $foreign->getContent());

        $this->mcp($beta, 'adjust_stock', ['product_id' => $this->alphaProduct->id, 'quantity' => -5, 'type' => 'manual', 'reason' => 'x']);
        $this->assertSame(50, $this->alphaProduct->fresh()->stock);
    }
}
